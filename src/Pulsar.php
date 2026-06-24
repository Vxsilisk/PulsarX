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
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ];

    private CurlHandle $ch;
    private object $callback;

    private array|false $info;

    private CookieJarInterface $cookieJar;
    private string $userAgent = 'PulsarX/1.0 (+https://github.com/Vxsilisk/PulsarX) requests-style cURL client';
    private ?Profile $profile = null;

    private int $error_code = 0;
    private string $error_string = '';

    private bool|string $body = false;
    private string $currentUrl = '';

    public function __construct(array $config = [])
    {
        $this->default   = array_replace($this->default, $config);
        $this->cookieJar = new CookieJar();
    }

    /* ----------------------------------------------------------------------
     * Impersonation
     * -------------------------------------------------------------------- */

    /**
     * Mimic a browser fingerprint. Chainable: $s->impersonate('chrome')->get($url).
     *
     * @param string|Profile $browser a target name (see impersonateTargets()) or a Profile
     */
    public function impersonate(string|Profile $browser): static
    {
        $this->profile = $browser instanceof Profile ? $browser : Profile::resolve($browser);
        return $this;
    }

    public function clearImpersonation(): static
    {
        $this->profile = null;
        return $this;
    }

    /** @return string[] every impersonation target Pulsar knows about. */
    public static function impersonateTargets(): array
    {
        return Profile::available();
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
        ?array $server
    ): array {
        if ($cookie !== null) {
            $this->setCookie($cookie);
        }

        $ch  = curl_init($url);
        $opt = $this->default;

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

        // Impersonation (profile) + user headers
        $opt[CURLOPT_USERAGENT]  = $this->profile?->userAgent ?? $this->userAgent;
        $opt[CURLOPT_HTTPHEADER] = $this->buildHeaderLines($headers);
        if ($this->profile !== null) {
            $opt += $this->profileOptions($this->profile);
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
    private function buildHeaderLines(?array $headers): array
    {
        if ($this->profile === null) {
            return $headers ?? [];
        }

        $merged = $this->profile->headers;          // name => value, ordered
        $merged['User-Agent'] ??= $this->profile->userAgent;

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

    public function get(string $url, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null): Response
    {
        return $this->dispatch('GET', $url, null, null, $headers, $cookie, $server);
    }

    public function post(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null): Response
    {
        return $this->dispatch('POST', $url, $data, $json, $headers, $cookie, $server);
    }

    public function put(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null): Response
    {
        return $this->dispatch('PUT', $url, $data, $json, $headers, $cookie, $server);
    }

    public function patch(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null): Response
    {
        return $this->dispatch('PATCH', $url, $data, $json, $headers, $cookie, $server);
    }

    public function delete(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null): Response
    {
        return $this->dispatch('DELETE', $url, $data, $json, $headers, $cookie, $server);
    }

    public function custom(string $url, string $method = 'GET', string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null): Response
    {
        return $this->dispatch($method, $url, $data, $json, $headers, $cookie, $server);
    }

    private function dispatch(string $method, string $url, string|array|Mime|null $data, mixed $json, ?array $headers, string|CookieJarInterface|null $cookie, ?array $server): Response
    {
        $this->currentUrl = $url;
        [$ch, $buffer] = $this->makeHandle($method, $url, $data, $json, $headers, $cookie, $server);
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

        $headers = [
            'request'  => array_key_exists('request_header', $info) ? $this->parseHeadersHandle($info['request_header']) : [],
            'response' => $buffer->rawResponseHeaders,
        ];

        $elapsed = (float)($info['total_time'] ?? 0.0);

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
            );
        }

        return new Response(
            success: true,
            status_code: $info['http_code'] ?? 0,
            headers: $headers,
            body: $body,
            elapsed: $elapsed,
        );
    }

    /* ----------------------------------------------------------------------
     * Asynchronous API (curl_multi)
     * -------------------------------------------------------------------- */

    public function getAsync(string $url, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, string|int|null $key = null): Promise
    {
        return $this->defer('GET', $url, null, null, $headers, $cookie, $server, $key);
    }

    public function postAsync(string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, string|int|null $key = null): Promise
    {
        return $this->defer('POST', $url, $data, $json, $headers, $cookie, $server, $key);
    }

    public function requestAsync(string $method, string $url, string|array|Mime|null $data = null, ?array $headers = null, string|CookieJarInterface|null $cookie = null, ?array $server = null, mixed $json = null, string|int|null $key = null): Promise
    {
        return $this->defer($method, $url, $data, $json, $headers, $cookie, $server, $key);
    }

    private function defer(string $method, string $url, string|array|Mime|null $data, mixed $json, ?array $headers, string|CookieJarInterface|null $cookie, ?array $server, string|int|null $key): Promise
    {
        [$ch, $buffer] = $this->makeHandle($method, $url, $data, $json, $headers, $cookie, $server);
        return new Promise($ch, $buffer, $url, $this, $key ?? $url);
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

                if ($promise !== null) {
                    $body     = curl_multi_getcontent($handle);
                    $info     = curl_getinfo($handle);
                    $response = $this->buildResponse($handle, $promise->buffer, $body, $info, $promise->url);
                    $promise->resolve($response);
                    $results[$promise->key] = $response;
                    unset($byHandle[(int)$handle]);
                }

                curl_multi_remove_handle($mh, $handle);
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
