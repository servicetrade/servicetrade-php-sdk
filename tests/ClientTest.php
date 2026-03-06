<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\Client;
use ServiceTrade\Exception\ApiException;
use ServiceTrade\Exception\AuthenticationException;
use ServiceTrade\HttpTransportInterface;
use ServiceTrade\Response;

final class ClientTest extends TestCase
{
    private function makeJwt(int $exp): string
    {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['exp' => $exp, 'sub' => 'test']));
        $signature = base64_encode('fake-signature');

        return "{$header}.{$payload}.{$signature}";
    }

    private string $testToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testToken = $this->makeJwt(time() + 3600);
    }

    private function tokenResponse(): Response
    {
        return new Response(200, json_encode([
            'access_token' => $this->testToken,
            'expires_in' => 86400,
            'token_type' => 'Bearer',
        ]));
    }

    private function apiResponse(array $data = [], int $status = 200): Response
    {
        return new Response($status, json_encode(['data' => $data, 'messages' => []]));
    }

    private function errorResponse(int $status, array $messages = []): Response
    {
        return new Response($status, json_encode(['messages' => ['error' => $messages]]));
    }

    /**
     * Creates a mock transport that auto-handles OAuth token requests and
     * delegates all other requests to the provided callback.
     */
    private function makeTransport(callable $apiHandler): HttpTransportInterface
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers = [], ?string $body = null, array $options = []) use ($apiHandler) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $apiHandler($method, $url, $headers, $body, $options);
            });

        return $transport;
    }

    /**
     * Creates a Client with sensible defaults for testing.
     * Pass overrides for any constructor parameter you need to change.
     */
    private function makeClient(HttpTransportInterface $transport, array $overrides = []): Client
    {
        return new Client(
            clientId: $overrides['clientId'] ?? 'id',
            clientSecret: $overrides['clientSecret'] ?? 'secret',
            refreshToken: $overrides['refreshToken'] ?? null,
            token: $overrides['token'] ?? null,
            baseUrl: $overrides['baseUrl'] ?? 'https://api.servicetrade.com',
            apiPrefix: $overrides['apiPrefix'] ?? '/api',
            userAgent: $overrides['userAgent'] ?? null,
            autoRefreshAuth: $overrides['autoRefreshAuth'] ?? false,
            onSetAuth: $overrides['onSetAuth'] ?? null,
            onUnsetAuth: $overrides['onUnsetAuth'] ?? null,
            transport: $transport,
        );
    }

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function testLazyAuthOnFirstApiCall(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->apiResponse(['id' => 1]);
            });

        $client = $this->makeClient($transport);

        // Token is null before first API call
        $this->assertNull($client->getAuthToken());

        // First API call triggers connect
        $client->get('/job/1');
        $this->assertSame($this->testToken, $client->getAuthToken());
    }

    public function testExplicitConnectBeforeApiCall(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->apiResponse(['id' => 1]);
            });

        $client = $this->makeClient($transport);

        $client->connect();
        $this->assertSame($this->testToken, $client->getAuthToken());

        $client->get('/job/1');
    }

    public function testGetSendsBearerHeader(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url, array $headers) {
            $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
            return $this->apiResponse(['id' => 42]);
        });

        $result = $this->makeClient($transport)->get('/job/42');

        $this->assertSame(['id' => 42], $result);
    }

    public function testTokenOnlyLazyAuth(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        // Should never call the token endpoint
        $transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                $this->assertStringNotContainsString('/oauth2/token', $url);
                return $this->apiResponse(['ok' => true]);
            });

        $client = new Client(
            token: 'my_bearer_token',
            autoRefreshAuth: false,
            transport: $transport,
        );

        // Token not yet set before first call
        $this->assertNull($client->getAuthToken());

        $client->get('/test');

        // Token set after first call triggered lazy connect
        $this->assertSame('my_bearer_token', $client->getAuthToken());
    }

    // ---------------------------------------------------------------
    // CRUD operations
    // ---------------------------------------------------------------

    public function testGetWithQueryParams(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
            $this->assertSame('GET', $method);
            $this->assertStringContainsString('page=1', $url);
            $this->assertStringContainsString('limit=25', $url);
            return $this->apiResponse([]);
        });

        $this->makeClient($transport)->get('/jobs', ['page' => 1, 'limit' => 25]);
    }

    public function testPost(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url, array $headers, ?string $body) {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('/api/job', $url);
            $this->assertSame('application/json', $headers['Content-Type']);
            $decoded = json_decode($body, true);
            $this->assertSame('New Job', $decoded['name']);

            return $this->apiResponse(['id' => 99, 'name' => 'New Job']);
        });

        $result = $this->makeClient($transport)->post('/job', ['name' => 'New Job']);

        $this->assertSame(99, $result['id']);
    }

    public function testPostWithQueryParams(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url, array $headers, ?string $body) {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('notify=true', $url);
            $decoded = json_decode($body, true);
            $this->assertSame('New Job', $decoded['name']);
            return $this->apiResponse(['id' => 1, 'name' => 'New Job']);
        });

        $result = $this->makeClient($transport)->post('/job', ['name' => 'New Job'], ['notify' => 'true']);

        $this->assertSame(1, $result['id']);
    }

    public function testPut(): void
    {
        $transport = $this->makeTransport(function (string $method) {
            $this->assertSame('PUT', $method);
            return $this->apiResponse(['id' => 1, 'name' => 'Updated']);
        });

        $result = $this->makeClient($transport)->put('/job/1', ['name' => 'Updated']);

        $this->assertSame('Updated', $result['name']);
    }

    public function testPutWithQueryParams(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url, array $headers, ?string $body) {
            $this->assertSame('PUT', $method);
            $this->assertStringContainsString('notify=true', $url);
            $decoded = json_decode($body, true);
            $this->assertSame('Updated', $decoded['name']);
            return $this->apiResponse(['id' => 1, 'name' => 'Updated']);
        });

        $result = $this->makeClient($transport)->put('/job/1', ['name' => 'Updated'], ['notify' => 'true']);

        $this->assertSame('Updated', $result['name']);
    }

    public function testDelete(): void
    {
        $transport = $this->makeTransport(function (string $method) {
            $this->assertSame('DELETE', $method);
            return new Response(204, '');
        });

        $this->makeClient($transport)->delete('/job/1'); // Should not throw
    }

    public function testDeleteWithQueryParams(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
            $this->assertSame('DELETE', $method);
            $this->assertStringContainsString('cascade=true', $url);
            return new Response(204, '');
        });

        $this->makeClient($transport)->delete('/job/1', ['cascade' => 'true']);
    }

    public function testDeleteThrowsOnError(): void
    {
        $transport = $this->makeTransport(fn() => $this->errorResponse(404, ['Not found']));
        $client = $this->makeClient($transport);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);
        $client->delete('/job/999');
    }

    public function testAttachSendsMultipart(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sdk_test_');
        file_put_contents($tmpFile, 'test file content');

        try {
            $transport = $this->makeTransport(function (string $method, string $url, array $headers, ?string $body, array $options) {
                $this->assertSame('POST', $method);
                $this->assertStringContainsString('/attachment', $url);
                // Content-Type should NOT be set (cURL sets multipart boundary)
                $this->assertArrayNotHasKey('Content-Type', $headers);
                $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
                // Multipart data passed via options
                $this->assertArrayHasKey('multipart', $options);
                $this->assertArrayHasKey('uploadedFile', $options['multipart']);
                $this->assertInstanceOf(\CURLFile::class, $options['multipart']['uploadedFile']);
                $this->assertSame('job', $options['multipart']['entityType']);

                return $this->apiResponse(['id' => 55]);
            });

            $result = $this->makeClient($transport)->attach($tmpFile, ['entityType' => 'job', 'entityId' => 1]);

            $this->assertSame(['id' => 55], $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testAttachThrowsOnMissingFile(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $client = $this->makeClient($transport, ['clientId' => null, 'clientSecret' => null, 'token' => 'tok']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');
        $client->attach('/nonexistent/file.pdf', ['entityType' => 'job']);
    }

    // ---------------------------------------------------------------
    // Error handling and 401 retry
    // ---------------------------------------------------------------

    public function test401TriggersRefreshAndRetry(): void
    {
        $callLog = [];

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) use (&$callLog) {
                if (str_contains($url, '/oauth2/token')) {
                    $callLog[] = 'token';
                    return $this->tokenResponse();
                }

                $callLog[] = 'api';
                // First API call returns 401, second succeeds
                if (count(array_filter($callLog, fn($c) => $c === 'api')) === 1) {
                    return $this->errorResponse(401, ['Unauthorized']);
                }

                return $this->apiResponse(['id' => 1]);
            });

        $client = $this->makeClient($transport, ['autoRefreshAuth' => true]);
        $result = $client->get('/job/1');

        $this->assertSame(['id' => 1], $result);
        // Should have: connect token, api 401, refresh token, api success
        $this->assertSame(['token', 'api', 'token', 'api'], $callLog);
    }

    public function test401WithNoRefreshThrows(): void
    {
        $transport = $this->makeTransport(fn() => $this->errorResponse(401, ['Token expired']));
        $client = $this->makeClient($transport, ['clientId' => null, 'clientSecret' => null, 'token' => 'pre_existing']);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $client->get('/job/1');
    }

    public function test401AfterRefreshThrowsAuthenticationException(): void
    {
        // Always returns 401, even after refresh
        $transport = $this->makeTransport(fn() => $this->errorResponse(401, ['Unauthorized']));
        $client = $this->makeClient($transport, ['autoRefreshAuth' => true]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Authentication failed after token refresh');
        $client->get('/job/1');
    }

    public function testApiExceptionOn4xx(): void
    {
        $transport = $this->makeTransport(fn() => $this->errorResponse(403, ['Access denied']));
        $client = $this->makeClient($transport);

        try {
            $client->get('/admin/secret');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertSame(['Access denied'], $e->messages);
        }
    }

    public function testAttach401RespectsAutoRefreshFlag(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sdk_test_');
        file_put_contents($tmpFile, 'content');

        try {
            // With autoRefreshAuth=false, 401 from attach should throw, not retry
            $transport = $this->makeTransport(fn() => $this->errorResponse(401, ['Unauthorized']));
            $client = $this->makeClient($transport);

            $this->expectException(ApiException::class);
            $this->expectExceptionCode(401);
            $client->attach($tmpFile, ['entityType' => 'job']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testPostThrowsOnUnencodableData(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')->willReturn($this->tokenResponse());

        $client = $this->makeClient($transport);

        $this->expectException(\JsonException::class);
        $client->post('/test', ['bad' => "\xB1\x31"]);
    }

    // ---------------------------------------------------------------
    // Response handling
    // ---------------------------------------------------------------

    public function testEmptyResponseReturnsNull(): void
    {
        $transport = $this->makeTransport(fn() => new Response(200, json_encode(['messages' => []])));

        $result = $this->makeClient($transport)->get('/job/1');

        $this->assertNull($result);
    }

    public function testGetLastResponse(): void
    {
        $transport = $this->makeTransport(fn() => new Response(200, json_encode(['data' => ['id' => 1], 'messages' => []]), [
            'x-request-id' => 'req-abc',
        ]));

        $client = $this->makeClient($transport);

        $this->assertNull($client->getLastResponse());

        $client->get('/job/1');

        $lastResponse = $client->getLastResponse();
        $this->assertNotNull($lastResponse);
        $this->assertSame(200, $lastResponse->statusCode);
        $this->assertSame('req-abc', $lastResponse->headers['x-request-id']);
    }

    // ---------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------

    public function testSetCustomHeader(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url, array $headers) {
            $this->assertSame('custom-value', $headers['X-Custom-Header']);
            return $this->apiResponse([]);
        });

        $client = $this->makeClient($transport);
        $client->setCustomHeader('X-Custom-Header', 'custom-value');
        $client->get('/test');
    }

    public function testCustomBaseUrl(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
            $this->assertStringStartsWith('https://staging.servicetrade.com/api/', $url);
            return $this->apiResponse([]);
        });

        $this->makeClient($transport, ['baseUrl' => 'https://staging.servicetrade.com'])->get('/job/1');
    }

    public function testCustomApiPrefix(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
            $this->assertStringContainsString('/api/v2/job/1', $url);
            return $this->apiResponse([]);
        });

        $this->makeClient($transport, ['apiPrefix' => '/api/v2'])->get('/job/1');
    }

    public function testBuildUrlNormalizesSlashes(): void
    {
        $transport = $this->makeTransport(function (string $method, string $url) {
            // Should be normalized regardless of input slashes
            $this->assertStringContainsString('/api/job/1', $url);
            $this->assertStringNotContainsString('//job', $url);
            return $this->apiResponse([]);
        });

        // apiPrefix with trailing slash, path without leading slash
        $this->makeClient($transport, ['apiPrefix' => '/api/'])->get('job/1');
    }

    // ---------------------------------------------------------------
    // Callbacks
    // ---------------------------------------------------------------

    public function testOnSetAuthCallbackViaClient(): void
    {
        $callbackTokens = [];
        $tokenA = $this->makeJwt(time() + 3600);
        $tokenB = $this->makeJwt(time() + 7200);
        $tokenCall = 0;

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) use ($tokenA, $tokenB, &$tokenCall) {
                if (str_contains($url, '/oauth2/token')) {
                    $token = ++$tokenCall === 1 ? $tokenA : $tokenB;
                    return new Response(200, json_encode([
                        'access_token' => $token,
                        'expires_in' => 86400,
                        'token_type' => 'Bearer',
                    ]));
                }
                return new Response(200, '');
            });

        $client = $this->makeClient($transport, [
            'onSetAuth' => function (string $token) use (&$callbackTokens) {
                $callbackTokens[] = $token;
            },
        ]);

        $client->connect();
        $this->assertSame([$tokenA], $callbackTokens);

        $client->disconnect();
        $client->connect();
        $this->assertSame([$tokenA, $tokenB], $callbackTokens);
    }

    public function testOnUnsetAuthCallbackViaClient(): void
    {
        $disconnectCalled = false;

        $transport = $this->makeTransport(fn() => new Response(200, ''));

        $client = $this->makeClient($transport, [
            'onUnsetAuth' => function () use (&$disconnectCalled) {
                $disconnectCalled = true;
            },
        ]);

        $client->connect();
        $client->disconnect();

        $this->assertTrue($disconnectCalled);
    }

    // ---------------------------------------------------------------
    // Misc
    // ---------------------------------------------------------------

    public function testVersionReturnsString(): void
    {
        $version = Client::version();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }
}
