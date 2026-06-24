<?php

/**
 * PulsarX — a browser profile used by Pulsar::impersonate().
 *
 * Pure-cURL impersonation matches the HTTP layer (User-Agent, header set + order,
 * Accept-Encoding) and the TLS cipher ordering. A byte-exact JA3/JA4 (TLS extension
 * order, GREASE, ALPS, HTTP/2 SETTINGS) requires a curl-impersonate build (BoringSSL);
 * when such a libcurl is present, the extra options are applied automatically.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Profile
{
    public function __construct(
        public string  $name,
        public string  $userAgent,
        /** @var array<string,string> ordered name => value */
        public array   $headers,
        public string  $ciphers,
        public string  $curves = 'X25519:P-256:P-384',
        public string  $encoding = 'gzip, deflate, br, zstd',
        public bool    $http2 = true,
        public bool    $permuteExtensions = true,
    ) {}

    // TLS 1.2/1.3 cipher ordering per family (from curl-impersonate). OpenSSL honours
    // the order, which shapes the JA3 cipher list even without BoringSSL.
    private const CHROME_CIPHERS =
        'TLS_AES_128_GCM_SHA256,TLS_AES_256_GCM_SHA384,TLS_CHACHA20_POLY1305_SHA256,'
        . 'ECDHE-ECDSA-AES128-GCM-SHA256,ECDHE-RSA-AES128-GCM-SHA256,ECDHE-ECDSA-AES256-GCM-SHA384,'
        . 'ECDHE-RSA-AES256-GCM-SHA384,ECDHE-ECDSA-CHACHA20-POLY1305,ECDHE-RSA-CHACHA20-POLY1305,'
        . 'ECDHE-RSA-AES128-SHA,ECDHE-RSA-AES256-SHA,AES128-GCM-SHA256,AES256-GCM-SHA384,AES128-SHA,AES256-SHA';

    private const FIREFOX_CIPHERS =
        'TLS_AES_128_GCM_SHA256,TLS_CHACHA20_POLY1305_SHA256,TLS_AES_256_GCM_SHA384,'
        . 'ECDHE-ECDSA-AES128-GCM-SHA256,ECDHE-RSA-AES128-GCM-SHA256,ECDHE-ECDSA-CHACHA20-POLY1305,'
        . 'ECDHE-RSA-CHACHA20-POLY1305,ECDHE-ECDSA-AES256-GCM-SHA384,ECDHE-RSA-AES256-GCM-SHA384,'
        . 'ECDHE-ECDSA-AES256-SHA,ECDHE-ECDSA-AES128-SHA,ECDHE-RSA-AES128-SHA,ECDHE-RSA-AES256-SHA,'
        . 'AES128-GCM-SHA256,AES256-GCM-SHA384,AES128-SHA,AES256-SHA';

    private const SAFARI_CIPHERS =
        'TLS_AES_128_GCM_SHA256,TLS_AES_256_GCM_SHA384,TLS_CHACHA20_POLY1305_SHA256,'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384,ECDHE-ECDSA-AES128-GCM-SHA256,ECDHE-ECDSA-CHACHA20-POLY1305,'
        . 'ECDHE-RSA-AES256-GCM-SHA384,ECDHE-RSA-AES128-GCM-SHA256,ECDHE-RSA-CHACHA20-POLY1305,'
        . 'ECDHE-ECDSA-AES256-SHA384,ECDHE-RSA-AES256-SHA384,ECDHE-ECDSA-AES128-SHA256,'
        . 'ECDHE-RSA-AES128-SHA256,AES256-GCM-SHA384,AES128-GCM-SHA256,AES256-SHA,AES128-SHA';

    /**
     * Impersonation target registry: name => [family, version, os, mobile].
     * Mirrors the curl-impersonate / curl_cffi target catalogue.
     *
     * @return array<string, array{0:string,1:string,2:string,3:bool}>
     */
    public static function targets(): array
    {
        return [
            // Chrome — desktop
            'chrome99'          => ['chrome', '99',  'win',     false],
            'chrome110'         => ['chrome', '110', 'win',     false],
            'chrome116'         => ['chrome', '116', 'win',     false],
            'chrome119'         => ['chrome', '119', 'mac',     false],
            'chrome120'         => ['chrome', '120', 'mac',     false],
            'chrome124'         => ['chrome', '124', 'mac',     false],
            'chrome131'         => ['chrome', '131', 'win',     false],
            'chrome133'         => ['chrome', '133', 'mac',     false],
            'chrome136'         => ['chrome', '136', 'mac',     false],
            'chrome142'         => ['chrome', '142', 'mac',     false],
            'chrome146'         => ['chrome', '146', 'mac',     false],
            'chrome'            => ['chrome', '131', 'win',     false], // alias -> latest stable
            // Chrome — Android
            'chrome99_android'  => ['chrome', '99',  'android', true],
            'chrome131_android' => ['chrome', '131', 'android', true],
            'chrome_android'    => ['chrome', '131', 'android', true],
            // Edge
            'edge99'            => ['edge',   '99',  'win',     false],
            'edge101'           => ['edge',   '101', 'win',     false],
            'edge131'           => ['edge',   '131', 'win',     false],
            'edge'              => ['edge',   '131', 'win',     false],
            // Firefox
            'firefox133'        => ['firefox', '133', 'win',    false],
            'firefox135'        => ['firefox', '135', 'win',    false],
            'firefox144'        => ['firefox', '144', 'win',    false],
            'firefox'           => ['firefox', '144', 'win',    false],
            // Safari — macOS
            'safari153'         => ['safari', '15.3', 'mac',    false],
            'safari170'         => ['safari', '17.0', 'mac',    false],
            'safari180'         => ['safari', '18.0', 'mac',    false],
            'safari260'         => ['safari', '26.0', 'mac',    false],
            'safari'            => ['safari', '18.0', 'mac',    false],
            // Safari — iOS
            'safari172_ios'     => ['safari', '17.2', 'ios',    true],
            'safari180_ios'     => ['safari', '18.0', 'ios',    true],
            'safari_ios'        => ['safari', '18.0', 'ios',    true],
            // Tor (Firefox ESR based)
            'tor'               => ['firefox', '128', 'win',    false],
        ];
    }

    /** @return string[] available impersonation target names */
    public static function available(): array
    {
        return array_keys(self::targets());
    }

    /**
     * A realistic spread of current desktop/mobile browsers for rotation.
     * Only complete, coherent targets — rotating between *whole* profiles keeps
     * UA / sec-ch-ua / TLS in sync, unlike randomising fields within one profile.
     *
     * @return string[]
     */
    public static function realisticPool(): array
    {
        return [
            'chrome131', 'chrome133', 'chrome136', 'chrome142', 'chrome146',
            'edge131', 'firefox144', 'firefox135',
            'safari180', 'safari260', 'safari180_ios', 'chrome131_android',
        ];
    }

    /** Pick a random complete profile from $pool (defaults to realisticPool()). */
    public static function random(?array $pool = null): self
    {
        $pool = $pool ?: self::realisticPool();
        return self::resolve($pool[array_rand($pool)]);
    }

    public static function resolve(string $name): self
    {
        $name    = strtolower($name);
        $targets = self::targets();

        if (!isset($targets[$name])) {
            throw new PulsarException("Unknown impersonation target: {$name}. See Pulsar::impersonateTargets().");
        }

        [$family, $version, $os, $mobile] = $targets[$name];

        return match ($family) {
            'chrome'  => self::chromium($name, $version, $os, $mobile, false),
            'edge'    => self::chromium($name, $version, $os, $mobile, true),
            'firefox' => self::firefox($name, $version, $os),
            'safari'  => self::safari($name, $version, $os, $mobile),
        };
    }

    private static function chromium(string $name, string $v, string $os, bool $mobile, bool $edge): self
    {
        $ua = match ($os) {
            'mac'     => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$v}.0.0.0 Safari/537.36",
            'android' => "Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$v}.0.0.0 Mobile Safari/537.36",
            default   => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$v}.0.0.0 Safari/537.36",
        };
        if ($edge) {
            $ua .= " Edg/{$v}.0.0.0";
        }

        $brand = $edge
            ? "\"Microsoft Edge\";v=\"{$v}\", \"Chromium\";v=\"{$v}\", \"Not_A Brand\";v=\"24\""
            : "\"Google Chrome\";v=\"{$v}\", \"Chromium\";v=\"{$v}\", \"Not_A Brand\";v=\"24\"";

        $platform = match ($os) {
            'mac'     => '"macOS"',
            'android' => '"Android"',
            default   => '"Windows"',
        };

        return new self(
            name: $name,
            userAgent: $ua,
            headers: [
                'sec-ch-ua'                 => $brand,
                'sec-ch-ua-mobile'          => $mobile ? '?1' : '?0',
                'sec-ch-ua-platform'        => $platform,
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent'                => $ua,
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Sec-Fetch-Site'            => 'none',
                'Sec-Fetch-Mode'            => 'navigate',
                'Sec-Fetch-User'            => '?1',
                'Sec-Fetch-Dest'            => 'document',
                'Accept-Encoding'           => 'gzip, deflate, br, zstd',
                'Accept-Language'           => 'en-US,en;q=0.9',
            ],
            ciphers: self::CHROME_CIPHERS,
        );
    }

    private static function firefox(string $name, string $v, string $os): self
    {
        $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$v}.0) Gecko/20100101 Firefox/{$v}.0";

        return new self(
            name: $name,
            userAgent: $ua,
            headers: [
                'User-Agent'                => $ua,
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'en-US,en;q=0.5',
                'Accept-Encoding'           => 'gzip, deflate, br, zstd',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest'            => 'document',
                'Sec-Fetch-Mode'            => 'navigate',
                'Sec-Fetch-Site'            => 'none',
                'Sec-Fetch-User'            => '?1',
            ],
            ciphers: self::FIREFOX_CIPHERS,
            curves: 'X25519:P-256:P-384:P-521',
        );
    }

    private static function safari(string $name, string $v, string $os, bool $mobile): self
    {
        if ($os === 'ios') {
            $osToken = str_replace('.', '_', $v);
            $ua = "Mozilla/5.0 (iPhone; CPU iPhone OS {$osToken} like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/{$v} Mobile/15E148 Safari/604.1";
        } else {
            $ua = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/{$v} Safari/605.1.15";
        }

        return new self(
            name: $name,
            userAgent: $ua,
            headers: [
                'User-Agent'      => $ua,
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
            ],
            ciphers: self::SAFARI_CIPHERS,
            encoding: 'gzip, deflate, br',
        );
    }
}
