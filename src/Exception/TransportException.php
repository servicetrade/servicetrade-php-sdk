<?php

declare(strict_types=1);

namespace ServiceTrade\Exception;

class TransportException extends ServiceTradeException
{
    public function __construct(
        public readonly int $curlErrorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $this->curlErrorCode, $previous);
    }
}
