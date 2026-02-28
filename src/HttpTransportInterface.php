<?php

declare(strict_types=1);

namespace ServiceTrade;

/**
 * Internal interface for HTTP transport. Exists only for testability (allowing
 * tests to inject a mock transport). This is not a public extension point --
 * the SDK always uses cURL in production.
 *
 * @internal
 */
interface HttpTransportInterface
{
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null,
        array $options = [],
    ): Response;
}
