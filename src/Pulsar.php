<?php

/**
 * PulsarX — a requests-style HTTP client for PHP, built on cURL.
 *
 *   - Session model: cookies persist in memory across requests (no files).
 *   - impersonate(): browser fingerprint mimicry (User-Agent, header order,
 *     TLS cipher list, curves, HTTP/2, Brotli/ZSTD).
 *   - async: parallel requests over curl_multi with a rolling concurrency window.
 *   - Mime: multipart/form-data + file uploads.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Pulsar extends Helper
{
    private array $default = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 20,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,   // secure by default — disable with new Pulsar(verify: false)
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    private CurlHandle $ch;
    private object $callback;

    private array|false $info;

    private CookieJarInterface $cookieJar;
    private string $userAgent = 'PulsarX/1.1 (+https://github.com/Vxsilisk/PulsarX) requests-style cURL client';
    private ?Profile $profile = null;

    /** @var string[] rotation pool; non-empty enables per-request rotation */
    private array $rotatePool = [];
    private bool $stealth = false;

    // Retry policy
    private int $retryTimes = 0;
    private float $retryDelay = 0.5;
    /** @var int[] HTTP status codes that trigger a retry */
    private array $retryOn = [429, 500, 502, 503, 504];

    private bool $throwOnError = false;

    private int $error_code = 0;
    private string $error_string = '';

    private bool|string $body = false;
    private string $currentUrl = '';
    private string $lastUrl = '';

    public function __construct(array $config = [], bool $verify = true)
    {
        $this->default = array_replace($this->default, $config);

        if (!$verify) {
            $this->default[CURLOPT_SSL_VERIFYPEER] = false;
            $this->default[CURLOPT_SSL_VERIFYHOST] = false;
        }

        $this->cookieJar = new CookieJar();
    }

    /* ----------------------------------------------------------------------
     * Request policy (chainable, session-wide)
     * -------------------------------------------------------------------- */

    /**
     * Retry failed requests with exponential backoff + jitter. Retries on transport
     * errors and on the given HTTP status codes.
     *
     * @param int        $times     max retries (0 disables)
     * @param float      $baseDelay seconds; each attempt waits baseDelay * 2^n (± jitter)
     * @param int[]|null $on        HTTP statuses to retry (default 429/500/502/503/504)
     */
    public function retries(int $times = 3, float $baseDelay = 0.5, ?array $on = null): static
    {
        $this->retryTimes = max(0, $times);
        $this->retryDelay = max(0.0, $baseDelay);
        if ($on !== null) {
            $this->retryOn = $on;
        }
        return $this;
    }

    /** Control redirect following for the session. */
    public function redirects(bool $follow = true, int $max = 20): static
    {
        $this->default[CURLOPT_FOLLOWLOCATION] = $follow;
        $this->default[CURLOPT_MAXREDIRS] = $max;
        return $this;
    }

    /** Throw a PulsarException on transport failure or any 4xx/5xx (sync requests only). */
    public function throwOnError(bool $on = true): static
    {
        $this->throwOnError = $on;
        return $this;
    }

    /* ----------------------------------------------------------------------
     * Impersonation
     * -------------------------------------------------------------------- */

    /**
     * Mimic a browser fingerprint. Chainable: $s->impersonate('chrome')->get($url).
     *
     * Pass 'random' to pick one coherent profile for the whole session.
     *
     * @param string|Profile $browser a target name (see impersonateTargets()), 'random', or a Profile
     */
    public function impersonate(string|Profile $browser): static
    {
        if ($browser === 'random') {
            $this->profile = Profile::random();
        } else {
            $this->profile = $browser instanceof Profile ? $browser : Profile::resolve($browser);
        }
        $this->rotatePool = [];
        return $this;
    }

    /**
     * Rotate a fresh, coherent browser profile on *every* request — real traffic is a
     * mix of browsers, so a single static fingerprint is itself a tell.
     *
     * @param string[]|null $targets pool of target names (defaults to Profile::realisticPool())
     */
    public function rotate(?array $targets = null): static
    {
        $this->rotatePool = $targets ?: Profile::realisticPool();
        $this->profile = null;
        return $this;
    }

    /**
     * Enable behavioural coherence: a real browser computes Sec-Fetch-Site
     * (none / same-origin / same-site / cross-site) from where it came, and sends a
     * Referer that honours Referrer-Policy (origin-only when cross-site). Naive
     * scrapers send a fixed `Sec-Fetch-Site: none` with no Referer — an easy tell.
     */
    public function stealth(bool $on = true): static
    {
        $this->stealth = $on;
        return $this;
    }

    public function clearImpersonation(): static
    {
        $this->profile = null;
        $this->rotatePool = [];
        return $this;
    }

    /** @return string[] every impersonation target Pulsar knows about. */
    public static function impersonateTargets(): array
    {
        return Profile::available();
    }

    /** Resolve the profile to use for the current request (fixed or rotated). */
    private function effectiveProfile(): ?Profile
    {
        if ($this->rotatePool !== []) {
            return Profile::random($this->rotatePool);
        }
        return $this->profile;
    }

    /* ----------------------------------------------------------------------
     * Cookies
     * -------------------------------------------------------------------- */

    private function setCookie(CookieJarInterface|string $cookie): void
    {
        if ($cookie instanceof CookieJarInterface) {
            $this->cookieJar = $cookie;
        } elseif ($cookie !== '') {
            $this->cookieJar->clear()->merge($cookie);
        }
    }

    public function getCookieJar(): CookieJarInterface
    {
        return $this->cookieJar;
    }

    public function deleteCookie(): void
    {
        $this->cookieJar->clear();
    }

    /* ----------------------------------------------------------------------
     * Handle construction (shared by sync + async)
     * -------------------------------------------------------------------- */

    private function dataType($data): false|string
    {
        return match (gettype($data)) {
            'string'          => $data,
            'array', 'object' => json_encode($data),
            default           => false
        };
    }

    /** Append a query string built from $params, preserving any existing query. */
    private function applyParams(string $url, ?array $params): string
    {
        if (empty($params)) {
            return $url;
        }

        $query = http_build_query($params);
        if ($query === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    /** Should this response be retried? (transport error or a retryable status, attempts left) */
    private function shouldRetry(Response $response, int $attempt): bool
    {
        if ($attempt >= $this->retryTimes) {
            return false;
        }

        return !$response->isSuccess() || in_array($response->getStatusCode(), $this->retryOn, true);
    }

    /** Exponential backoff with ±25% jitter, in microseconds-sleep. */
    private function backoff(int $attempt): void
    {
        $delay = $this->retryDelay * (2 ** $attempt);
        $jitter = $delay * 0.25 * (mt_rand(-1000, 1000) / 1000);
        $seconds = max(0.0, $delay + $jitter);

        if ($seconds > 0) {
            usleep((int)($seconds * 1_000_000));
        }
    }

    /**
     * Resolve a request body into cURL POSTFIELDS plus any forced headers.
     *
     * @return array{0: string|array|null, 1: array<int,string>}
     */
    private function resolveBody(string|array|Mime|null $data, mixed $json): array
    {
        if ($json !== null) {
            return [json_encode($json), ['Content-Type: application/json']];
        }

        if ($data instanceof Mime) {
            return [$data->toPostFields(), []]; // array -> cURL builds multipart/form-data
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if ($value instanceof CURLFile || $value instanceof CURLStringFile) {
                    return [$data, []]; // contains a file -> multipart
                }
            }
            return [json_encode($data), []]; // plain array -> JSON (legacy behaviour)
        }

        if (is_string($data)) {
            return [$data, []];
        }

        return [null, []];
    }

    /** Append forced header lines unless the caller already set that header. */
    private function mergeHeaderList(?array $headers, array $extra): array
    {
        $headers = $headers ?? [];

        foreach ($extra as $line) {
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            $present = false;
            foreach ($headers as $existing) {
                if (is_string($existing) && strtolower(trim(explode(':', $existing, 2)[0])) === $name) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $headers[] = $line;
            }
        }

        return $headers;
    }

    /**
     * Build a fully-configured CurlHandle for a request without executing it.
     *
     * @return array{0: CurlHandle, 1: object}  [handle, headerBuffer]
     */
    private function makeHandle(
        string $method,
        string $url,
        string|array|Mime|null $data,
        mixed $json,
        ?array $headers,
        string|CookieJarInterface|null $cookie,
        ?array $server,
        ?array $params = null,
        ?int $timeout = null
    ): array {
        if ($cookie !== null) {
            $this->setCookie($cookie);
        }

        $url = $this->applyParams($url, $params);
        $ch  = curl_init($url);
        $opt = $this->default;

        if ($timeout !== null) {
            $opt[CURLOPT_TIMEOUT] = $timeout;
        }

        // Body: JSON param, Mime multipart, raw array (-> JSON) or string
        [$postfields, $extraHeaders] = $this->resolveBody($data, $json);
        $headers = $this->mergeHeaderList($headers, $extraHeaders);

        // Method
        $method = strtoupper($method);
        if ($method === 'POST') {
            $opt[CURLOPT_POST] = true;
            if ($postfields !== null) {
                $opt[CURLOPT_POSTFIELDS] = $postfields;
            }
        } elseif ($method !== 'GET') {
            $opt[CURLOPT_CUSTOMREQUEST] = $method;
            if ($postfields !== null) {
                $opt[CURLOPT_POSTFIELDS] = $postfields;
            }
        }

        // Impersonation (profile, possibly rotated) + user headers
        $profile = $this->effectiveProfile();

        // Behavioural coherence: realistic Sec-Fetch-Site + Referer chain
        if ($this->stealth && $profile !== null) {
            $headers = $this->applyStealthHeaders($headers, $profile, $url);
        }

        $opt[CURLOPT_USERAGENT]  = $profile?->userAgent ?? $this->userAgent;
        $opt[CURLOPT_HTTPHEADER] = $this->buildHeaderLines($headers, $profile);
        if ($profile !== null) {
            $opt += $this->profileOptions($profile);
        }

        // Cookies scoped to this URL (session persistence)
        $cookieHeader = $this->cookieJar->toHeader($url);
        if ($cookieHeader !== '') {
            $opt[CURLOPT_COOKIE] = $cookieHeader;
        }

        // Proxy
        if (is_array($server)) {
            $opt += $this->proxyOptions($server);
        }

        // Per-handle response-header capture
        $buffer = (object)['rawResponseHeaders' => ''];
        $opt[CURLOPT_HEADERFUNCTION] = function ($_, $header) use ($buffer) {
            $buffer->rawResponseHeaders .= $header;
            return strlen($header);
        };

        curl_setopt_array($ch, $opt);

        return [$ch, $buffer];
    }

    /**
     * Merge the active profile's ordered headers with caller-supplied headers.
     * Caller headers override the profile by name and are appended if new.
     *
     * @param array<int,string>|null $headers indexed "Name: value" strings
     * @return array<int,string>
     */
    private function buildHeaderLines(?array $headers, ?Profile $profile): array
    {
        if ($profile === null) {
            return $headers ?? [];
        }

        $merged = $profile->headers;                 // name => value, ordered
        $merged['User-Agent'] ??= $profile->userAgent;

        foreach ($headers ?? [] as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            // case-insensitive override while preserving original key position
            $existing = null;
            foreach ($merged as $key => $_) {
                if (strcasecmp($key, trim($name)) === 0) {
                    $existing = $key;
                    break;
                }
            }
            $merged[$existing ?? trim($name)] = ltrim($value);
        }

        $lines = [];
        foreach ($merged as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return $lines;
    }

    private function profileOptions(Profile $p): array
    {
        $opt = [
            CURLOPT_SSL_CIPHER_LIST => $p->ciphers,
            CURLOPT_ENCODING        => $p->encoding,
        ];

        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $opt[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        if ($p->http2 && defined('CURL_HTTP_VERSION_2_0')) {
            $opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        }
        if (defined('CURLOPT_SSL_EC_CURVES')) {
            $opt[CURLOPT_SSL_EC_CURVES] = $p->curves;
        }

        // curl-impersonate (BoringSSL) only — applied automatically when available,
        // giving a byte-exact JA3/JA4. Harmless no-ops on stock libcurl.
        if (defined('CURLOPT_SSL_ENABLE_ALPS'))      $opt[CURLOPT_SSL_ENABLE_ALPS] = 1;
        if (defined('CURLOPT_HTTP2_NO_SERVER_PUSH')) $opt[CURLOPT_HTTP2_NO_SERVER_PUSH] = 1;
        if (defined('CURLOPT_SSL_CERT_COMPRESSION')) $opt[CURLOPT_SSL_CERT_COMPRESSION] = 'brotli';
        if ($p->permuteExtensions && defined('CURLOPT_TLS_PERMUTE_EXTENSIONS')) {
            $opt[CURLOPT_TLS_PERMUTE_EXTENSIONS] = 1;
        }

        return $opt;
    }

    /**
     * Inject a realistic Sec-Fetch-Site + Referer derived from the previous request
     * in this session. Values the caller set explicitly always win.
     *
     * @param array<int,string>|null $headers
     * @return array<int,string>
     */
    private function applyStealthHeaders(?array $headers, Profile $profile, string $url): array
    {
        $extra = [];

        // Only emit Sec-Fetch-Site for profiles that actually send the Sec-Fetch family.
        foreach (array_keys($profile->headers) as $name) {
            if (strcasecmp($name, 'Sec-Fetch-Site') === 0) {
                $extra[] = 'Sec-Fetch-Site: ' . $this->secFetchSite($this->lastUrl, $url);
                break;
            }
        }

        $referer = $this->referer($this->lastUrl, $url);
        if ($referer !== null) {
            $extra[] = 'Referer: ' . $referer;
        }

        return $this->mergeHeaderList($headers, $extra);
    }

    /** Browser request-context: none | same-origin | same-site | cross-site. */
    private function secFetchSite(string $from, string $to): string
    {
        if ($from === '') {
            return 'none';
        }

        $a = parse_url($from);
        $b = parse_url($to);

        if ($this->origin($a) === $this->origin($b)) {
            return 'same-origin';
        }

        if ($this->registrableDomain($a['host'] ?? '') === $this->registrableDomain($b['host'] ?? '')) {
            return 'same-site';
        }

        return 'cross-site';
    }

    /**
     * Referer honouring the default strict-origin-when-cross-origin policy:
     * full URL within the same site, origin-only across sites, none on first hit.
     */
    private function referer(string $from, string $to): ?string
    {
        if ($from === '') {
            return null;
        }

        if ($this->secFetchSite($from, $to) === 'cross-site') {
            return $this->origin(parse_url($from)) . '/';
        }

        return $from;
    }

    private function origin(array|false $parts): string
    {
        if (!is_array($parts)) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /** Approximate eTLD+1 (last two labels). Good enough for Sec-Fetch heuristics. */
    private function registrableDomain(string $host): string
    {
        $parts = explode('.', strtolower($host));
        return count($parts) <= 2 ? $host : implode('.', array_slice($parts, -2));
    }

    private function proxyOptions(array $args): array
    {
        $args = array_change_key_case($args);

        return match ($args['method'] ?? '') {
            'tunnel' => [CURLOPT_PROXY => $args['server'], CURLOPT_HTTPPROXYTUNNEL => true],
            'custom' => [CURLOPT_PROXY => $args['server'], CURLOPT_PROXYUSERPWD => $args['auth']],
            default  => throw new PulsarException('Invalid proxy router.'),
        };
    }

    /* ----------------------------------------------------------------------
     * Synchronous API
     * -------------------------------------------------------------------- */

    public function get(string $url, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch('GET', $url, null, null, $headers, $cookie, $server, $params, $timeout);
    }

    public function post(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch('POST', $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
    }

    public function put(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch('PUT', $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
    }

    public function patch(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch('PATCH', $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
    }

    public function delete(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch('DELETE', $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
    }

    public function custom(string $url, string $method = 'GET', string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, ?int $timeout = null): Response
    {
        return $this->dispatch($method, $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
    }

    private function dispatch(string $method, string $url, string|array|Mime|null $data, mixed $json, ?array $headers, string|CookieJarInterface|null $cookie, ?array $server, ?array $params = null, ?int $timeout = null): Response
    {
        $this->currentUrl = $this->applyParams($url, $params);

        $attempt = 0;
        while (true) {
            [$ch, $buffer] = $this->makeHandle($method, $url, $data, $json, $headers, $cookie, $server, $params, $timeout);
            $this->ch = $ch;
            $this->callback = $buffer;

            $body = curl_exec($ch);
            $info = curl_getinfo($ch);

            $this->body         = $body;
            $this->info         = $info;
            $this->error_code   = curl_errno($ch);
            $this->error_string = curl_error($ch);

            $response = $this->buildResponse($ch, $buffer, $body, $info);
            unset($ch);

            if (!$this->shouldRetry($response, $attempt)) {
                break;
            }

            $this->backoff($attempt);
            $attempt++;
        }

        if ($this->throwOnError && !$response->ok()) {
            throw new PulsarException(
                "Request to {$this->currentUrl} failed with status {$response->getStatusCode()}"
                . ($response->getReason() ? ": {$response->getReason()}" : '')
            );
        }

        return $response;
    }

    /**
     * Turn a finished handle into a Response and ingest its Set-Cookie headers.
     */
    private function buildResponse(CurlHandle $ch, object $buffer, bool|string $body, array $info, string $url = ''): Response
    {
        $url  = $url !== '' ? $url : ($info['url'] ?? $this->currentUrl);
        $host = (string)parse_url($url, PHP_URL_HOST);
        $this->cookieJar->ingestResponseHeaders($buffer->rawResponseHeaders, $host);

        // Remember where we ended up (after redirects) for the next request's Referer chain.
        $this->lastUrl = $info['url'] ?? $url;

        $headers = [
            'request'  => array_key_exists('request_header', $info) ? $this->parseHeadersHandle($info['request_header']) : [],
            'response' => $buffer->rawResponseHeaders,
        ];

        $elapsed   = (float)($info['total_time'] ?? 0.0);
        $redirects = (int)($info['redirect_count'] ?? 0);

        // A transport error is curl returning false — NOT an empty body (e.g. 204).
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            return new Response(
                success: false,
                status_code: $info['http_code'] ?? 0,
                headers: $headers,
                body: 'Error code: ' . $errno . ' / Message: ' . $error,
                reason: $error,
                elapsed: $elapsed,
                url: $url,
                redirects: $redirects,
            );
        }

        return new Response(
            success: true,
            status_code: $info['http_code'] ?? 0,
            headers: $headers,
            body: $body,
            elapsed: $elapsed,
            url: $url,
            redirects: $redirects,
        );
    }

    /* ----------------------------------------------------------------------
     * Asynchronous API (curl_multi)
     * -------------------------------------------------------------------- */

    public function getAsync(string $url, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, ?array $params = null, string|int|null $key = null): Promise
    {
        return $this->defer('GET', $url, null, null, $headers, $cookie, $server, $params, $key);
    }

    public function postAsync(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, string|int|null $key = null): Promise
    {
        return $this->defer('POST', $url, $data, $json, $headers, $cookie, $server, $params, $key);
    }

    public function requestAsync(string $method, string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, ?array $params = null, string|int|null $key = null): Promise
    {
        return $this->defer($method, $url, $data, $json, $headers, $cookie, $server, $params, $key);
    }

    private function defer(string $method, string $url, string|array|Mime|null $data, mixed $json, ?array $headers, string|CookieJarInterface|null $cookie, ?array $server, ?array $params, string|int|null $key): Promise
    {
        $factory = fn(): array => $this->makeHandle($method, $url, $data, $json, $headers, $cookie, $server, $params);
        return new Promise($factory, $this->applyParams($url, $params), $this, $key ?? $url);
    }

    /**
     * Run a batch of promises concurrently with a rolling window, resolving each
     * as soon as it finishes (curl_multi_select keeps CPU near zero while waiting).
     *
     * @param Promise[] $promises
     * @return array<string|int, Response> keyed by each promise's key
     */
    public function pool(array $promises, int $concurrency = 10): array
    {
        $mh        = curl_multi_init();
        $results   = [];
        $byHandle  = [];
        $pending   = array_values($promises);
        $next      = 0;
        $total     = count($pending);
        $concurrency = max(1, $concurrency);

        $add = function () use (&$next, $pending, $total, $mh, &$byHandle) {
            if ($next < $total) {
                $promise = $pending[$next++];
                curl_multi_add_handle($mh, $promise->handle);
                $byHandle[(int)$promise->handle] = $promise;
            }
        };

        for ($i = 0; $i < min($concurrency, $total); $i++) {
            $add();
        }

        do {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($done = curl_multi_info_read($mh)) {
                $handle  = $done['handle'];
                $promise = $byHandle[(int)$handle] ?? null;
                curl_multi_remove_handle($mh, $handle);

                if ($promise === null) {
                    continue;
                }
                unset($byHandle[(int)$handle]);

                $body     = curl_multi_getcontent($handle);
                $info     = curl_getinfo($handle);
                $response = $this->buildResponse($handle, $promise->buffer, $body, $info, $promise->url);

                // Retry in place (no backoff sleep — it would stall the whole pool).
                if ($this->shouldRetry($response, $promise->attempts)) {
                    $promise->rebuild();
                    curl_multi_add_handle($mh, $promise->handle);
                    $byHandle[(int)$promise->handle] = $promise;
                    continue;
                }

                $promise->resolve($response);
                $results[$promise->key] = $response;
                $add(); // refill the window
            }

            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running || $next < $total || !empty($byHandle));

        curl_multi_close($mh);
        return $results;
    }

    /* ----------------------------------------------------------------------
     * Debug
     * -------------------------------------------------------------------- */

    public function debug(): void
    {
        if (php_sapi_name() === 'cli') {
            echo "=============================================\nPULSAR DEBUG\n=============================================\n";
            echo "Response:\n" . $this->body . "\n\n";
            echo "=============================================\nInformation:\n";
            echo print_r([
                'request_headers'  => $this->parseArray($this->info),
                'response_headers' => $this->parseHeadersHandle($this->callback->rawResponseHeaders),
            ], true) . "\n";

            if ($this->error_string !== '') {
                echo "=============================================\nErrors\nCode: {$this->error_code}\nMessage: {$this->error_string}\n";
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'pulsar_debug' => [
                    'information' => [
                        'request_headers'  => $this->parseArray($this->info),
                        'response_headers' => $this->parseHeadersHandle($this->callback->rawResponseHeaders),
                    ],
                    'errors'   => ['errnum' => $this->error_code, 'errstr' => $this->error_string],
                    'response' => $this->body,
                ],
            ]);
        }
    }
}
