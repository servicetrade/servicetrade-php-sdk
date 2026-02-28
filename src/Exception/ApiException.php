<?php

declare(strict_types=1);

namespace ServiceTrade\Exception;

class ApiException extends ServiceTradeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly array $messages = [],
        public readonly array $validation = [],
        public readonly ?string $responseBody = null,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        if ($message === '' && $this->messages !== []) {
            $parts = array_map(
                fn($m) => is_string($m) ? $m : json_encode($m),
                $this->messages,
            );
            $message = implode('; ', $parts);
        }
        if ($message === '') {
            $message = "API request failed with status {$this->statusCode}";
        }

        parent::__construct($message, $this->statusCode, $previous);
    }
}
