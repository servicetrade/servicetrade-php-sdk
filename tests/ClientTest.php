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

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

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

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $client->connect();
        $this->assertSame($this->testToken, $client->getAuthToken());

        $client->get('/job/1');
    }

    public function testGetSendsBearerHeader(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
                return $this->apiResponse(['id' => 42]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->get('/job/42');

        $this->assertSame(['id' => 42], $result);
    }

    public function testGetWithQueryParams(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('GET', $method);
                $this->assertStringContainsString('page=1', $url);
                $this->assertStringContainsString('limit=25', $url);
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->get('/jobs', ['page' => 1, 'limit' => 25]);
    }

    public function testPost(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('POST', $method);
                $this->assertStringContainsString('/api/job', $url);
                $this->assertSame('application/json', $headers['Content-Type']);
                $decoded = json_decode($body, true);
                $this->assertSame('New Job', $decoded['name']);

                return $this->apiResponse(['id' => 99, 'name' => 'New Job']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->post('/job', ['name' => 'New Job']);

        $this->assertSame(99, $result['id']);
    }

    public function testPut(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('PUT', $method);
                return $this->apiResponse(['id' => 1, 'name' => 'Updated']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->put('/job/1', ['name' => 'Updated']);

        $this->assertSame('Updated', $result['name']);
    }

    public function testDelete(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('DELETE', $method);
                return new Response(204, '');
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->delete('/job/1'); // Should not throw
    }

    public function testDeleteThrowsOnError(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->errorResponse(404, ['Not found']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);
        $client->delete('/job/999');
    }

    public function test401TriggersRefreshAndRetry(): void
    {
        $callLog = [];

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers) use (&$callLog) {
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

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: true,
            transport: $transport,
        );
        $result = $client->get('/job/1');

        $this->assertSame(['id' => 1], $result);
        // Should have: connect token, api 401, refresh token, api success
        $this->assertSame(['token', 'api', 'token', 'api'], $callLog);
    }

    public function test401WithNoRefreshThrows(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->errorResponse(401, ['Token expired']);
            });

        $client = new Client(
            token: 'pre_existing',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $client->get('/job/1');
    }

    public function test401AfterRefreshThrowsAuthenticationException(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                // Always returns 401, even after refresh
                return $this->errorResponse(401, ['Unauthorized']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: true,
            transport: $transport,
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Authentication failed after token refresh');
        $client->get('/job/1');
    }

    public function testApiExceptionOn4xx(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->errorResponse(403, ['Access denied']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        try {
            $client->get('/admin/secret');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertSame(['Access denied'], $e->messages);
        }
    }

    public function testAttachSendsMultipart(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sdk_test_');
        file_put_contents($tmpFile, 'test file content');

        try {
            $transport = $this->createMock(HttpTransportInterface::class);

            $transport->expects($this->exactly(2))
                ->method('send')
                ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body, array $options) {
                    if (str_contains($url, '/oauth2/token')) {
                        return $this->tokenResponse();
                    }

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

            $client = new Client(
                clientId: 'id',
                clientSecret: 'secret',
                autoRefreshAuth: false,
                transport: $transport,
            );
            $result = $client->attach($tmpFile, ['entityType' => 'job', 'entityId' => 1]);

            $this->assertSame(['id' => 55], $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testAttachThrowsOnMissingFile(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $client = new Client(
            token: 'tok',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');
        $client->attach('/nonexistent/file.pdf', ['entityType' => 'job']);
    }

    public function testAttach401RespectsAutoRefreshFlag(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sdk_test_');
        file_put_contents($tmpFile, 'content');

        try {
            $transport = $this->createMock(HttpTransportInterface::class);

            $transport->method('send')
                ->willReturnCallback(function (string $method, string $url) {
                    if (str_contains($url, '/oauth2/token')) {
                        return $this->tokenResponse();
                    }
                    return $this->errorResponse(401, ['Unauthorized']);
                });

            // With autoRefreshAuth=false, 401 from attach should throw, not retry
            $client = new Client(
                clientId: 'id',
                clientSecret: 'secret',
                autoRefreshAuth: false,
                transport: $transport,
            );

            $this->expectException(ApiException::class);
            $this->expectExceptionCode(401);
            $client->attach($tmpFile, ['entityType' => 'job']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testSetCustomHeader(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('custom-value', $headers['X-Custom-Header']);
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->setCustomHeader('X-Custom-Header', 'custom-value');
        $client->get('/test');
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

    public function testBuildUrlNormalizesSlashes(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                // Should be normalized regardless of input slashes
                $this->assertStringContainsString('/api/job/1', $url);
                $this->assertStringNotContainsString('//job', $url);
                return $this->apiResponse([]);
            });

        // apiPrefix with trailing slash, path without leading slash
        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            apiPrefix: '/api/',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->get('job/1');
    }

    public function testPostThrowsOnUnencodableData(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')->willReturn($this->tokenResponse());

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $this->expectException(\JsonException::class);
        $client->post('/test', ['bad' => "\xB1\x31"]);
    }

    public function testGetLastResponse(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return new Response(200, json_encode(['data' => ['id' => 1], 'messages' => []]), [
                    'x-request-id' => 'req-abc',
                ]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );

        $this->assertNull($client->getLastResponse());

        $client->get('/job/1');

        $lastResponse = $client->getLastResponse();
        $this->assertNotNull($lastResponse);
        $this->assertSame(200, $lastResponse->statusCode);
        $this->assertSame('req-abc', $lastResponse->headers['x-request-id']);
    }

    public function testPostWithQueryParams(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('POST', $method);
                $this->assertStringContainsString('notify=true', $url);
                $decoded = json_decode($body, true);
                $this->assertSame('New Job', $decoded['name']);
                return $this->apiResponse(['id' => 1, 'name' => 'New Job']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->post('/job', ['name' => 'New Job'], ['notify' => 'true']);

        $this->assertSame(1, $result['id']);
    }

    public function testPutWithQueryParams(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('PUT', $method);
                $this->assertStringContainsString('notify=true', $url);
                $decoded = json_decode($body, true);
                $this->assertSame('Updated', $decoded['name']);
                return $this->apiResponse(['id' => 1, 'name' => 'Updated']);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->put('/job/1', ['name' => 'Updated'], ['notify' => 'true']);

        $this->assertSame('Updated', $result['name']);
    }

    public function testDeleteWithQueryParams(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertSame('DELETE', $method);
                $this->assertStringContainsString('cascade=true', $url);
                return new Response(204, '');
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->delete('/job/1', ['cascade' => 'true']);
    }

    public function testEmptyResponseReturnsNull(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return new Response(200, json_encode(['messages' => []]));
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->get('/job/1');

        $this->assertNull($result);
    }

    public function testCustomBaseUrl(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertStringStartsWith('https://staging.servicetrade.com/api/', $url);
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            baseUrl: 'https://staging.servicetrade.com',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->get('/job/1');
    }

    public function testCustomApiPrefix(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }

                $this->assertStringContainsString('/api/v2/job/1', $url);
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            apiPrefix: '/api/v2',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $client->get('/job/1');
    }

    public function testCustomUserAgent(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            userAgent: 'MyApp/1.0',
            autoRefreshAuth: false,
            transport: $transport,
        );
        $result = $client->get('/test');

        $this->assertIsArray($result);
    }

    public function testOnSetAuthCallbackViaClient(): void
    {
        $callbackToken = null;

        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                return $this->apiResponse([]);
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            onSetAuth: function (string $token) use (&$callbackToken) {
                $callbackToken = $token;
            },
            transport: $transport,
        );

        $client->connect();

        $this->assertSame($this->testToken, $callbackToken);
    }

    public function testOnUnsetAuthCallbackViaClient(): void
    {
        $disconnectCalled = false;

        $transport = $this->createMock(HttpTransportInterface::class);

        $transport->method('send')
            ->willReturnCallback(function (string $method, string $url) {
                if (str_contains($url, '/oauth2/token')) {
                    return $this->tokenResponse();
                }
                // Revoke endpoint
                return new Response(200, '');
            });

        $client = new Client(
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
            onUnsetAuth: function () use (&$disconnectCalled) {
                $disconnectCalled = true;
            },
            transport: $transport,
        );

        $client->connect();
        $client->disconnect();

        $this->assertTrue($disconnectCalled);
    }

    public function testVersionReturnsString(): void
    {
        $version = Client::version();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }
}
