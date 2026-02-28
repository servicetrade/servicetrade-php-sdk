<?php

declare(strict_types=1);

namespace ServiceTrade;

use ServiceTrade\Exception\TransportException;

final class CurlHttpTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly string $userAgent,
    ) {
    }

    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null,
        array $options = [],
    ): Response {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (isset($options['multipart'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($headers !== []) {
            $formattedHeaders = [];
            foreach ($headers as $key => $value) {
                $formattedHeaders[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders): int {
            $trimmed = trim($line);
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        });

        $rawResult = curl_exec($ch);

        if ($rawResult === false) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            throw new TransportException($errorCode, "cURL error: {$errorMessage}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return new Response($statusCode, (string) $rawResult, $responseHeaders);
    }
}
