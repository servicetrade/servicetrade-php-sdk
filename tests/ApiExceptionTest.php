<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\Exception\ApiException;

final class ApiExceptionTest extends TestCase
{
    public function testMessageFromMessagesArray(): void
    {
        $e = new ApiException(400, ['Field required', 'Invalid format']);

        $this->assertSame(400, $e->statusCode);
        $this->assertSame(['Field required', 'Invalid format'], $e->messages);
        $this->assertSame('Field required; Invalid format', $e->getMessage());
        $this->assertSame(400, $e->getCode());
    }

    public function testFallbackMessageWhenMessagesEmpty(): void
    {
        $e = new ApiException(500);

        $this->assertSame([], $e->messages);
        $this->assertSame('API request failed with status 500', $e->getMessage());
    }

    public function testExplicitMessageOverridesAll(): void
    {
        $e = new ApiException(403, ['Access denied'], message: 'Custom message');

        $this->assertSame('Custom message', $e->getMessage());
    }

    public function testValidationStored(): void
    {
        $validation = ['locationId' => 'Location is required'];
        $e = new ApiException(400, ['Validation failed'], $validation);

        $this->assertSame($validation, $e->validation);
    }

    public function testValidationDefaultsToEmpty(): void
    {
        $e = new ApiException(400, ['Some error']);

        $this->assertSame([], $e->validation);
    }

    public function testResponseBodyStored(): void
    {
        $body = '{"messages":{"error":["Not found"]},"data":null}';
        $e = new ApiException(404, ['Not found'], responseBody: $body);

        $this->assertSame($body, $e->responseBody);
    }

    public function testResponseBodyDefaultsToNull(): void
    {
        $e = new ApiException(500);

        $this->assertNull($e->responseBody);
    }
}
