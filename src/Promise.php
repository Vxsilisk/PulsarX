<?php

/**
 * PulsarX — a deferred async request.
 *
 * Returned by Pulsar::getAsync()/postAsync()/requestAsync(); resolve a batch
 * with Pulsar::pool([...]).
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Promise
{
    public ?Response $response = null;
    /** @var callable[] */
    private array $onResolved = [];

    public function __construct(
        public readonly CurlHandle $handle,
        public readonly object     $buffer,   // {rawResponseHeaders: string}
        public readonly string     $url,
        public readonly Pulsar     $owner,
        public readonly string|int $key,
    ) {}

    public function then(callable $cb): static
    {
        $this->onResolved[] = $cb;
        return $this;
    }

    public function resolve(Response $response): void
    {
        $this->response = $response;
        foreach ($this->onResolved as $cb) {
            $cb($response);
        }
    }
}
