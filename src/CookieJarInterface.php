<?php

/**
 * PulsarX — cookie jar contract.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
interface CookieJarInterface
{
    public function add(Cookie $cookie);

    public function merge(string $cookieHeader);

    public function ingestResponseHeaders(string $rawHeaders, string $requestHost = '');

    public function toHeader(?string $url = null): string;

    public function clear();
}
