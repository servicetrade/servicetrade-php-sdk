<?php

declare(strict_types=1);

namespace ServiceTrade;

use Composer\InstalledVersions;
use ServiceTrade\Exception\ApiException;
use ServiceTrade\Exception\AuthenticationException;

final class Client
{
    private readonly Authenticator $authenticator;
    private readonly HttpTransportInterface $transport;
    private readonly string $baseApiUrl;
    private readonly bool $autoRefreshAuth;
    private array $customHeaders = [];
    private ?Response $lastResponse = null;

    public function __construct(
        // OAuth2 credentials
        ?string $clientId = null,
        #[\SensitiveParameter] ?string $clientSecret = null,
        #[\SensitiveParameter] ?string $refreshToken = null,
        #[\SensitiveParameter] ?string $token = null,

        // Connection
        string $baseUrl = 'https://api.servicetrade.com',
        string $apiPrefix = '/api',
        ?string $userAgent = null,

        // Behavior
        bool $autoRefreshAuth = true,

        // Callbacks
        ?\Closure $onSetAuth = null,
        ?\Closure $onUnsetAuth = null,

        // Internal: injectable for testing only. Production always uses cURL.
        ?HttpTransportInterface $transport = null,
    ) {
        $this->baseApiUrl = rtrim($baseUrl, '/') . '/' . trim($apiPrefix, '/');
        $this->autoRefreshAuth = $autoRefreshAuth;
        $userAgent ??= 'ServiceTrade PHP SDK/' . self::version();
        $this->transport = $transport ?? new CurlHttpTransport(
            userAgent: $userAgent,
        );
        $this->authenticator = new Authenticator(
            transport: $this->transport,
            baseApiUrl: $this->baseApiUrl,
            clientId: $clientId,
            clientSecret: $clientSecret,
            refreshToken: $refreshToken,
            token: $token,
            autoRefreshAuth: $autoRefreshAuth,
            onSetAuth: $onSetAuth,
            onUnsetAuth: $onUnsetAuth,
        );
    }

    public function connect(): void
    {
        $this->authenticator->connect();
    }

    public function disconnect(): void
    {
        $this->authenticator->disconnect();
    }

    public function getAuthToken(): ?string
    {
        return $this->authenticator->getToken();
    }

    public function get(string $path, array $query = []): ?array
    {
        $response = $this->request('GET', $path, query: $query);
        return $this->extractData($response);
    }

    public function post(string $path, array $params, array $query = []): ?array
    {
        $response = $this->request('POST', $path, json_encode($params, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), $query);
        return $this->extractData($response);
    }

    public function put(string $path, array $params, array $query = []): ?array
    {
        $response = $this->request('PUT', $path, json_encode($params, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), $query);
        return $this->extractData($response);
    }

    public function delete(string $path, array $query = []): void
    {
        $this->request('DELETE', $path, query: $query);
    }

    public function attach(string $filePath, array $params): ?array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File does not exist or is not readable: {$filePath}");
        }

        $multipart = $params;
        $multipart['uploadedFile'] = new \CURLFile($filePath);

        $response = $this->request('POST', '/attachment', options: ['multipart' => $multipart]);
        return $this->extractData($response);
    }

    public function setCustomHeader(string $key, string $value): void
    {
        $this->customHeaders[$key] = $value;
    }

    public function getLastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    private function request(
        string $method,
        string $path,
        ?string $body = null,
        array $query = [],
        array $options = [],
    ): Response {
        $this->ensureAuthenticated();
        $this->authenticator->refreshIfStale();

        $url = $this->buildUrl($path, $query);
        $headers = $this->buildRequestHeaders($options);

        $response = $this->transport->send($method, $url, $headers, $body, $options);

        if ($response->statusCode === 401 && $this->autoRefreshAuth) {
            $this->authenticator->handleUnauthorized();
            $headers = $this->buildRequestHeaders($options);
            $response = $this->transport->send($method, $url, $headers, $body, $options);

            if ($response->statusCode === 401) {
                throw new AuthenticationException(
                    'Authentication failed after token refresh. Credentials may be revoked.'
                );
            }
        }

        $this->lastResponse = $response;
        $this->throwOnError($response);
        return $response;
    }

    private function ensureAuthenticated(): void
    {
        if ($this->authenticator->getToken() === null) {
            $this->authenticator->connect();
        }
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseApiUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function buildRequestHeaders(array $options): array
    {
        $headers = [];

        if (!isset($options['multipart'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $token = $this->authenticator->getToken();
        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        foreach ($this->customHeaders as $key => $value) {
            $headers[$key] = $value;
        }

        return $headers;
    }

    private function extractData(Response $response): ?array
    {
        $decoded = $response->decodedBody();
        return $decoded['data'] ?? null;
    }

    private function throwOnError(Response $response): void
    {
        if ($response->isSuccess()) {
            return;
        }

        $decoded = $response->decodedBody();
        $messages = $decoded['messages'] ?? [];
        $errorMessages = $messages['error'] ?? [];
        $validation = $messages['validation'] ?? [];

        throw new ApiException(
            $response->statusCode,
            $errorMessages,
            $validation,
            $response->body,
        );
    }

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class)) {
            return InstalledVersions::getPrettyVersion('servicetrade/servicetrade') ?? 'unknown';
        }
        return 'unknown';
    }
}
