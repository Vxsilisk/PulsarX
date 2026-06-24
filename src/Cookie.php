<?php

/**
 * PulsarX — a single cookie.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Cookie
{
    public const HTTP_ONLY = '#HttpOnly_.';

    public function __construct(
        public string $domain = '',
        public string $includeSubDomains = 'TRUE',
        public string $path = '/',
        public string $httpOnly = 'FALSE',
        public string $expire = '',
        public string $name = '',
        public string $value = '',
    ) {}

    public function get()
    {
        return $this->toHeader();
    }

    public function toHeader(): string
    {
        return "{$this->name}={$this->value}";
    }
}
