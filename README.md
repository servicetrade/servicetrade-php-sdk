# ServiceTrade PHP SDK

Official PHP SDK for the [ServiceTrade API](https://api.servicetrade.com/api/docs).

## Requirements

- PHP 8.1 or later
- ext-curl
- ext-json

## Installation

```bash
composer require servicetrade/servicetrade
```

## Quick Start

```php
use ServiceTrade\Client;

$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
);

// List jobs
$data = $client->get('/job', ['page' => 1]);
$jobs = $data['jobs'];

// Create a job
$job = $client->post('/job', [
    'type' => 'inspection',
    'description' => 'Quarterly HVAC Inspection',
    'locationId' => 123,
    'vendorId' => 456,
]);

// Update a job
$client->put('/job/' . $job['id'], ['description' => 'Updated Description']);
```

Authentication happens automatically on the first API call. The SDK obtains an OAuth2 token, attaches it as a `Bearer` header, and refreshes it before it expires.

## Authentication

The SDK supports three authentication modes. You must provide exactly one set of credentials.

### Client Credentials

Use `clientId` and `clientSecret` for server-to-server integrations. This is the recommended approach for most use cases.

```php
$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
);
```

The SDK exchanges these for a bearer token via `POST /api/oauth2/token` with `grant_type=client_credentials`.

### Refresh Token

Use a refresh token for long-lived sessions where you already have a token from a previous authentication flow.

```php
$client = new Client(
    refreshToken: 'your-refresh-token',
);
```

The SDK exchanges the refresh token for a bearer token via `POST /api/oauth2/token` with `grant_type=refresh_token`. If the server returns a new refresh token (token rotation), the SDK stores it automatically for subsequent refreshes.

### Pre-existing Bearer Token

If you already have a valid bearer token (e.g., obtained from another service), you can pass it directly.

```php
$client = new Client(
    token: 'your-bearer-token',
);
```

In this mode, no token endpoint calls are made. The token is used as-is. If it expires, the SDK cannot refresh it -- API calls will throw an `ApiException` with status 401.

### Lazy Authentication

The SDK does not authenticate during construction. The first API call triggers authentication automatically. If you need to authenticate eagerly (e.g., to fail fast on bad credentials), call `connect()`:

```php
$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
);

$client->connect(); // Connects immediately; throws on failure
```

## API Methods

All methods take API paths relative to the API prefix (default: `/api`). Responses return the `data` field from the JSON response body, or `null` if absent.

### GET

```php
// Get a single job
$job = $client->get('/job/123');

// List jobs with query parameters
$data = $client->get('/job', ['status' => 'scheduled', 'locationId' => 456]);
$jobs = $data['jobs'];

// List locations for a company
$data = $client->get('/location', ['companyId' => 789]);
$locations = $data['locations'];
```

**Signature:** `get(string $path, array $query = []): ?array`

### POST

```php
// Create a job
$job = $client->post('/job', [
    'type' => 'inspection',
    'description' => 'Annual Fire Alarm Inspection',
    'locationId' => 456,
]);

// Create an appointment on a job
$appointment = $client->post('/appointment', [
    'jobId' => $job['id'],
    'windowStart' => 1773576000,
    'windowEnd' => 1773590400,
    'techIds' => [101],
]);
```

**Signature:** `post(string $path, array $params, array $query = []): ?array`

The `$params` array is JSON-encoded and sent with `Content-Type: application/json`.

### PUT

```php
$updated = $client->put('/job/123', ['description' => 'Updated Inspection Description']);
```

**Signature:** `put(string $path, array $params, array $query = []): ?array`

### DELETE

```php
$client->delete('/location/456');
```

**Signature:** `delete(string $path, array $query = []): void`

Throws `ApiException` on failure. Returns nothing on success.

### File Upload

```php
$attachment = $client->attach('/path/to/document.pdf', [
    'entityType' => 3,
    'entityId' => 123,
    'purposeId' => 7,
]);
```

**Signature:** `attach(string $filePath, array $params): ?array`

The file is sent as a multipart form upload to `/attachment`. The `$params` array is included as additional form fields. Throws `InvalidArgumentException` if the file does not exist or is not readable.

## Configuration

All options are passed to the `Client` constructor using named arguments:

```php
$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    baseUrl: 'https://api.servicetrade.com',
);
```

### Credentials

| Option         | Type      | Default | Description                                                          |
| :------------- | :-------- | :------ | :------------------------------------------------------------------- |
| `clientId`     | `?string` | `null`  | OAuth2 client ID. Used with `clientSecret` for `client_credentials`. |
| `clientSecret` | `?string` | `null`  | OAuth2 client secret. Used with `clientId` for `client_credentials`. |
| `refreshToken` | `?string` | `null`  | OAuth2 refresh token for `refresh_token` grant.                      |
| `token`        | `?string` | `null`  | Pre-existing bearer token. No refresh capability.                    |

Credential precedence when multiple are provided: `clientId`/`clientSecret` > `refreshToken` > `token`.

### Connection

| Option           | Type     | Default                        | Description                                                         |
| :--------------- | :------- | :----------------------------- | :------------------------------------------------------------------ |
| `baseUrl`        | `string` | `https://api.servicetrade.com` | Base URL of the API server. Do not include the `/api` prefix.       |
| `apiPrefix`      | `string` | `/api`                         | Path prefix appended to `baseUrl` for all requests.                 |
| `userAgent`      | `?string` | auto-detected from Composer   | Value of the `User-Agent` header. Defaults to `ServiceTrade PHP SDK/{version}` using the installed package version. |

### Behavior

| Option            | Type   | Default | Description                                                                                                                                                |
| :---------------- | :----- | :------ | :--------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `autoRefreshAuth` | `bool` | `true`  | When enabled, the SDK proactively refreshes tokens before they expire and retries once on 401 responses. Set to `false` to manage token lifecycle yourself. |

### Callbacks

| Option         | Type       | Default | Description                                                          |
| :------------- | :--------- | :------ | :------------------------------------------------------------------- |
| `onSetAuth`    | `?Closure` | `null`  | Called with the bearer token string whenever a token is obtained.    |
| `onUnsetAuth`  | `?Closure` | `null`  | Called with no arguments when auth is cleared (on `disconnect()`).   |

## Token Refresh

When `autoRefreshAuth` is enabled (the default), the SDK handles token lifecycle automatically:

1. **Proactive refresh** -- Before each API call, the SDK parses the JWT `exp` claim. If the token expires within 5 minutes, it refreshes before sending the request.

2. **Reactive retry** -- If an API call returns HTTP 401, the SDK refreshes the token and retries the request once.

3. **Token rotation** -- If the token endpoint returns a new `refresh_token` in its response, the SDK stores it and uses it for subsequent refreshes. This happens transparently regardless of which grant type was used initially.

When `autoRefreshAuth` is `false`, none of the above happens. You are responsible for detecting expired tokens and calling `connect()` to re-authenticate.

## Token Persistence

By default, tokens live only in memory for the duration of the PHP process. To persist tokens across requests (e.g., in a web application), use the `onSetAuth` callback:

```php
$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    onSetAuth: function (string $token): void {
        // Store the token in your cache, session, or database
        $cache->set('servicetrade_token', $token, ttl: 86400);
    },
    onUnsetAuth: function (): void {
        $cache->delete('servicetrade_token');
    },
);
```

To reuse a previously stored token, pass it as `token` on subsequent requests:

```php
$cachedToken = $cache->get('servicetrade_token');

$client = new Client(
    token: $cachedToken,
);
```

Note that `token`-only mode cannot refresh. If the cached token has expired, API calls will fail with a 401. For automatic refresh capability, use `clientId`/`clientSecret` or `refreshToken` instead.

## Error Handling

All errors throw exceptions that extend `ServiceTrade\Exception\ServiceTradeException`, which itself extends `\RuntimeException`.

### Exception Types

**`AuthenticationException`** -- Thrown when authentication fails: invalid credentials, missing credentials, or inability to refresh a token.

```php
use ServiceTrade\Exception\AuthenticationException;

try {
    $client->connect();
} catch (AuthenticationException $e) {
    echo $e->getMessage(); // "OAuth2 token request failed: invalid_client"
}
```

**`ApiException`** -- Thrown when the API returns a non-2xx response. Carries the HTTP status code, error messages, and validation errors from the response body.

```php
use ServiceTrade\Exception\ApiException;

try {
    $client->post('/job', ['description' => 'Missing required fields']);
} catch (ApiException $e) {
    echo $e->statusCode;              // 400
    echo $e->getMessage();            // "Location is required; Type is required"
    print_r($e->messages);            // ['Location is required', 'Type is required']
    print_r($e->validation);          // ['locationId' => 'Location is required']
    echo $e->responseBody;            // Raw JSON response body for debugging
}
```

**`TransportException`** -- Thrown when the HTTP request itself fails (DNS resolution, connection timeout, SSL error, etc.). Carries the cURL error code.

```php
use ServiceTrade\Exception\TransportException;

try {
    $client->get('/job');
} catch (TransportException $e) {
    echo $e->curlErrorCode;           // 28 (CURLE_OPERATION_TIMEDOUT)
    echo $e->getMessage();            // "cURL error: Connection timed out after 60001 milliseconds"
}
```

### Catching All SDK Errors

```php
use ServiceTrade\Exception\ServiceTradeException;

try {
    $client->get('/job');
} catch (ServiceTradeException $e) {
    // Handles AuthenticationException, ApiException, and TransportException
    log_error($e->getMessage());
}
```

## Disconnect

Call `disconnect()` to clear the current token. If a refresh token is present, the SDK attempts to revoke it via `POST /api/oauth2/revoke`. Revocation errors are silently ignored.

```php
$client->disconnect();
```

## Pointing to a Different Environment

```php
$client = new Client(
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret',
    baseUrl: 'https://some-other-environment.servicetrade.com',
);
```

The `baseUrl` should not include the `/api` prefix -- that is handled by `apiPrefix`.

## Response Headers

After any API call, you can inspect the response headers via `getLastResponse()`:

```php
$client->get('/job');

$response = $client->getLastResponse();
$requestId = $response->headers['x-request-id'] ?? null;
```

Header names are normalized to lowercase.

## Pagination

For list endpoints that return paginated results, use the `Paginator` helper to iterate through all pages automatically:

```php
use ServiceTrade\Paginator;

$paginator = new Paginator($client, '/job', 'jobs', ['status' => 'scheduled']);

foreach ($paginator as $job) {
    echo $job['description'] . "\n";
}
```

The constructor takes the client, the endpoint path, the key within the response `data` object that holds the items array, and optional query parameters. The paginator reads `totalPages` from each response and advances the `page` parameter automatically.

You can also collect all results into an array:

```php
$allJobs = iterator_to_array(new Paginator($client, '/job', 'jobs'));
```

## Custom Headers

```php
$client->setCustomHeader('X-Request-Id', 'abc-123');
```

Custom headers are included in all subsequent API requests.


## License

MIT -- see [LICENSE](LICENSE).
