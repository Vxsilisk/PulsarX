<?php

/**
 * PulsarX — an immutable HTTP response.
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Response
{
    public function __construct(
        private readonly bool  $success = false,
        private readonly int   $status_code = 200,
        private readonly array $headers = [],
        private                $body = null,
        private                $reason = null,
        private readonly float $elapsed = 0.0,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** requests-style: true when the status code is in [200, 400). */
    public function ok(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 400;
    }

    /** Total transfer time in seconds. */
    public function getElapsed(): float
    {
        return $this->elapsed;
    }

    public function getStatusCode(): int
    {
        return $this->status_code;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** requests-style helper: decode the body as JSON. */
    public function json(bool $assoc = true): mixed
    {
        return json_decode((string)$this->body, $assoc);
    }
}
