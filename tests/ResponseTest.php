<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\Response;

final class ResponseTest extends TestCase
{
    public function testDecodedBodyWithJson(): void
    {
        $response = new Response(200, '{"data":{"id":1},"messages":[]}');

        $this->assertSame(['data' => ['id' => 1], 'messages' => []], $response->decodedBody());
    }

    public function testDecodedBodyWithEmptyString(): void
    {
        $response = new Response(204, '');

        $this->assertNull($response->decodedBody());
    }

    public function testDecodedBodyWithInvalidJsonThrows(): void
    {
        $response = new Response(200, 'not json');

        $this->expectException(\JsonException::class);
        $response->decodedBody();
    }

    public function testIsSuccess(): void
    {
        $this->assertTrue((new Response(200, ''))->isSuccess());
        $this->assertTrue((new Response(201, ''))->isSuccess());
        $this->assertTrue((new Response(204, ''))->isSuccess());
        $this->assertFalse((new Response(400, ''))->isSuccess());
        $this->assertFalse((new Response(500, ''))->isSuccess());
    }

    public function testHeadersDefaultToEmpty(): void
    {
        $response = new Response(200, '{}');

        $this->assertSame([], $response->headers);
    }

    public function testHeadersStored(): void
    {
        $headers = ['content-type' => 'application/json', 'x-request-id' => 'abc-123'];
        $response = new Response(200, '{}', $headers);

        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('abc-123', $response->headers['x-request-id']);
    }
}
