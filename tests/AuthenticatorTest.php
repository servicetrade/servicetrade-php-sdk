<?php

declare(strict_types=1);

namespace ServiceTrade\Tests;

use PHPUnit\Framework\TestCase;
use ServiceTrade\Authenticator;
use ServiceTrade\Exception\AuthenticationException;
use ServiceTrade\HttpTransportInterface;
use ServiceTrade\Response;

final class AuthenticatorTest extends TestCase
{
    private const BASE_API_URL = 'https://api.servicetrade.com/api';

    private function makeJwt(int $exp): string
    {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['exp' => $exp, 'sub' => 'test']));
        $signature = base64_encode('fake-signature');

        return "{$header}.{$payload}.{$signature}";
    }

    private function tokenResponse(string $accessToken, ?string $refreshToken = null): Response
    {
        $data = ['access_token' => $accessToken, 'expires_in' => 86400, 'token_type' => 'Bearer'];
        if ($refreshToken !== null) {
            $data['refresh_token'] = $refreshToken;
        }

        return new Response(200, json_encode($data));
    }

    public function testClientCredentialsConnect(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://api.servicetrade.com/api/oauth2/token',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $this->callback(function (string $body): bool {
                    parse_str($body, $params);
                    return $params['grant_type'] === 'client_credentials'
                        && $params['client_id'] === 'my-id'
                        && $params['client_secret'] === 'my-secret';
                }),
            )
            ->willReturn($this->tokenResponse('access_token_123'));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'my-id',
            clientSecret: 'my-secret',
        );
        $auth->connect();

        $this->assertSame('access_token_123', $auth->getToken());
    }

    public function testRefreshTokenConnect(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                $this->anything(),
                $this->anything(),
                $this->callback(function (string $body): bool {
                    parse_str($body, $params);
                    return $params['grant_type'] === 'refresh_token'
                        && $params['refresh_token'] === 'rt_abc';
                }),
            )
            ->willReturn($this->tokenResponse('access_from_refresh'));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            refreshToken: 'rt_abc',
        );
        $auth->connect();

        $this->assertSame('access_from_refresh', $auth->getToken());
    }

    public function testTokenOnlyMode(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->never())->method('send');

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            token: 'pre_existing_token',
        );
        $auth->connect();

        $this->assertSame('pre_existing_token', $auth->getToken());
    }

    public function testTokenOnlyCannotRefresh(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            token: 'pre_existing_token',
        );
        $auth->connect();

        $this->expectException(AuthenticationException::class);
        $auth->handleUnauthorized();
    }

    public function testMissingCredentialsThrows(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No valid credentials');
        $auth->connect();
    }

    public function testProactiveRefresh(): void
    {
        // Token that expires in 2 minutes (below 5 min buffer)
        $soonToken = $this->makeJwt(time() + 120);

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturn(
                $this->tokenResponse($soonToken),
                $this->tokenResponse('refreshed_token'),
            );

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect(); // First call — gets soon-expiring token
        $auth->refreshIfStale(); // Should trigger refresh

        $this->assertSame('refreshed_token', $auth->getToken());
    }

    public function testNoRefreshWhenTokenFresh(): void
    {
        // Token that expires in 1 hour
        $freshToken = $this->makeJwt(time() + 3600);

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once()) // Only the initial connect
            ->method('send')
            ->willReturn($this->tokenResponse($freshToken));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect();
        $auth->refreshIfStale(); // Should NOT trigger refresh

        $this->assertSame($freshToken, $auth->getToken());
    }

    public function testTokenRotation(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    // First call: client_credentials returns access + refresh token
                    return $this->tokenResponse('token_v1', 'new_rt_from_server');
                }

                // Second call: should use the new refresh token
                parse_str($body, $params);
                $this->assertSame('refresh_token', $params['grant_type']);
                $this->assertSame('new_rt_from_server', $params['refresh_token']);

                return $this->tokenResponse('token_v2');
            });

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect(); // Gets token_v1 + new refresh token
        $auth->handleUnauthorized(); // Should use new_rt_from_server

        $this->assertSame('token_v2', $auth->getToken());
    }

    public function testDisconnectRevokesToken(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $method, string $url, array $headers, ?string $body) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    // Connect
                    return $this->tokenResponse('token', 'rt_to_revoke');
                }

                // Revocation call
                $this->assertStringContainsString('/oauth2/revoke', $url);
                parse_str($body, $params);
                $this->assertSame('rt_to_revoke', $params['refresh_token']);

                return new Response(200, '{}');
            });

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect();
        $auth->disconnect();

        $this->assertNull($auth->getToken());
    }

    public function testDisconnectSwallowsRevocationError(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    return $this->tokenResponse('token', 'rt_123');
                }

                // Revocation throws
                throw new \RuntimeException('Network error');
            });

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect();

        // Should not throw
        $auth->disconnect();
        $this->assertNull($auth->getToken());
    }

    public function testOnSetAuthCallback(): void
    {
        $capturedToken = null;
        $onSet = function (string $token) use (&$capturedToken) {
            $capturedToken = $token;
        };

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')->willReturn($this->tokenResponse('callback_token'));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
            onSetAuth: $onSet,
        );
        $auth->connect();

        $this->assertSame('callback_token', $capturedToken);
    }

    public function testOnUnsetAuthCallback(): void
    {
        $unsetCalled = false;
        $onUnset = function () use (&$unsetCalled) {
            $unsetCalled = true;
        };

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->method('send')->willReturn($this->tokenResponse('token'));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
            onUnsetAuth: $onUnset,
        );
        $auth->connect();
        $auth->disconnect();

        $this->assertTrue($unsetCalled);
    }

    public function testAutoRefreshDisabled(): void
    {
        $soonToken = $this->makeJwt(time() + 120);

        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once()) // Only connect, no refresh
            ->method('send')
            ->willReturn($this->tokenResponse($soonToken));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
            autoRefreshAuth: false,
        );
        $auth->connect();
        $auth->refreshIfStale(); // Should be a no-op

        $this->assertSame($soonToken, $auth->getToken());
    }

    public function testOpaqueTokenTriggersProactiveRefresh(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturn(
                $this->tokenResponse('not-a-jwt'),
                $this->tokenResponse('refreshed_token'),
            );

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );
        $auth->connect();

        // Non-JWT token is treated as stale, so refreshIfStale triggers refresh
        $auth->refreshIfStale();
        $this->assertSame('refreshed_token', $auth->getToken());
    }

    public function testTokenEndpointFailureThrows(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(401, json_encode([
                'error' => 'invalid_client',
                'error_description' => 'Bad credentials',
            ])));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'wrong',
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('OAuth2 token request failed: Bad credentials');
        $auth->connect();
    }

    public function testTokenResponseMissingAccessTokenThrows(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, json_encode(['token_type' => 'Bearer'])));

        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('missing access_token');
        $auth->connect();
    }

    public function testReentrancyGuardPreventsInfiniteRecursion(): void
    {
        $transport = $this->createMock(HttpTransportInterface::class);
        $transport->expects($this->once()) // Only one token request despite callback re-entering connect
            ->method('send')
            ->willReturn($this->tokenResponse('token_123'));

        $auth = null;
        $auth = new Authenticator(
            transport: $transport,
            baseApiUrl: self::BASE_API_URL,
            clientId: 'id',
            clientSecret: 'secret',
            onSetAuth: function (string $token) use (&$auth) {
                // Simulate a callback that tries to refresh again
                $auth->refreshIfStale();
            },
        );
        $auth->connect();

        $this->assertSame('token_123', $auth->getToken());
    }
}
