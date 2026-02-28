<?php

declare(strict_types=1);

namespace ServiceTrade;

final class Response
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function decodedBody(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
