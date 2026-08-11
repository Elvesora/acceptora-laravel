# Acceptora Verification for Laravel

`acceptora/verification-laravel` is a standalone Laravel client for the Acceptora Verification API v1 and its MCP server. It provides typed REST and MCP results, Laravel package discovery, a facade, compatibility checks, and named methods for the complete eight-operation verification workflow. It is a client library; an Acceptora account, project, compatible server origin, and project-scoped credential are still required.

A passing completion gate proves that the submitted source is synchronized with current Acceptora verification state. It does not represent human review or acceptance.

## Requirements

- Laravel 12 with PHP 8.2, 8.3, 8.4, or 8.5
- Laravel 13 with PHP 8.3, 8.4, or 8.5
- Composer 2

## Installation

Install the package with Composer:

```bash
composer require acceptora/verification-laravel
```

Laravel package discovery registers `Acceptora\Verification\Laravel\VerificationServiceProvider` automatically.

Publish the configuration when application-level overrides are needed:

```bash
php artisan vendor:publish --tag=acceptora-verification-config
```

Set the project-scoped bearer token in the deployment environment:

```dotenv
ACCEPTORA_AGENT_TOKEN=avt_your_one_time_token
```

The value above is deliberately non-functional. Do not commit a real token to source control. Store production credentials in the deployment platform's secret store.

## Configuration

The published `config/acceptora-verification.php` file exposes these settings:

| Environment variable | Configuration key | Default | Purpose |
| --- | --- | --- | --- |
| `ACCEPTORA_VERIFICATION_BASE_URL` | `base_url` | `https://www.acceptora.com` | Acceptora server origin |
| `ACCEPTORA_AGENT_TOKEN` | `token` | `null` | Project-scoped bearer token for protected requests |
| `ACCEPTORA_VERIFICATION_TIMEOUT` | `timeout` | `15` | HTTP request and streamed-body read timeout in seconds |
| `ACCEPTORA_VERIFICATION_CONNECT_TIMEOUT` | `connect_timeout` | `5` | Connection timeout in seconds |
| `ACCEPTORA_VERIFICATION_MAX_REQUEST_BYTES` | `max_request_bytes` | `2000000` | Maximum outbound JSON request size |
| `ACCEPTORA_VERIFICATION_MAX_RESPONSE_BYTES` | `max_response_bytes` | `4000000` | Maximum accepted response size |
| `ACCEPTORA_VERIFICATION_MAX_TOOL_PAGES` | `max_tool_pages` | `20` | Maximum pages followed by paginated tool reads |
| `ACCEPTORA_VERIFICATION_USER_AGENT` | `user_agent` | `Acceptora-Verification-Laravel/1.0.0` | Outbound HTTP user agent |

Read values through Laravel configuration, for example `config('acceptora-verification.timeout')`. Set `base_url` to the canonical origin shown in the Acceptora project connection settings, without appending `/api`, `/mcp`, or another path. Keep it on HTTPS in deployed environments.

## Acceptora account and project setup

1. Sign in at [www.acceptora.com](https://www.acceptora.com) and create a project.
2. Open the project and select **Settings > Credentials**. Create a credential with a descriptive name and an appropriate expiry. Creating a credential requires the current Acceptora password; accounts created through Google sign-in must set that password first when prompted by Acceptora.
3. Select only the capabilities the integration needs. The standard workflow uses `projects:read`, `features:resolve`, `features:read`, `checklists:write`, `feedback:read`, `feedback:address`, and `gates:read`. Grant the optional `exceptions:write` capability only when the integration must record source-bound verification exceptions.
4. Copy the bearer token immediately. Acceptora shows the secret once and cannot recover it after the page is left or reloaded.
5. Open **Settings > Connection**. Copy the canonical origin into `ACCEPTORA_VERIFICATION_BASE_URL`; the project ID, REST base URL, OpenAPI URL, and MCP endpoint shown there identify the same project connection. Put the token in `ACCEPTORA_AGENT_TOKEN`.
6. Run the public readiness and contract checks, then a credential-bound check for the transport you intend to use. The examples below distinguish MCP compatibility from REST availability.

Tokens are project-scoped. Revoking the credential immediately denies subsequent calls made with that token. Never put the token in browser code, a URL, a request payload, logs, or exception context.

## Provider, facade, and dependency injection

Applications that disable Composer package discovery can add the provider to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    Acceptora\Verification\Laravel\VerificationServiceProvider::class,
];
```

Import the facade when a concise call site is useful:

```php
use Acceptora\Verification\Laravel\Facades\Verification;

