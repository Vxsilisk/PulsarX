<?php

/**
 * PulsarX — a deferred async request.
 *
 * Returned by Pulsar::getAsync()/postAsync()/requestAsync(); resolve a batch
 * with Pulsar::pool([...]). The factory rebuilds a fresh cURL handle on retry.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Promise
{
    public ?Response $response = null;
    /** @var callable[] */
    private array $onResolved = [];

    public CurlHandle $handle;
    public object     $buffer;   // {rawResponseHeaders: string}
    public int        $attempts = 0;

    /**
     * @param Closure $factory returns [CurlHandle, object] — a fresh, configured handle
     */
    public function __construct(
        private readonly Closure   $factory,
        public readonly string     $url,
        public readonly Pulsar     $owner,
        public readonly string|int $key,
    ) {
        [$this->handle, $this->buffer] = ($this->factory)();
    }

    /** Build a fresh handle + buffer for a retry. */
    public function rebuild(): void
    {
        [$this->handle, $this->buffer] = ($this->factory)();
        $this->attempts++;
    }

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
