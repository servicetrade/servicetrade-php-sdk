<?php

declare(strict_types=1);

namespace ServiceTrade;

use ServiceTrade\Exception\AuthenticationException;

final class Authenticator
{
    private const TOKEN_TTL_BUFFER_SECONDS = 300; // 5 minutes

    private ?string $token = null;
    private readonly ?string $presetToken;
    private ?string $currentRefreshToken = null;
    private ?string $grantType = null;
    private bool $refreshing = false;

    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly string $baseApiUrl,
        private readonly ?string $clientId = null,
        #[\SensitiveParameter] private readonly ?string $clientSecret = null,
        #[\SensitiveParameter] ?string $refreshToken = null,
        #[\SensitiveParameter] ?string $token = null,
        private readonly bool $autoRefreshAuth = true,
        private readonly ?\Closure $onSetAuth = null,
        private readonly ?\Closure $onUnsetAuth = null,
    ) {
        $this->presetToken = $token;
        $this->currentRefreshToken = $refreshToken;
        $this->grantType = $this->determineGrantType($refreshToken, $token);
    }

    public function connect(): void
    {
        if ($this->grantType === 'token_only') {
            $this->token = $this->presetToken;
            $this->invokeOnSetAuth();
            return;
        }

        if ($this->grantType === null) {
            throw new AuthenticationException(
                'No valid credentials provided. Required: clientId/clientSecret, refreshToken, or token.'
            );
        }

        $this->performTokenRequest();
    }

    public function disconnect(): void
    {
        $this->attemptRevokeRefreshToken();
        $this->token = null;
        $this->currentRefreshToken = null;

        if ($this->onUnsetAuth !== null) {
            ($this->onUnsetAuth)();
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function refreshIfStale(): void
    {
        if (!$this->autoRefreshAuth) {
            return;
        }

        if ($this->grantType === 'token_only' || $this->grantType === null) {
            return;
        }

        $ttl = $this->getTokenTTL();
        if ($ttl < self::TOKEN_TTL_BUFFER_SECONDS) {
            $this->performTokenRequest();
        }
    }

    public function handleUnauthorized(): void
    {
        if ($this->grantType === 'token_only' || $this->grantType === null) {
            throw new AuthenticationException('Cannot refresh: no refreshable credentials available.');
        }

        $this->performTokenRequest();
    }

    private function determineGrantType(?string $refreshToken, ?string $token): ?string
    {
        if ($this->clientId !== null && $this->clientSecret !== null) {
            return 'client_credentials';
        }

        if ($refreshToken !== null) {
            return 'refresh_token';
        }

        if ($token !== null) {
            return 'token_only';
        }

        return null;
    }

    private function performTokenRequest(): void
    {
        // Guard against reentrancy: if onSetAuth callback triggers code that
        // calls back into the authenticator, this prevents infinite recursion.
        if ($this->refreshing) {
            return;
        }

        $this->refreshing = true;

        try {
            $body = $this->buildTokenRequestBody();

            $response = $this->postOAuth2('oauth2/token', $body);

            if (!$response->isSuccess()) {
                $decoded = $response->decodedBody();
                $message = $decoded['error_description'] ?? $decoded['error'] ?? 'Authentication failed';
                throw new AuthenticationException("OAuth2 token request failed: {$message}");
            }

            $data = $response->decodedBody();
            if ($data === null || !isset($data['access_token'])) {
                throw new AuthenticationException('OAuth2 token response missing access_token');
            }

            $this->token = $data['access_token'];

            // Token rotation: store new refresh token if provided
            if (isset($data['refresh_token'])) {
                $this->currentRefreshToken = $data['refresh_token'];
                $this->grantType = 'refresh_token';
            }

            $this->invokeOnSetAuth();
        } finally {
            $this->refreshing = false;
        }
    }

    private function buildTokenRequestBody(): array
    {
        // If we have a current refresh token (from rotation or initial config), use it
        if ($this->currentRefreshToken !== null && $this->grantType === 'refresh_token') {
            $body = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->currentRefreshToken,
            ];
            if ($this->clientId !== null) {
                $body['client_id'] = $this->clientId;
            }
            return $body;
        }

        // Client credentials
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    private function attemptRevokeRefreshToken(): void
    {
        if ($this->currentRefreshToken === null) {
            return;
        }

        try {
            $body = ['refresh_token' => $this->currentRefreshToken];
            if ($this->clientId !== null) {
                $body['client_id'] = $this->clientId;
            }
            if ($this->clientSecret !== null) {
                $body['client_secret'] = $this->clientSecret;
            }

            $this->postOAuth2('oauth2/revoke', $body);
        } catch (\Throwable) {
            // Swallow revocation errors, matching Node SDK behavior
        }
    }

    private function postOAuth2(string $endpoint, #[\SensitiveParameter] array $body): Response
    {
        $url = $this->baseApiUrl . '/' . $endpoint;

        return $this->transport->send(
            'POST',
            $url,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($body),
        );
    }

    private function getTokenTTL(): int
    {
        if ($this->token === null) {
            return 0;
        }

        $parts = explode('.', $this->token);
        if (count($parts) !== 3) {
            return 0;
        }

        $payload = $parts[1];
        $payload = str_replace(['-', '_'], ['+', '/'], $payload);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return 0;
        }

        $parsed = json_decode($decoded, true);
        if (!is_array($parsed) || !isset($parsed['exp'])) {
            return 0;
        }

        return (int) $parsed['exp'] - time();
    }

    private function invokeOnSetAuth(): void
    {
        if ($this->onSetAuth !== null && $this->token !== null) {
            ($this->onSetAuth)($this->token);
        }
    }
}