$health = Verification::health();
$contract = Verification::contractInfo();
```

For application services, inject the contract:

```php
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;

final class VerifyRelease
{
    public function __construct(
        private readonly VerificationClientInterface $verification,
    ) {}

    public function handle(): void
    {
        $this->verification->preflight();
    }
}
```

The service provider binds `VerificationClientInterface` and `Acceptora\Verification\Laravel\Client` as the same scoped instance for one Laravel request or queue-job lifecycle. Laravel flushes it for each new Octane request or long-lived worker job, preventing MCP session and request-ID state from leaking across lifecycles.

## Usage examples and client methods

Every method accepts an optional final `$requestId` argument. Supply a non-secret correlation value when requests need to be traced across application and Acceptora logs.

### HTTP and MCP transport

| Method | Remote operation | Return type |
| --- | --- | --- |
| `health()` | `GET /api/health` | `HealthResult` |
| `contractInfo()` | `GET /api/contract-version` | `ContractInfoResult` |
| `openApiDocument()` | `GET /api/v1/integrations/openapi.json` | `array<string, mixed>` |
| `projectMetadata()` | `GET /api/v1/integrations/project` | `ProjectMetadataResult` |
| `callRestOperation($operation, $payload)` | Canonical `/api/v1/integrations/*` route | `RestOperationResult` |
| `checkCompletionGate($payload)` | Legacy `POST /api/integrations/completion-gate` hook | `CompletionGateResult` |
| `listTools()` | MCP `tools/list` through `POST /mcp` | list of `ToolDefinition` |
| `callTool($tool, $arguments)` | MCP `tools/call` through `POST /mcp` | `ToolResult` |
| `preflight()` | Readiness and v1 compatibility verification | `CompatibilityResult` |

`openApiDocument()` returns the public OpenAPI 3.1 document without sending bearer authentication. `checkCompletionGate()` accepts the v1 `check_completion_gate` input payload. Protected HTTP and MCP operations require the configured bearer token and the corresponding project capability.

### Verify the transport offered by your server

The package exposes both transports, but installing the package does not prove that a particular Acceptora server origin offers both:

- `health()` and `contractInfo()` check the public server readiness and advertised versions.
- `preflight()` verifies readiness, version compatibility, MCP initialization, tool names, read-only annotations, and MCP input/output schema digests. It does not call the REST OpenAPI or project-metadata endpoints, so a passing MCP preflight does not prove REST availability.
- REST requires `openApiDocument()` to return an OpenAPI 3.1 document and `projectMetadata()` to succeed with a credential carrying `projects:read`. A `404` from the OpenAPI path means that the configured server does not offer the versioned REST surface; do not infer support from this package's methods.

Use the server-provided schemas as the request authority. For REST, use `openApiDocument()` and its `paths` and `components.schemas` objects. For MCP, `listTools()` returns every tool's `inputSchema`, `outputSchema`, and annotations. The bundled [`resources/contract-manifest.json`](resources/contract-manifest.json) pins endpoint names, capabilities, versions, and schema digests for compatibility checks; it is not a complete payload schema or evidence that an endpoint is currently available.

```php
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;

$client = app(VerificationClientInterface::class);

$health = $client->health();
$contract = $client->contractInfo();

// MCP compatibility, including exact tool-schema digests.
$mcpCompatibility = $client->preflight();

// REST availability and its authoritative schemas.
$openApi = $client->openApiDocument();
$project = $client->projectMetadata();
```

### Named REST v1 operations

Use REST for queue workers, CI systems, or agents that can send JSON over HTTPS but do not support MCP. The named REST methods add the package version object when it is absent and return the same operation-specific result classes as MCP:

| Method | Required scope | Return type |
| --- | --- | --- |
| `resolveFeatureRest($payload)` | `features:resolve` | `ResolveFeatureResult` |
| `getFeatureContextRest($payload)` | `features:read` | `FeatureContextResult` |
| `reconcileChecklistRest($payload)` | `checklists:write` | `ReconciliationResult` |
| `getVerificationFeedbackRest($payload)` | `feedback:read` | `VerificationFeedbackResult` |
| `addressFeedbackRest($payload)` | `feedback:address` | `AddressFeedbackResult` |
| `getVerificationStatusRest($payload)` | `features:read` | `VerificationStatusResult` |
| `checkCompletionGateRest($payload)` | `gates:read` | `CompletionGateResult` |
| `recordVerificationExceptionRest($payload)` | `exceptions:write` | `VerificationExceptionResult` |

```php
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;

$client = app(VerificationClientInterface::class);
$status = $client->getVerificationStatusRest([
    'feature_id' => $featureId,
]);
```

Use `callRestOperation()` with one of the eight stable operation names for dynamic dispatch. REST calls do not initialize or retain an MCP session. No REST or MCP method in this package can make a human decision, dismiss feedback, finally accept work, or delete human verification state.

### Named MCP v1 tools

The named methods accept the exact v1 tool argument array and return a tool-specific result:

| Method | MCP tool | Required scope | Mode | Return type |
| --- | --- | --- | --- | --- |
| `resolveFeature($arguments)` | `resolve_feature` | `features:resolve` | Write | `ResolveFeatureResult` |
| `getFeatureContext($arguments)` | `get_feature_context` | `features:read` | Read only | `FeatureContextResult` |
| `reconcileChecklist($arguments)` | `reconcile_checklist` | `checklists:write` | Write | `ReconciliationResult` |
| `getVerificationFeedback($arguments)` | `get_verification_feedback` | `feedback:read` | Read only | `VerificationFeedbackResult` |
| `addressFeedback($arguments)` | `address_feedback` | `feedback:address` | Write | `AddressFeedbackResult` |
| `getVerificationStatus($arguments)` | `get_verification_status` | `features:read` | Read only | `VerificationStatusResult` |
| `checkCompletionGateTool($arguments)` | `check_completion_gate` | `gates:read` | Read only | `CompletionGateResult` |
| `recordVerificationException($arguments)` | `record_verification_exception` | `exceptions:write` | Write | `VerificationExceptionResult` |

For example:

```php
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;

$status = app(VerificationClientInterface::class)->getVerificationStatus([
    'feature_id' => $featureId,
    'versions' => [
        'integration_name' => 'release-automation',
        'integration_version' => '1.0.0',
        'skill_version' => '1.0.0',
        'contract_version' => '1.0.0',
    ],
]);
```

Write operations require a caller-generated UUID or ULID idempotency key where the v1 contract requires one. Reuse that key only when retrying the same logical write.

Typed results can be serialized directly. Structured API and tool result classes also expose `toArray()`, `requestId()`, and `correlationId()`:

```php
if (! $health->ready()) {
    throw new RuntimeException('Acceptora is not ready.');
}

$payload = $status->toArray();
$correlationId = $status->correlationId();
```

## Compatibility preflight

Run `preflight()` before the application starts protected MCP verification work:

```php
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;
$client = app(VerificationClientInterface::class);
$compatibility = $client->preflight(requestId: $deploymentId);
```

The preflight checks server readiness, the advertised contract metadata, and the MCP tool registry against the package's bundled v1 manifest. It throws `CompatibilityException` when the server and package are incompatible. Treat that failure as a hard stop: use compatible package and server versions before sending writes. Verify REST separately with `openApiDocument()` and `projectMetadata()` as described above.

Run preflight again after either the Acceptora API contract or this package is upgraded.

## Errors and safe operation

Remote API, MCP, and transport failures use the `Acceptora\Verification\Laravel\Exceptions\VerificationException` hierarchy. Specific exception types are available for authentication, authorization, compatibility, rate limiting, transport failures, invalid responses, API errors, and MCP tool errors. Invalid transport configuration is rejected when the client is resolved. The token remains lazy so public health and contract calls work without one; the first protected call fails locally with `AUTH_REQUIRED` when no valid token is configured.

Exceptions expose the stable `errorCode`, HTTP `statusCode`, `retryable`, `retryAfter`, `recoveryInstruction`, `correlationId`, and `requestId` fields when supplied by the server. Unknown error codes are preserved so callers remain forward-compatible. Remote error text and details are bounded and redact recognized Acceptora and GitHub token forms, values containing private-key markers, and values under credential-named keys. This is not arbitrary-secret detection.

```php
use Acceptora\Verification\Laravel\Exceptions\AuthenticationException;
use Acceptora\Verification\Laravel\Exceptions\AuthorizationException;
use Acceptora\Verification\Laravel\Exceptions\ContractMismatchException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedApiException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedToolException;
use Acceptora\Verification\Laravel\Exceptions\RateLimitException;

try {
    $result = $client->getVerificationStatus($arguments, $requestId);
} catch (RateLimitException $exception) {
    return [
        'status' => 'retry_later',
        'retry_after' => $exception->retryAfter,
        'error_code' => $exception->errorCode,
    ];
} catch (AuthenticationException|AuthorizationException|ContractMismatchException|ExpectedApiException|ExpectedToolException $exception) {
    return [
        'status' => 'blocked',
        'error_code' => $exception->errorCode,
        'recovery_instruction' => $exception->recoveryInstruction,
    ];
}
```

Expected authentication, authorization, contract, domain-conflict, and rate-limit exceptions implement Laravel's `ShouldntReport` marker, so Laravel does not report them by default. Handle them as control flow as shown above. Unexpected transport failures, malformed responses, server-side 5xx errors, and invalid bundled manifests remain reportable; leave those uncaught unless the application can recover and pass them to Laravel's exception reporter exactly once.

Operational safeguards:

- The client forces TLS certificate verification, applies explicit connection, request, and streamed-body read timeouts, and rejects oversized requests before network access and oversized responses while streaming.
- Ambient HTTP proxies are disabled for the permitted plain-HTTP loopback origins so bearer tokens are sent only to the configured local endpoint; HTTPS origins retain normal proxy behavior.
- Bearer tokens belong in server-side configuration only. Never include them in payloads, logs, exception context, or browser code.
- Log stable error codes and correlation identifiers instead of complete request or response bodies.
- The client performs no automatic retries. The application owns retry policy and must consider idempotency before replaying a call.
- Honor `retryable` and `retryAfter`. Do not blindly replay mutating calls.
- On `CONTRACT_UNSUPPORTED`, stop and resolve the version mismatch instead of attempting best-effort compatibility.
- On `REVISION_CONFLICT`, refetch current state and create a new logical write only when the intended write has changed.
- On `RATE_LIMITED` or a retryable transport failure, use bounded backoff. Preserve the original idempotency key for the same logical write.

## Development

```bash
composer install
composer check
```

The check command validates Composer metadata, checks formatting, runs PHPUnit, performs static analysis, and audits locked dependencies.

## Security

See [SECURITY.md](SECURITY.md) for private vulnerability-reporting guidance.

## Support

- For package usage problems and reproducible defects, use the repository's [issue tracker](https://github.com/Elvesora/acceptora-laravel/issues). Remove credentials, source payloads, personal data, and private URLs before posting.
- For Acceptora account or project-access questions, use the [Acceptora contact page](https://www.acceptora.com/contact).
- Report suspected vulnerabilities through the private process in [SECURITY.md](SECURITY.md), never through a public issue.

## License

This package is licensed under the [MIT license](LICENSE).
