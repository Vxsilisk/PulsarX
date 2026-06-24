<?php

/**
 * PulsarX — in-memory cookie jar with domain / path / expiry scoping
 * (like Python requests' session cookies). Nothing is written to disk.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class CookieJar implements CookieJarInterface
{
    /** @var array<string, Cookie> keyed by domain|path|name so a name can live on several hosts */
    private array $cookies = [];

    private function key(Cookie $cookie): string
    {
        $domain = strtolower(ltrim($cookie->domain, '.'));
        $path = $cookie->path !== '' ? $cookie->path : '/';

        return $domain . '|' . $path . '|' . $cookie->name;
    }

    public function add(Cookie $cookie)
    {
        $this->cookies[$this->key($cookie)] = $cookie;
        return $this;
    }

    public function merge(string $cookieHeader)
    {
        $pairs = preg_split('/;\s*/', trim($cookieHeader), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $this->add(new Cookie(name: trim($name), value: trim($value)));
        }

        return $this;
    }

    public function ingestResponseHeaders(string $rawHeaders, string $requestHost = '')
    {
        $headers = preg_split('/\r\n|\n|\r/', $rawHeaders, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($headers as $header) {
            if (!str_starts_with(strtolower($header), 'set-cookie:')) {
                continue;
            }

            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $parts = preg_split('/;\s*/', $cookie, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $nameValue = array_shift($parts);

            if (!is_string($nameValue) || !str_contains($nameValue, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $nameValue, 2);
            $cookieObject = new Cookie(name: trim($name), value: trim($value));
            // host-only by default: bind the cookie to the host that issued it
            $cookieObject->domain = $requestHost;

            foreach ($parts as $part) {
                if (strcasecmp($part, 'httponly') === 0) {
                    $cookieObject->httpOnly = 'TRUE';
                    continue;
                }

                if (str_starts_with(strtolower($part), 'domain=')) {
                    $cookieObject->domain = trim(substr($part, 7));
                    continue;
                }

                if (str_starts_with(strtolower($part), 'path=')) {
                    $cookieObject->path = trim(substr($part, 5));
                    continue;
                }

                if (str_starts_with(strtolower($part), 'expires=')) {
                    $cookieObject->expire = trim(substr($part, 8));
                }
            }

            $this->add($cookieObject);
        }

        return $this;
    }

    public function toHeader(?string $url = null): string
    {
        $host = $url !== null ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '';
        $path = $url !== null ? ((string)parse_url($url, PHP_URL_PATH) ?: '/') : '/';

        $pairs = [];

        foreach ($this->cookies as $cookie) {
            // no URL given -> behave like a flat jar and send everything
            if ($url !== null && !$this->matches($cookie, $host, $path)) {
                continue;
            }

            $pairs[] = $cookie->toHeader();
        }

        return implode('; ', $pairs);
    }

    private function matches(Cookie $cookie, string $host, string $path): bool
    {
        return !$this->isExpired($cookie)
            && $this->matchesDomain($cookie, $host)
            && $this->matchesPath($cookie, $path);
    }

    private function matchesDomain(Cookie $cookie, string $host): bool
    {
        $domain = strtolower(ltrim($cookie->domain, '.'));

        if ($domain === '') {
            return true; // unscoped / manually added cookie -> send to any host
        }

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private function matchesPath(Cookie $cookie, string $path): bool
    {
        $cookiePath = $cookie->path !== '' ? $cookie->path : '/';

        if ($cookiePath === '/') {
            return true;
        }

        return $path === $cookiePath || str_starts_with($path, rtrim($cookiePath, '/') . '/');
    }

    private function isExpired(Cookie $cookie): bool
    {
        if ($cookie->expire === '') {
            return false; // session cookie -> lives for the whole session
        }

        $timestamp = strtotime($cookie->expire);

        return $timestamp !== false && $timestamp <= time();
    }

    public function clear()
    {
        $this->cookies = [];
        return $this;
    }
}
