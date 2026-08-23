<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel;

use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;
use Acceptora\Verification\Laravel\Exceptions\ApiException;
use Acceptora\Verification\Laravel\Exceptions\AuthenticationException;
use Acceptora\Verification\Laravel\Exceptions\AuthorizationException;
use Acceptora\Verification\Laravel\Exceptions\CompatibilityException;
use Acceptora\Verification\Laravel\Exceptions\ConfigurationException;
use Acceptora\Verification\Laravel\Exceptions\ContractMismatchException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedApiException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedToolException;
use Acceptora\Verification\Laravel\Exceptions\InvalidInputException;
use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;
use Acceptora\Verification\Laravel\Exceptions\RateLimitException;
use Acceptora\Verification\Laravel\Exceptions\TokenResolutionException;
use Acceptora\Verification\Laravel\Exceptions\ToolException;
use Acceptora\Verification\Laravel\Exceptions\TransportException;
use Acceptora\Verification\Laravel\Exceptions\VerificationException;
use Acceptora\Verification\Laravel\Results\AddressFeedbackResult;
use Acceptora\Verification\Laravel\Results\CompatibilityResult;
use Acceptora\Verification\Laravel\Results\CompletionGateResult;
use Acceptora\Verification\Laravel\Results\ContractInfoResult;
use Acceptora\Verification\Laravel\Results\FeatureContextResult;
use Acceptora\Verification\Laravel\Results\HealthResult;
use Acceptora\Verification\Laravel\Results\ProjectMetadataResult;
use Acceptora\Verification\Laravel\Results\ReconciliationResult;
use Acceptora\Verification\Laravel\Results\ResolveFeatureResult;
use Acceptora\Verification\Laravel\Results\RestOperationResult;
use Acceptora\Verification\Laravel\Results\StructuredResult;
use Acceptora\Verification\Laravel\Results\ToolDefinition;
use Acceptora\Verification\Laravel\Results\ToolResult;
use Acceptora\Verification\Laravel\Results\VerificationExceptionResult;
use Acceptora\Verification\Laravel\Results\VerificationFeedbackResult;
use Acceptora\Verification\Laravel\Results\VerificationStatusResult;
use Acceptora\Verification\Laravel\Support\ContractManifest;
use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Throwable;

final class Client implements VerificationClientInterface
{
    public const PACKAGE_VERSION = '1.0.1';

    public const CONTRACT_VERSION = '1.0.0';

    public const INTEGRATION_VERSION = '1.0.0';

    public const SKILL_VERSION = '1.1.0';

    public const MCP_PROTOCOL_VERSION = '2025-11-25';

    public const TOOL_RESOLVE_FEATURE = 'resolve_feature';

    public const TOOL_GET_FEATURE_CONTEXT = 'get_feature_context';

    public const TOOL_RECONCILE_CHECKLIST = 'reconcile_checklist';

    public const TOOL_GET_VERIFICATION_FEEDBACK = 'get_verification_feedback';

    public const TOOL_ADDRESS_FEEDBACK = 'address_feedback';

    public const TOOL_GET_VERIFICATION_STATUS = 'get_verification_status';

    public const TOOL_CHECK_COMPLETION_GATE = 'check_completion_gate';

    public const TOOL_RECORD_VERIFICATION_EXCEPTION = 'record_verification_exception';

    /** @var list<string> */
    public const SUPPORTED_TOOLS = [
        self::TOOL_RESOLVE_FEATURE,
        self::TOOL_GET_FEATURE_CONTEXT,
        self::TOOL_RECONCILE_CHECKLIST,
        self::TOOL_GET_VERIFICATION_FEEDBACK,
        self::TOOL_ADDRESS_FEEDBACK,
        self::TOOL_GET_VERIFICATION_STATUS,
        self::TOOL_CHECK_COMPLETION_GATE,
        self::TOOL_RECORD_VERIFICATION_EXCEPTION,
    ];

    /** @var array<string, string> */
    public const REQUIRED_SCOPES = [
        self::TOOL_RESOLVE_FEATURE => 'features:resolve',
        self::TOOL_GET_FEATURE_CONTEXT => 'features:read',
        self::TOOL_RECONCILE_CHECKLIST => 'checklists:write',
        self::TOOL_GET_VERIFICATION_FEEDBACK => 'feedback:read',
        self::TOOL_ADDRESS_FEEDBACK => 'feedback:address',
        self::TOOL_GET_VERIFICATION_STATUS => 'features:read',
        self::TOOL_CHECK_COMPLETION_GATE => 'gates:read',
        self::TOOL_RECORD_VERIFICATION_EXCEPTION => 'exceptions:write',
    ];

    /** @var array<string, bool> */
    public const READ_ONLY_TOOLS = [
        self::TOOL_RESOLVE_FEATURE => false,
        self::TOOL_GET_FEATURE_CONTEXT => true,
        self::TOOL_RECONCILE_CHECKLIST => false,
        self::TOOL_GET_VERIFICATION_FEEDBACK => true,
        self::TOOL_ADDRESS_FEEDBACK => false,
        self::TOOL_GET_VERIFICATION_STATUS => true,
        self::TOOL_CHECK_COMPLETION_GATE => true,
        self::TOOL_RECORD_VERIFICATION_EXCEPTION => false,
    ];

    /** @var array<string, string> */
    public const REST_OPERATION_PATHS = [
        self::TOOL_RESOLVE_FEATURE => '/api/v1/integrations/features/resolve',
        self::TOOL_GET_FEATURE_CONTEXT => '/api/v1/integrations/features/context',
        self::TOOL_RECONCILE_CHECKLIST => '/api/v1/integrations/checklists/reconcile',
        self::TOOL_GET_VERIFICATION_FEEDBACK => '/api/v1/integrations/feedback/query',
        self::TOOL_ADDRESS_FEEDBACK => '/api/v1/integrations/feedback/address',
        self::TOOL_GET_VERIFICATION_STATUS => '/api/v1/integrations/status',
        self::TOOL_CHECK_COMPLETION_GATE => '/api/v1/integrations/completion-gate',
        self::TOOL_RECORD_VERIFICATION_EXCEPTION => '/api/v1/integrations/verification-exceptions',
    ];

    public const OPENAPI_PATH = '/api/v1/integrations/openapi.json';

    /** @var list<array<string, string>> */
    private const HTTP_ENDPOINTS = [
        ['name' => 'readiness', 'method' => 'GET', 'path' => '/api/health', 'authentication' => 'none'],
        ['name' => 'contract_info', 'method' => 'GET', 'path' => '/api/contract-version', 'authentication' => 'none'],
        ['name' => 'openapi', 'method' => 'GET', 'path' => self::OPENAPI_PATH, 'authentication' => 'none'],
        ['name' => 'project', 'method' => 'GET', 'path' => '/api/v1/integrations/project', 'authentication' => 'bearer', 'required_scope' => 'projects:read'],
        ['name' => 'resolve_feature', 'method' => 'POST', 'path' => '/api/v1/integrations/features/resolve', 'authentication' => 'bearer', 'required_scope' => 'features:resolve'],
        ['name' => 'get_feature_context', 'method' => 'POST', 'path' => '/api/v1/integrations/features/context', 'authentication' => 'bearer', 'required_scope' => 'features:read'],
        ['name' => 'reconcile_checklist', 'method' => 'POST', 'path' => '/api/v1/integrations/checklists/reconcile', 'authentication' => 'bearer', 'required_scope' => 'checklists:write'],
        ['name' => 'get_verification_feedback', 'method' => 'POST', 'path' => '/api/v1/integrations/feedback/query', 'authentication' => 'bearer', 'required_scope' => 'feedback:read'],
        ['name' => 'address_feedback', 'method' => 'POST', 'path' => '/api/v1/integrations/feedback/address', 'authentication' => 'bearer', 'required_scope' => 'feedback:address'],
        ['name' => 'get_verification_status', 'method' => 'POST', 'path' => '/api/v1/integrations/status', 'authentication' => 'bearer', 'required_scope' => 'features:read'],
        ['name' => 'check_completion_gate', 'method' => 'POST', 'path' => '/api/v1/integrations/completion-gate', 'authentication' => 'bearer', 'required_scope' => 'gates:read'],
        ['name' => 'record_verification_exception', 'method' => 'POST', 'path' => '/api/v1/integrations/verification-exceptions', 'authentication' => 'bearer', 'required_scope' => 'exceptions:write'],
        [
            'name' => 'legacy_completion_gate',
            'method' => 'POST',
            'path' => '/api/integrations/completion-gate',
            'authentication' => 'bearer',
            'required_scope' => 'gates:read',
        ],
        ['name' => 'mcp', 'method' => 'POST', 'path' => '/mcp', 'authentication' => 'bearer'],
    ];

    /** @var list<string> */
    private const PUBLIC_ERROR_CODES = [
        'AUTH_REQUIRED',
        'SCOPE_DENIED',
        'FEATURE_AMBIGUOUS',
        'FEATURE_ARCHIVED',
        'REVISION_CONFLICT',
        'IDEMPOTENCY_CONFLICT',
        'SOURCE_STALE',
        'UNCOVERED_CHANGED_SURFACE',
        'EXCEPTION_POLICY_DENIED',
        'SECRET_REJECTED',
        'PAYLOAD_TOO_LARGE',
        'RATE_LIMITED',
        'CONTRACT_UNSUPPORTED',
        'SERVICE_UNAVAILABLE',
    ];

    private const DEFAULT_BASE_URL = 'https://www.acceptora.com';

    private const HEALTH_PATH = '/api/health';

    private const CONTRACT_INFO_PATH = '/api/contract-version';

    private const PROJECT_METADATA_PATH = '/api/v1/integrations/project';

    private const COMPLETION_GATE_PATH = '/api/integrations/completion-gate';

    private const MCP_PATH = '/mcp';

    private const MAX_TOTAL_TOOL_DEFINITIONS = 256;

    private const MAX_TOTAL_TOOL_DEFINITION_BYTES = 8_388_608;

    private const SERVER_NAME = 'Acceptora Verification';

    private const SERVER_VERSION = '1.0.0';

    private const TOKEN_PATTERN = '/\Aavt_[0-9A-HJKMNP-TV-Z]{26}_[A-Za-z0-9]{48}\z/';

    private const SENSITIVE_FRAGMENT_PATTERN = '/(?:avt_[0-9A-HJKMNP-TV-Z]{26}_[A-Za-z0-9]{48}|gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{20,255})/';

    private const PRIVATE_KEY_MARKER_PATTERN = '/-----BEGIN (?:(?:RSA|EC|DSA|OPENSSH|ENCRYPTED) PRIVATE KEY|PRIVATE KEY|PGP PRIVATE KEY BLOCK)-----/';

    private const IDENTIFIER_PATTERN = '/\A[A-Za-z0-9._:-]{1,120}\z/';

    private const SESSION_PATTERN = '/\A[\x21-\x7E]{1,200}\z/';

    /** @var string|(Closure(): string)|null */
    private string|Closure|null $token;

    private ClientInterface $httpClient;

    private LoggerInterface $logger;

    private string $baseUrl;

    private bool $initialized = false;

    private ?string $sessionId = null;

    /** @var array<string, mixed> */
    private array $serverInfo = [];

    private int $nextRpcId = 1;

    /**
     * @param  string|(Closure(): string)|null  $token
     */
    public function __construct(
        #[\SensitiveParameter]
        string|Closure|null $token = null,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        #[\SensitiveParameter]
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 15.0,
        private readonly float $connectTimeout = 5.0,
        private readonly int $maxRequestBytes = 2_000_000,
        private readonly int $maxResponseBytes = 4_000_000,
        private readonly int $maxToolPages = 20,
        #[\SensitiveParameter]
        private readonly string $userAgent = 'acceptora-verification-laravel/1.0.1',
        private readonly ?ContractManifest $contractManifest = null,
    ) {
        $this->assertConfiguration($baseUrl);

        if (is_string($token)) {
            $this->assertToken($token);
        }

        $this->token = $token;
        $this->httpClient = $httpClient ?? new HttpClient;
        $this->logger = $logger ?? new NullLogger;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function health(#[\SensitiveParameter] ?string $requestId = null): HealthResult
    {
        $response = $this->send(
            method: 'GET',
            path: self::HEALTH_PATH,
            requestId: $requestId,
            acceptedStatuses: [200, 503],
        );

        $dataOutcome = $this->unwrapData(
            $response['payload'],
            $response['request_id'],
            'readiness',
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($dataOutcome['error'] !== null) {
            $this->throwErrorSpecification($dataOutcome['error']);
        }

        $data = $dataOutcome['object'];

        try {
            $result = new HealthResult($data, $response['request_id'], $response['correlation_id']);
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $response);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($response);

        return $result;
    }

    public function contractInfo(#[\SensitiveParameter] ?string $requestId = null): ContractInfoResult
    {
        $response = $this->send(
            method: 'GET',
            path: self::CONTRACT_INFO_PATH,
            requestId: $requestId,
        );

        $dataOutcome = $this->unwrapData(
            $response['payload'],
            $response['request_id'],
            'contract information',
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($dataOutcome['error'] !== null) {
            $this->throwErrorSpecification($dataOutcome['error']);
        }

        $data = $dataOutcome['object'];

        try {
            $result = new ContractInfoResult($data, $response['request_id'], $response['correlation_id']);
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $response);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($response);

        return $result;
    }

    /** @return array<string, mixed> */
    public function openApiDocument(#[\SensitiveParameter] ?string $requestId = null): array
    {
        $response = $this->send(
            method: 'GET',
            path: self::OPENAPI_PATH,
            requestId: $requestId,
        );
        $documentOutcome = $this->requireObject(
            $response['payload'],
            $response['request_id'],
            'OpenAPI document',
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($documentOutcome['error'] !== null) {
            $this->throwErrorSpecification($documentOutcome['error']);
        }

        $document = $documentOutcome['object'];

        if (! $this->isOpenApiDocument($document)) {
            throw $this->invalidResponse(
                'The Acceptora OpenAPI response must be an OpenAPI 3.1 document with paths and component schemas.',
                $response['request_id'],
                operation: $response['operation'],
                correlationId: $response['correlation_id'],
                statusCode: $response['status_code'],
            );
        }

        $this->logCompletedResponse($response);

        return $document;
    }

    public function projectMetadata(#[\SensitiveParameter] ?string $requestId = null): ProjectMetadataResult
    {
        $response = $this->send(
            method: 'GET',
            path: self::PROJECT_METADATA_PATH,
            requestId: $requestId,
            authenticated: true,
        );
        $dataOutcome = $this->requireObject(
            $response['payload'],
            $response['request_id'],
            'project metadata',
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($dataOutcome['error'] !== null) {
            $this->throwErrorSpecification($dataOutcome['error']);
        }

        $data = $dataOutcome['object'];

        try {
            $result = new ProjectMetadataResult(
                $data,
                $response['request_id'],
                $this->correlationFromData($data, $response['correlation_id']),
            );
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $response);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($response);

        return $result;
    }

    public function checkCompletionGate(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): CompletionGateResult {
        $response = $this->send(
            method: 'POST',
            path: self::COMPLETION_GATE_PATH,
            requestId: $requestId,
            payload: $this->withVersions($payload),
            authenticated: true,
        );

        $dataOutcome = $this->requireObject(
            $response['payload'],
            $response['request_id'],
            'completion gate',
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($dataOutcome['error'] !== null) {
            $this->throwErrorSpecification($dataOutcome['error']);
        }

        $data = $dataOutcome['object'];

        try {
            $result = new CompletionGateResult(
                $data,
                $response['request_id'],
                $this->correlationFromData($data, $response['correlation_id']),
            );
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $response);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($response);

        return $result;
    }

    public function listTools(#[\SensitiveParameter] ?string $requestId = null): array
    {
        $requestId = $this->requestId($requestId);
        $this->ensureInitialized($requestId);

        $cursor = null;
        $seenCursors = [];
        $tools = [];
        $totalDefinitionBytes = 0;

        for ($page = 1; $page <= $this->maxToolPages; $page++) {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $result = $this->rpc('tools/list', $params, $requestId);
            $definitions = $result['result']['tools'] ?? null;

            if (! is_array($definitions) || ! array_is_list($definitions)) {
                throw $this->invalidResponse('The Acceptora MCP tools/list response has an invalid tools collection.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
            }

            if (count($tools) + count($definitions) > self::MAX_TOTAL_TOOL_DEFINITIONS) {
                throw $this->invalidResponse('The Acceptora MCP tools/list response exceeded the aggregate tool-definition count limit.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
            }

            foreach ($definitions as $definition) {
                if (! is_array($definition) || array_is_list($definition)) {
                    throw $this->invalidResponse('The Acceptora MCP server returned an invalid tool definition.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
                }

                $encodedDefinition = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if (! is_string($encodedDefinition)) {
                    throw $this->invalidResponse('The Acceptora MCP server returned an invalid tool definition.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
                }

                $totalDefinitionBytes += strlen($encodedDefinition);

                if ($totalDefinitionBytes > self::MAX_TOTAL_TOOL_DEFINITION_BYTES) {
                    throw $this->invalidResponse('The Acceptora MCP tools/list response exceeded the aggregate tool-definition byte limit.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
                }

                try {
                    $tools[] = ToolDefinition::fromArray($definition, $requestId);
                } catch (InvalidResponseException $exception) {
                    $error = $this->resultValidationFailureSpecification($exception, $result);
                    $this->throwErrorSpecification($error);
                }
            }

            $nextCursor = $result['result']['nextCursor'] ?? null;

            if ($nextCursor === null) {
                $this->logCompletedResponse($result);

                return $tools;
            }

            if (! is_string($nextCursor) || trim($nextCursor) === '' || strlen($nextCursor) > 2_048) {
                throw $this->invalidResponse('The Acceptora MCP tools/list response has an invalid pagination cursor.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
            }

            if (isset($seenCursors[$nextCursor])) {
                throw $this->invalidResponse('The Acceptora MCP tools/list response repeated a pagination cursor.', $requestId, operation: $result['operation'], correlationId: $result['correlation_id'], statusCode: $result['status_code']);
            }

            $seenCursors[$nextCursor] = true;
            $cursor = $nextCursor;
        }

        throw $this->invalidResponse('The Acceptora MCP tools/list response exceeded the configured page limit.', $requestId);
    }

    public function callTool(
        #[\SensitiveParameter] string $tool,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ToolResult {
        $call = $this->performToolCall($tool, $arguments, $requestId);
        $this->logCompletedResponse($call['response']);

        return $call['result'];
    }

    public function callRestOperation(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): RestOperationResult {
        $call = $this->performRestOperation($operation, $payload, $requestId);
        $this->logCompletedResponse($call['response']);

        return $call['result'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{result: RestOperationResult, response: array{request_id: string, correlation_id: string, operation: string, status_code: int, duration_ms: int}}
     */
    private function performRestOperation(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId,
    ): array {
        $path = self::REST_OPERATION_PATHS[$operation] ?? null;

        if ($path === null) {
            throw new InvalidInputException('Unsupported Acceptora REST operation.');
        }

        if (! $this->isNonEmptyJsonObject($payload)) {
            throw new InvalidInputException('Acceptora REST operation payloads must be non-empty JSON objects.');
        }

        $response = $this->send(
            method: 'POST',
            path: $path,
            requestId: $requestId,
            payload: $this->withVersions($payload),
            authenticated: true,
        );
        $dataOutcome = $this->requireObject(
            $response['payload'],
            $response['request_id'],
            $operation,
            $response['operation'],
            $response['correlation_id'],
            $response['status_code'],
        );

        if ($dataOutcome['error'] !== null) {
            $this->throwErrorSpecification($dataOutcome['error']);
        }

        $data = $dataOutcome['object'];

        return [
            'result' => new RestOperationResult(
                $operation,
                $data,
                $response['request_id'],
                $this->correlationFromData($data, $response['correlation_id']),
            ),
            'response' => [
                'request_id' => $response['request_id'],
                'correlation_id' => $response['correlation_id'],
                'operation' => $response['operation'],
                'status_code' => $response['status_code'],
                'duration_ms' => $response['duration_ms'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: ToolResult, response: array{request_id: string, correlation_id: string, operation: string, status_code: int, duration_ms: int}}
     */
    private function performToolCall(
        #[\SensitiveParameter] string $tool,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId,
    ): array {
        if (! in_array($tool, self::SUPPORTED_TOOLS, true)) {
            throw new InvalidInputException('Unsupported Acceptora verification tool.');
        }

        $requestId = $this->requestId($requestId);
        $this->ensureInitialized($requestId);
        $response = $this->rpc('tools/call', [
            'name' => $tool,
            'arguments' => $this->withVersions($arguments),
        ], $requestId);
        $result = $response['result'];
        $isError = $result['isError'] ?? false;

        if (! is_bool($isError)) {
            throw $this->invalidResponse('The Acceptora MCP tool response has an invalid isError value.', $requestId, operation: $response['operation'], correlationId: $response['correlation_id'], statusCode: $response['status_code']);
        }

        if ($isError) {
            $this->logMcpFailure(
                operation: $response['operation'],
                requestId: $response['request_id'],
                correlationId: $response['correlation_id'],
                statusCode: $response['status_code'],
                errorCode: 'TOOL_ERROR',
                outcome: 'tool_error',
            );
            $error = $this->toolErrorSpecification($result, $requestId, $response['correlation_id']);
            $this->throwErrorSpecification($error);
        }

        $structured = $result['structuredContent'] ?? null;

        if (! is_array($structured) || $structured === [] || array_is_list($structured)) {
            throw $this->invalidResponse('The Acceptora MCP tool response omitted required structuredContent.', $requestId, operation: $response['operation'], correlationId: $response['correlation_id'], statusCode: $response['status_code']);
        }

        $toolResult = new ToolResult(
            $tool,
            $structured,
            $requestId,
            $this->correlationFromData($structured, $response['correlation_id']),
        );

        return [
            'result' => $toolResult,
            'response' => [
                'request_id' => $response['request_id'],
                'correlation_id' => $response['correlation_id'],
                'operation' => $response['operation'],
                'status_code' => $response['status_code'],
                'duration_ms' => $response['duration_ms'],
            ],
        ];
    }

    public function resolveFeature(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ResolveFeatureResult {
        return $this->namedToolResult(self::TOOL_RESOLVE_FEATURE, ResolveFeatureResult::class, $arguments, $requestId);
    }

    public function getFeatureContext(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): FeatureContextResult {
        return $this->namedToolResult(self::TOOL_GET_FEATURE_CONTEXT, FeatureContextResult::class, $arguments, $requestId);
    }

    public function reconcileChecklist(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ReconciliationResult {
        return $this->namedToolResult(self::TOOL_RECONCILE_CHECKLIST, ReconciliationResult::class, $arguments, $requestId);
    }

    public function getVerificationFeedback(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationFeedbackResult {
        return $this->namedToolResult(self::TOOL_GET_VERIFICATION_FEEDBACK, VerificationFeedbackResult::class, $arguments, $requestId);
    }

    public function addressFeedback(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): AddressFeedbackResult {
        return $this->namedToolResult(self::TOOL_ADDRESS_FEEDBACK, AddressFeedbackResult::class, $arguments, $requestId);
    }

    public function getVerificationStatus(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationStatusResult {
        return $this->namedToolResult(self::TOOL_GET_VERIFICATION_STATUS, VerificationStatusResult::class, $arguments, $requestId);
    }

    public function checkCompletionGateTool(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): CompletionGateResult {
        return $this->namedToolResult(self::TOOL_CHECK_COMPLETION_GATE, CompletionGateResult::class, $arguments, $requestId);
    }

    public function recordVerificationException(
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationExceptionResult {
        return $this->namedToolResult(self::TOOL_RECORD_VERIFICATION_EXCEPTION, VerificationExceptionResult::class, $arguments, $requestId);
    }

    public function resolveFeatureRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ResolveFeatureResult {
        return $this->namedRestResult(self::TOOL_RESOLVE_FEATURE, ResolveFeatureResult::class, $payload, $requestId);
    }

    public function getFeatureContextRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): FeatureContextResult {
        return $this->namedRestResult(self::TOOL_GET_FEATURE_CONTEXT, FeatureContextResult::class, $payload, $requestId);
    }

    public function reconcileChecklistRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ReconciliationResult {
        return $this->namedRestResult(self::TOOL_RECONCILE_CHECKLIST, ReconciliationResult::class, $payload, $requestId);
    }

    public function getVerificationFeedbackRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationFeedbackResult {
        return $this->namedRestResult(self::TOOL_GET_VERIFICATION_FEEDBACK, VerificationFeedbackResult::class, $payload, $requestId);
    }

    public function addressFeedbackRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): AddressFeedbackResult {
        return $this->namedRestResult(self::TOOL_ADDRESS_FEEDBACK, AddressFeedbackResult::class, $payload, $requestId);
    }

    public function getVerificationStatusRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationStatusResult {
        return $this->namedRestResult(self::TOOL_GET_VERIFICATION_STATUS, VerificationStatusResult::class, $payload, $requestId);
    }

    public function checkCompletionGateRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): CompletionGateResult {
        return $this->namedRestResult(self::TOOL_CHECK_COMPLETION_GATE, CompletionGateResult::class, $payload, $requestId);
    }

    public function recordVerificationExceptionRest(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): VerificationExceptionResult {
        return $this->namedRestResult(self::TOOL_RECORD_VERIFICATION_EXCEPTION, VerificationExceptionResult::class, $payload, $requestId);
    }

    /**
     * @template TResult of StructuredResult
     *
     * @param  class-string<TResult>  $resultClass
     * @param  array<string, mixed>  $arguments
     * @return TResult
     */
    private function namedToolResult(
        #[\SensitiveParameter] string $tool,
        string $resultClass,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId,
    ): StructuredResult {
        $call = $this->performToolCall($tool, $arguments, $requestId);
        $result = $call['result'];

        try {
            $typedResult = new $resultClass($result->toArray(), $result->requestId(), $result->correlationId());
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $call['response']);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($call['response']);

        return $typedResult;
    }

    /**
     * @template TResult of StructuredResult
     *
     * @param  class-string<TResult>  $resultClass
     * @param  array<string, mixed>  $payload
     * @return TResult
     */
    private function namedRestResult(
        #[\SensitiveParameter] string $operation,
        string $resultClass,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId,
    ): StructuredResult {
        $call = $this->performRestOperation($operation, $payload, $requestId);
        $result = $call['result'];

        try {
            $typedResult = new $resultClass($result->toArray(), $result->requestId(), $result->correlationId());
        } catch (InvalidResponseException $exception) {
            $error = $this->resultValidationFailureSpecification($exception, $call['response']);
            $this->throwErrorSpecification($error);
        }

        $this->logCompletedResponse($call['response']);

        return $typedResult;
    }

    public function preflight(#[\SensitiveParameter] ?string $requestId = null): CompatibilityResult
    {
        $requestId = $this->requestId($requestId);

        try {
            return $this->performPreflight($requestId);
        } catch (CompatibilityException $exception) {
            $this->logPreflightFailure($exception, $requestId);

            throw $exception;
        }
    }

    private function performPreflight(#[\SensitiveParameter] string $requestId): CompatibilityResult
    {
        $manifest = $this->manifest($requestId);
        $this->assertManifestMetadata($manifest, $requestId);

        $health = $this->health($requestId);

        if (! $health->ready()) {
            throw new CompatibilityException(
                message: 'The Acceptora verification service is not ready.',
                errorCode: 'SERVICE_UNAVAILABLE',
                statusCode: 503,
                retryable: true,
                correlationId: $health->correlationId(),
                requestId: $requestId,
            );
        }

        $contract = $this->contractInfo($requestId);
        $expectedContract = $this->manifestSection($manifest, 'contract', $requestId);
        $expectedIntegration = $this->manifestSection($manifest, 'integration', $requestId);
        $expectedSkill = $this->manifestSection($manifest, 'skill', $requestId);

        foreach ([
            ['contract version', $expectedContract['version'] ?? null, $contract->contractVersion()],
            ['integration version', $expectedIntegration['version'] ?? null, $contract->integrationVersion()],
            ['skill version', $expectedSkill['version'] ?? null, $contract->skillVersion()],
        ] as [$label, $expectedValue, $actualValue]) {
            $error = $this->compatibilityValueSpecification($label, $expectedValue, $actualValue, $requestId);

            if ($error !== null) {
                $this->throwErrorSpecification($error);
            }
        }

        $this->ensureInitialized($requestId);
        $expectedServer = $this->manifestSection($manifest, 'server', $requestId);
        foreach ([
            ['MCP protocol version', $expectedContract['mcp_protocol_version'] ?? null, self::MCP_PROTOCOL_VERSION],
            ['MCP server name', $expectedServer['name'] ?? null, $this->serverInfo['name'] ?? null],
            ['MCP server version', $expectedServer['version'] ?? null, $this->serverInfo['version'] ?? null],
        ] as [$label, $expectedValue, $actualValue]) {
            $error = $this->compatibilityValueSpecification($label, $expectedValue, $actualValue, $requestId);

            if ($error !== null) {
                $this->throwErrorSpecification($error);
            }
        }

        $tools = $this->listTools($requestId);
        $expectedTools = $manifest['tools'] ?? null;

        if (! is_array($expectedTools) || ! array_is_list($expectedTools)) {
            throw $this->compatibilityFailure('The bundled contract manifest has an invalid tools registry.', $requestId);
        }

        $actualNames = array_map(static fn (ToolDefinition $tool): string => $tool->name, $tools);
        $expectedNames = [];

        foreach ($expectedTools as $expectedTool) {
            if (! is_array($expectedTool) || array_is_list($expectedTool) || ! is_string($expectedTool['name'] ?? null)) {
                throw $this->compatibilityFailure('The bundled contract manifest has an invalid tool entry.', $requestId);
            }

            $expectedNames[] = $expectedTool['name'];

            if (($expectedTool['required_scope'] ?? null) !== (self::REQUIRED_SCOPES[$expectedTool['name']] ?? null)) {
                throw $this->compatibilityFailure(
                    sprintf('The bundled contract manifest has an invalid required scope for %s.', $expectedTool['name']),
                    $requestId,
                );
            }

            if (($expectedTool['read_only'] ?? null) !== (self::READ_ONLY_TOOLS[$expectedTool['name']] ?? null)) {
                throw $this->compatibilityFailure(
                    sprintf('The bundled contract manifest has invalid read-only metadata for %s.', $expectedTool['name']),
                    $requestId,
                );
            }
        }

        if ($expectedNames !== $actualNames) {
            throw $this->compatibilityFailure('The Acceptora MCP tool registry does not match this package manifest.', $requestId, [
                'expected_tools' => $expectedNames,
                'actual_tools' => $actualNames,
            ]);
        }

        foreach ($tools as $index => $tool) {
            $expectedTool = $expectedTools[$index];
            $actualReadOnly = $tool->annotations['readOnlyHint'] ?? null;

            if (! is_bool($actualReadOnly) || $actualReadOnly !== $expectedTool['read_only']) {
                throw $this->compatibilityFailure(
                    sprintf('The Acceptora %s read-only annotation is incompatible with this package.', $tool->name),
                    $requestId,
                );
            }
            $inputSchemaError = $this->compatibilityValueSpecification(
                sprintf('%s input schema digest', $tool->name),
                $expectedTool['input_schema_sha256'] ?? null,
                $tool->inputSchemaDigest(),
                $requestId,
            );

            if ($inputSchemaError !== null) {
                $this->throwErrorSpecification($inputSchemaError);
            }

            $outputSchemaError = $this->compatibilityValueSpecification(
                sprintf('%s output schema digest', $tool->name),
                $expectedTool['output_schema_sha256'] ?? null,
                $tool->outputSchemaDigest(),
                $requestId,
            );

            if ($outputSchemaError !== null) {
                $this->throwErrorSpecification($outputSchemaError);
            }
        }

        return new CompatibilityResult(
            health: $health,
            contract: $contract,
            serverInfo: $this->serverInfo,
            tools: $tools,
            protocolVersion: self::MCP_PROTOCOL_VERSION,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{result: array<string, mixed>, request_id: string, correlation_id: string, operation: string, status_code: int, duration_ms: int}
     */
    private function rpc(
        #[\SensitiveParameter] string $method,
        #[\SensitiveParameter] array $params,
        #[\SensitiveParameter] string $requestId,
    ): array {
        $id = $this->nextRpcId++;
        $response = $this->send(
            method: 'POST',
            path: self::MCP_PATH,
            requestId: $requestId,
            payload: [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params === [] ? new stdClass : $params,
            ],
            authenticated: true,
            extraHeaders: $this->mcpHeaders(),
            expectedRpcId: $id,
        );

        $rpcOutcome = $this->rpcResult(
            $response['payload'],
            $id,
            $requestId,
            $response['correlation_id'],
            $response['operation'],
            $response['status_code'],
        );

        if ($rpcOutcome['error'] !== null) {
            $this->throwErrorSpecification($rpcOutcome['error']);
        }

        return [
            'result' => $rpcOutcome['result'],
            'request_id' => $response['request_id'],
            'correlation_id' => $response['correlation_id'],
            'operation' => $response['operation'],
            'status_code' => $response['status_code'],
            'duration_ms' => $response['duration_ms'],
        ];
    }

    private function ensureInitialized(#[\SensitiveParameter] string $requestId): void
    {
        if ($this->initialized) {
            return;
        }

        $id = $this->nextRpcId++;
        $response = $this->send(
            method: 'POST',
            path: self::MCP_PATH,
            requestId: $requestId,
            payload: [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                    'capabilities' => new stdClass,
                    'clientInfo' => [
                        'name' => 'acceptora-verification-laravel',
                        'version' => self::PACKAGE_VERSION,
                    ],
                ],
            ],
            authenticated: true,
            expectedRpcId: $id,
        );
        $rpcOutcome = $this->rpcResult(
            $response['payload'],
            $id,
            $requestId,
            $response['correlation_id'],
            $response['operation'],
            $response['status_code'],
        );

        if ($rpcOutcome['error'] !== null) {
            $this->throwErrorSpecification($rpcOutcome['error']);
        }

        $result = $rpcOutcome['result'];
        $protocolVersion = $result['protocolVersion'] ?? null;
        $serverInfo = $result['serverInfo'] ?? null;

        if ($protocolVersion !== self::MCP_PROTOCOL_VERSION || ! is_array($serverInfo) || array_is_list($serverInfo)) {
            throw $this->compatibilityFailure('The Acceptora MCP initialize response is incompatible with this package.', $requestId);
        }

        if (! is_string($serverInfo['name'] ?? null) || ! is_string($serverInfo['version'] ?? null)) {
            throw $this->invalidResponse('The Acceptora MCP initialize response has invalid server information.', $requestId, operation: $response['operation'], correlationId: $response['correlation_id'], statusCode: $response['status_code']);
        }

        $sessionId = $response['headers']['mcp-session-id'] ?? null;

        if ($sessionId !== null) {
            if (preg_match(self::SESSION_PATTERN, $sessionId) !== 1) {
                throw $this->invalidResponse('The Acceptora MCP server returned an unsafe session identifier.', $requestId, operation: $response['operation'], correlationId: $response['correlation_id'], statusCode: $response['status_code']);
            }

            $this->sessionId = $sessionId;
        }

        $this->serverInfo = $serverInfo;
        $this->logCompletedResponse($response);

        try {
            $notificationResponse = $this->send(
                method: 'POST',
                path: self::MCP_PATH,
                requestId: $requestId,
                payload: [
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/initialized',
                    'params' => new stdClass,
                ],
                authenticated: true,
                extraHeaders: $this->mcpHeaders(),
                allowEmpty: true,
            );
            $this->logCompletedResponse($notificationResponse);
        } catch (Throwable $throwable) {
            $this->sessionId = null;
            $this->serverInfo = [];

            throw $throwable;
        }

        $this->initialized = true;
    }

    /**
     * @param  array<string, mixed>|stdClass|null  $payload
     * @param  list<int>  $acceptedStatuses
     * @param  array<string, string>  $extraHeaders
     * @return array{payload: array<string, mixed>, request_id: string, correlation_id: string, headers: array<string, string>, operation: string, status_code: int, duration_ms: int}
     */
    private function send(
        #[\SensitiveParameter] string $method,
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] ?string $requestId,
        #[\SensitiveParameter] array|stdClass|null $payload = null,
        bool $authenticated = false,
        #[\SensitiveParameter] array $acceptedStatuses = [],
        #[\SensitiveParameter] array $extraHeaders = [],
        #[\SensitiveParameter] int|string|null $expectedRpcId = null,
        bool $allowEmpty = false,
    ): array {
        $requestId = $this->requestId($requestId);
        $operation = $method.' '.$path;
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'User-Agent' => $this->userAgent,
            'X-Request-ID' => $requestId,
            'X-Correlation-ID' => $requestId,
        ];

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($authenticated) {
            $headers['Authorization'] = 'Bearer '.$this->resolvedToken($requestId, $operation);
        }

        foreach ($extraHeaders as $name => $value) {
            if ($this->safeHeaderName($name) && $this->safeHeaderValue($value)) {
                $headers[$name] = $value;
            }
        }

        $options = [
            'allow_redirects' => false,
            'connect_timeout' => $this->connectTimeout,
            'headers' => $headers,
            'http_errors' => false,
            'read_timeout' => $this->timeout,
            'stream' => true,
            'timeout' => $this->timeout,
            'verify' => true,
        ];

        if ($this->usesPlainHttpLoopbackOrigin()) {
            $options['proxy'] = ['no' => ['localhost', '127.0.0.1', '::1', '[::1]']];
        }

        if ($payload !== null) {
            try {
                $encodedBody = json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } catch (JsonException $exception) {
                throw new InvalidInputException('The Acceptora request payload is not JSON-serializable.');
            }

            if (strlen($encodedBody) > $this->maxRequestBytes) {
                throw new InvalidInputException('The Acceptora request payload exceeded the configured byte limit.');
            }

            $options['body'] = $encodedBody;
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, $options);
        } catch (GuzzleException $exception) {
            $this->logger->warning('Acceptora verification transport failed.', [
                'event' => 'acceptora_verification.request_failed',
                'operation' => $method.' '.$path,
                'external_service' => 'acceptora',
                'attempt' => 1,
                'retry_state' => 'not_retried',
                'request_id' => $requestId,
                'outcome' => 'transport_error',
                'exception' => $exception::class,
            ]);

            throw new TransportException(
                message: 'The Acceptora verification service could not be reached.',
                errorCode: 'TRANSPORT_ERROR',
                retryable: true,
                requestId: $requestId,
            );
        }

        $status = $response->getStatusCode();
        $correlationId = $this->responseIdentifier($response, 'X-Correlation-ID') ?? $requestId;
        $bodyOutcome = $this->readResponseBody($response, $requestId, $operation, $correlationId, $status);

        if ($bodyOutcome['error'] !== null) {
            $this->throwErrorSpecification($bodyOutcome['error']);
        }

        $body = $bodyOutcome['body'];
        $durationMilliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (($acceptedStatuses !== [] && ! in_array($status, $acceptedStatuses, true))
            || ($acceptedStatuses === [] && ($status < 200 || $status >= 300))) {
            $this->logger->warning('Acceptora verification request returned an error.', [
                'event' => 'acceptora_verification.request_failed',
                'operation' => $method.' '.$path,
                'external_service' => 'acceptora',
                'attempt' => 1,
                'retry_state' => 'not_retried',
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'status_code' => $status,
                'duration_ms' => $durationMilliseconds,
                'outcome' => 'http_error',
            ]);

            $error = $this->httpErrorSpecification($response, $body, $requestId, $correlationId);
            $this->throwErrorSpecification($error);
        }

        $decodedOutcome = $this->decodeSuccessBody(
            body: $body,
            contentType: $response->getHeaderLine('Content-Type'),
            requestId: $requestId,
            expectedRpcId: $expectedRpcId,
            allowEmpty: $allowEmpty,
            operation: $operation,
            correlationId: $correlationId,
            statusCode: $status,
        );

        if ($decodedOutcome['error'] !== null) {
            $this->throwErrorSpecification($decodedOutcome['error']);
        }

        $decoded = $decodedOutcome['payload'];

        $responseHeaders = [];
        $sessionId = $this->responseIdentifier($response, 'MCP-Session-Id', self::SESSION_PATTERN);

        if ($sessionId !== null) {
            $responseHeaders['mcp-session-id'] = $sessionId;
        }

        return [
            'payload' => $decoded,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'headers' => $responseHeaders,
            'operation' => $operation,
            'status_code' => $status,
            'duration_ms' => $durationMilliseconds,
        ];
    }

    /**
     * @return array{body: string, error: null}|array{
     *     body: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function readResponseBody(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
    ): array {
        try {
            $contentLength = $response->getHeaderLine('Content-Length');

            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $this->maxResponseBytes) {
                return [
                    'body' => null,
                    'error' => $this->invalidResponseSpecification(
                        'The Acceptora response exceeded the configured byte limit.',
                        $requestId,
                        'RESPONSE_TOO_LARGE',
                        operation: $operation,
                        correlationId: $correlationId,
                        statusCode: $statusCode,
                    ),
                ];
            }

            $body = '';
            $stream = $response->getBody();

            while (! $stream->eof()) {
                $chunk = $stream->read(8_192);

                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;

                if (strlen($body) > $this->maxResponseBytes) {
                    return [
                        'body' => null,
                        'error' => $this->invalidResponseSpecification(
                            'The Acceptora response exceeded the configured byte limit.',
                            $requestId,
                            'RESPONSE_TOO_LARGE',
                            operation: $operation,
                            correlationId: $correlationId,
                            statusCode: $statusCode,
                        ),
                    ];
                }
            }
        } catch (Throwable $exception) {
            return [
                'body' => null,
                'error' => $this->responseStreamFailureSpecification(
                    $exception::class,
                    $requestId,
                    $operation,
                    $correlationId,
                    $statusCode,
                ),
            ];
        }

        return ['body' => $body, 'error' => null];
    }

    /**
     * @return array{payload: array<string, mixed>, error: null}|array{
     *     payload: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function decodeSuccessBody(
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] string $contentType,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] int|string|null $expectedRpcId,
        bool $allowEmpty,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
    ): array {
        if (trim($body) === '') {
            if ($allowEmpty) {
                return ['payload' => [], 'error' => null];
            }

            return [
                'payload' => null,
                'error' => $this->invalidResponseSpecification(
                    'The Acceptora service returned an empty response.',
                    $requestId,
                    operation: $operation,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                ),
            ];
        }

        if (stripos($contentType, 'text/event-stream') !== false || preg_match('/^\s*(?:event|data):/m', $body) === 1) {
            return $this->decodeSse($body, $requestId, $expectedRpcId, $operation, $correlationId, $statusCode);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'payload' => null,
                'error' => $this->invalidResponseSpecification(
                    'The Acceptora service returned malformed JSON.',
                    $requestId,
                    operation: $operation,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                ),
            ];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [
                'payload' => null,
                'error' => $this->invalidResponseSpecification(
                    'The Acceptora response must be a JSON object.',
                    $requestId,
                    operation: $operation,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                ),
            ];
        }

        return ['payload' => $decoded, 'error' => null];
    }

    /**
     * @return array{payload: array<string, mixed>, error: null}|array{
     *     payload: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function decodeSse(
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] int|string|null $expectedRpcId,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
    ): array {
        $events = [];
        $dataLines = [];
        $lines = preg_split('/\r\n|\r|\n/', $body);

        if (! is_array($lines)) {
            return [
                'payload' => null,
                'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned malformed SSE.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
            ];
        }

        foreach ([...$lines, ''] as $line) {
            if ($line === '') {
                if ($dataLines !== []) {
                    $events[] = implode("\n", $dataLines);
                    $dataLines = [];
                }

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $data = substr($line, 5);
                $dataLines[] = str_starts_with($data, ' ') ? substr($data, 1) : $data;
            }
        }

        foreach ($events as $event) {
            try {
                $decoded = json_decode($event, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [
                    'payload' => null,
                    'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned malformed SSE JSON.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
                ];
            }

            if (! is_array($decoded) || array_is_list($decoded)) {
                return [
                    'payload' => null,
                    'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned an invalid SSE event.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
                ];
            }

            if ($expectedRpcId === null || $this->rpcIdMatches($decoded['id'] ?? null, $expectedRpcId)) {
                return ['payload' => $decoded, 'error' => null];
            }
        }

        return [
            'payload' => null,
            'error' => $this->invalidResponseSpecification('The Acceptora MCP SSE stream omitted the matching JSON-RPC response.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{result: array<string, mixed>, error: null}|array{
     *     result: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function rpcResult(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] int|string $expectedId,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
        #[\SensitiveParameter] string $operation,
        int $statusCode,
    ): array {
        if (($payload['jsonrpc'] ?? null) !== '2.0'
            || ! $this->rpcIdMatches($payload['id'] ?? null, $expectedId)) {
            return [
                'result' => null,
                'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned an invalid JSON-RPC response.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
            ];
        }

        $hasResult = array_key_exists('result', $payload);
        $hasError = array_key_exists('error', $payload);

        if ($hasResult === $hasError) {
            return [
                'result' => null,
                'error' => $this->invalidResponseSpecification('The Acceptora MCP response must contain exactly one of result or error.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
            ];
        }

        if ($hasError) {
            $error = $payload['error'];

            if (! is_array($error) || array_is_list($error)) {
                return [
                    'result' => null,
                    'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned an invalid JSON-RPC error.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
                ];
            }

            $data = $error['data'] ?? null;

            if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
                $this->logMcpFailure(
                    operation: $operation,
                    requestId: $requestId,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                    errorCode: $this->safeErrorCode($data['error']['code'] ?? 'JSON_RPC_ERROR'),
                    outcome: 'rpc_error',
                );

                return [
                    'result' => null,
                    'error' => $this->normalizedErrorSpecification(
                        ['error' => $data['error']],
                        null,
                        $requestId,
                        $correlationId,
                        ToolException::class,
                    ),
                ];
            }

            $sanitizedCode = $this->sanitizeErrorValue($error['code'] ?? 'JSON_RPC_ERROR');
            $sanitizedMessage = $this->sanitizeErrorValue(
                $error['message'] ?? 'The Acceptora MCP server returned a JSON-RPC error.',
            );
            $sanitizedData = $this->sanitizeErrorValue($data);
            $errorCode = is_int($sanitizedCode) || is_string($sanitizedCode)
                ? (string) $sanitizedCode
                : 'JSON_RPC_ERROR';

            $this->logMcpFailure(
                operation: $operation,
                requestId: $requestId,
                correlationId: $correlationId,
                statusCode: $statusCode,
                errorCode: $errorCode,
                outcome: 'rpc_error',
            );

            return [
                'result' => null,
                'error' => [
                    'class' => ToolException::class,
                    'message' => is_string($sanitizedMessage) && trim($sanitizedMessage) !== ''
                        ? $sanitizedMessage
                        : 'The Acceptora MCP server returned a JSON-RPC error.',
                    'error_code' => $errorCode,
                    'status_code' => null,
                    'retryable' => false,
                    'retry_after' => null,
                    'correlation_id' => $correlationId,
                    'request_id' => $requestId,
                    'recovery_instruction' => null,
                    'details' => is_array($sanitizedData) ? $sanitizedData : [],
                    'response_body' => [],
                ],
            ];
        }

        $result = $payload['result'];

        if (! is_array($result) || array_is_list($result)) {
            return [
                'result' => null,
                'error' => $this->invalidResponseSpecification('The Acceptora MCP server returned an invalid JSON-RPC result.', $requestId, operation: $operation, correlationId: $correlationId, statusCode: $statusCode),
            ];
        }

        return ['result' => $result, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function toolErrorSpecification(
        #[\SensitiveParameter] array $result,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
    ): array {
        $content = $result['content'] ?? null;

        if (is_array($content)) {
            foreach ($content as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'text' || ! is_string($item['text'] ?? null)) {
                    continue;
                }

                try {
                    $decoded = json_decode($item['text'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                    return $this->normalizedErrorSpecification($decoded, null, $requestId, $correlationId, ToolException::class);
                }
            }
        }

        return [
            'class' => ToolException::class,
            'message' => 'The Acceptora MCP tool reported an error.',
            'error_code' => 'TOOL_ERROR',
            'status_code' => null,
            'retryable' => false,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'recovery_instruction' => null,
            'details' => [],
            'response_body' => [],
        ];
    }

    /**
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function httpErrorSpecification(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] string $body,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
    ): array {
        $decoded = [];

        if (trim($body) !== '') {
            try {
                $candidate = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $decoded = is_array($candidate) && ! array_is_list($candidate) ? $candidate : [];
            } catch (JsonException) {
                // Non-JSON error bodies are deliberately not exposed to consumers or logs.
            }
        }

        return $this->normalizedErrorSpecification(
            payload: $decoded,
            response: $response,
            requestId: $requestId,
            fallbackCorrelationId: $correlationId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string<VerificationException>|null  $forcedClass
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function normalizedErrorSpecification(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?ResponseInterface $response,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $fallbackCorrelationId,
        ?string $forcedClass = null,
    ): array {
        $payload = $this->sanitizeErrorPayload($payload);
        $error = $payload['error'] ?? null;
        $error = is_array($error) && ! array_is_list($error) ? $error : [];
        $envelopeStatus = $error['http_status'] ?? null;
        $status = $response?->getStatusCode()
            ?? (is_int($envelopeStatus) && $envelopeStatus >= 100 && $envelopeStatus <= 599 ? $envelopeStatus : null);
        $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
            ? $error['code']
            : ($status === 429 ? 'RATE_LIMITED' : 'HTTP_ERROR');
        $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
            ? $error['message']
            : 'The Acceptora verification service returned an error.';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $recoveryInstruction = $this->recoveryInstruction($error['recovery_instruction'] ?? null);
        $correlationId = is_string($error['correlation_id'] ?? null)
            && $this->isSafeIdentifier($error['correlation_id'])
                ? $error['correlation_id']
                : $fallbackCorrelationId;
        $retryAfter = $response === null ? null : $this->retryAfter($response->getHeaderLine('Retry-After'));
        $retryable = is_bool($error['retryable'] ?? null)
            ? $error['retryable']
            : ($status === 429 || ($status !== null && $status >= 500));
        $isExpectedClientError = $status !== null
            && $status >= 400
            && $status < 500
            && (in_array($status, [401, 403, 429], true) || in_array($code, self::PUBLIC_ERROR_CODES, true));
        $exceptionClass = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => AuthorizationException::class,
            $status === 429 => RateLimitException::class,
            $forcedClass === ToolException::class && $isExpectedClientError => ExpectedToolException::class,
            $forcedClass !== null => $forcedClass,
            $isExpectedClientError => ExpectedApiException::class,
            default => ApiException::class,
        };

        return [
            'class' => $exceptionClass,
            'message' => $message,
            'error_code' => $code,
            'status_code' => $status,
            'retryable' => $retryable,
            'retry_after' => $retryAfter,
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'recovery_instruction' => $recoveryInstruction,
            'details' => $details,
            'response_body' => $payload,
        ];
    }

    /**
     * @param  array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }  $error
     */
    private function throwErrorSpecification(#[\SensitiveParameter] array $error): never
    {
        $class = $error['class'];

        throw new $class(
            message: $error['message'],
            errorCode: $error['error_code'],
            statusCode: $error['status_code'],
            retryable: $error['retryable'],
            retryAfter: $error['retry_after'],
            correlationId: $error['correlation_id'],
            requestId: $error['request_id'],
            recoveryInstruction: $error['recovery_instruction'],
            details: $error['details'],
            responseBody: $error['response_body'],
        );
    }

    /** @return array<string, string> */
    private function mcpHeaders(): array
    {
        $headers = ['MCP-Protocol-Version' => self::MCP_PROTOCOL_VERSION];

        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }

    private function resolvedToken(
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $operation,
    ): string {
        try {
            $token = $this->token instanceof Closure ? ($this->token)() : $this->token;
        } catch (Throwable $exception) {
            $this->logger->warning('Acceptora bearer token resolution failed.', [
                'event' => 'acceptora_verification.token_resolution_failed',
                'operation' => $operation,
                'external_service' => 'acceptora',
                'attempt' => 1,
                'retry_state' => 'not_retried',
                'request_id' => $requestId,
                'outcome' => 'token_resolution_error',
                'exception' => $exception::class,
            ]);

            throw new TokenResolutionException(
                message: 'The Acceptora bearer token could not be resolved.',
                errorCode: 'TOKEN_RESOLUTION_FAILED',
                requestId: $requestId,
            );
        }

        if (! is_string($token)) {
            throw new AuthenticationException(
                message: 'The Acceptora bearer token is not configured.',
                errorCode: 'AUTH_REQUIRED',
                statusCode: 401,
            );
        }

        $this->assertToken($token);

        return $token;
    }

    private function assertToken(#[\SensitiveParameter] string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new AuthenticationException(
                message: 'The Acceptora bearer token has an invalid format.',
                errorCode: 'AUTH_REQUIRED',
                statusCode: 401,
            );
        }
    }

    private function assertConfiguration(#[\SensitiveParameter] string $baseUrl): void
    {
        if (! is_finite($this->timeout) || ! is_finite($this->connectTimeout)
            || $this->timeout <= 0 || $this->connectTimeout <= 0) {
            throw new ConfigurationException('Acceptora request and connection timeouts must be greater than zero.');
        }

        if ($this->maxRequestBytes < 1_024) {
            throw new ConfigurationException('Acceptora maxRequestBytes must be at least 1024.');
        }

        if ($this->maxResponseBytes < 1_024) {
            throw new ConfigurationException('Acceptora maxResponseBytes must be at least 1024.');
        }

        if ($this->maxToolPages < 1 || $this->maxToolPages > 1_000) {
            throw new ConfigurationException('Acceptora maxToolPages must be between 1 and 1000.');
        }

        if (! $this->safeHeaderValue($this->userAgent)
            || $this->userAgent !== trim($this->userAgent)
            || preg_match(self::SENSITIVE_FRAGMENT_PATTERN, $this->userAgent) === 1
            || strlen($this->userAgent) > 255) {
            throw new ConfigurationException('The Acceptora user agent is invalid.');
        }

        $parts = parse_url($baseUrl);
        $authority = [];
        $authorityMatched = preg_match(
            '/\A[a-z][a-z0-9+.-]*:\/\/([^\/?#]*)/i',
            $baseUrl,
            $authority,
        ) === 1;

        if ($baseUrl !== trim($baseUrl) || preg_match('/[\x00-\x20\x7F"\\\\]/', $baseUrl) === 1
            || preg_match(self::SENSITIVE_FRAGMENT_PATTERN, $baseUrl) === 1
            || ! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || ! $authorityMatched
            || ! $this->urlAuthorityMatchesParsedComponents($authority[1], $parts)) {
            throw new ConfigurationException('The Acceptora base URL must be an absolute HTTP(S) origin without credentials, path, query, or fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException('The Acceptora base URL must use HTTP or HTTPS.');
        }

        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            throw new ConfigurationException('The Acceptora base URL must use HTTPS except for loopback development hosts.');
        }
    }

    /** @param array<string, mixed> $parts */
    private function urlAuthorityMatchesParsedComponents(
        #[\SensitiveParameter] string $authority,
        #[\SensitiveParameter] array $parts,
    ): bool {
        $host = $parts['host'] ?? null;

        if (! is_string($host)) {
            return false;
        }

        $expected = $host;

        if (array_key_exists('port', $parts)) {
            if (! is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65_535) {
                return false;
            }

            $expected .= ':'.$parts['port'];
        }

        return strcasecmp($authority, $expected) === 0;
    }

    private function requestId(#[\SensitiveParameter] ?string $requestId): string
    {
        $requestId ??= 'req_'.bin2hex(random_bytes(16));

        if (! $this->isSafeIdentifier($requestId)) {
            throw new InvalidInputException('Acceptora request IDs must contain 1-120 safe, non-secret identifier characters.');
        }

        return $requestId;
    }

    private function usesPlainHttpLoopbackOrigin(): bool
    {
        $parts = parse_url($this->baseUrl);

        if (! is_array($parts) || ! is_string($parts['scheme'] ?? null) || ! is_string($parts['host'] ?? null)) {
            return false;
        }

        return strtolower($parts['scheme']) === 'http'
            && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withVersions(#[\SensitiveParameter] array $payload): array
    {
        $payload['versions'] ??= [
            'integration_name' => 'acceptora-verification-laravel',
            'integration_version' => self::INTEGRATION_VERSION,
            'skill_version' => self::SKILL_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
        ];

        return $payload;
    }

    /** @param array<array-key, mixed> $payload */
    private function isNonEmptyJsonObject(#[\SensitiveParameter] array $payload): bool
    {
        return $payload !== [] && ! array_is_list($payload);
    }

    /** @param array<string, mixed> $document */
    private function isOpenApiDocument(#[\SensitiveParameter] array $document): bool
    {
        $version = $document['openapi'] ?? null;
        $paths = $document['paths'] ?? null;
        $components = $document['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;

        return is_string($version)
            && preg_match('/^3\.1\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1
            && is_array($paths)
            && $paths !== []
            && ! array_is_list($paths)
            && is_array($components)
            && ($components === [] || ! array_is_list($components))
            && is_array($schemas)
            && $schemas !== []
            && ! array_is_list($schemas);
    }

    private function rpcIdMatches(
        #[\SensitiveParameter] mixed $actualId,
        #[\SensitiveParameter] int|string $expectedId,
    ): bool {
        return (is_int($actualId) || is_string($actualId))
            && (string) $actualId === (string) $expectedId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{object: array<string, mixed>, error: null}|array{
     *     object: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function unwrapData(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $label,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
    ): array {
        if (! array_key_exists('data', $payload)) {
            return [
                'object' => null,
                'error' => $this->invalidResponseSpecification(
                    sprintf('The Acceptora %s response omitted its data envelope.', $label),
                    $requestId,
                    operation: $operation,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                ),
            ];
        }

        return $this->requireObject($payload['data'], $requestId, $label, $operation, $correlationId, $statusCode);
    }

    /**
     * @return array{object: array<string, mixed>, error: null}|array{
     *     object: null,
     *     error: array{
     *         class: class-string<VerificationException>,
     *         message: string,
     *         error_code: string,
     *         status_code: int|null,
     *         retryable: bool,
     *         retry_after: int|null,
     *         correlation_id: string|null,
     *         request_id: string|null,
     *         recovery_instruction: string|null,
     *         details: array<string, mixed>,
     *         response_body: array<string, mixed>
     *     }
     * }
     */
    private function requireObject(
        #[\SensitiveParameter] mixed $value,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $label,
        #[\SensitiveParameter] string $operation = 'response_validation',
        #[\SensitiveParameter] ?string $correlationId = null,
        ?int $statusCode = null,
    ): array {
        if (! is_array($value) || array_is_list($value)) {
            return [
                'object' => null,
                'error' => $this->invalidResponseSpecification(
                    sprintf('The Acceptora %s response must be a JSON object.', $label),
                    $requestId,
                    operation: $operation,
                    correlationId: $correlationId,
                    statusCode: $statusCode,
                ),
            ];
        }

        return ['object' => $value, 'error' => null];
    }

    /** @param array<string, mixed> $data */
    private function correlationFromData(
        #[\SensitiveParameter] array $data,
        #[\SensitiveParameter] string $fallback,
    ): string {
        $correlationId = $data['correlation_id'] ?? null;

        return is_string($correlationId) && $this->isSafeIdentifier($correlationId)
            ? $correlationId
            : $fallback;
    }

    private function responseIdentifier(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] string $header,
        #[\SensitiveParameter] string $pattern = self::IDENTIFIER_PATTERN,
    ): ?string {
        $value = $response->getHeaderLine($header);

        return $value !== '' && $this->isSafeIdentifier($value, $pattern) ? $value : null;
    }

    private function isSafeIdentifier(
        #[\SensitiveParameter] string $value,
        #[\SensitiveParameter] string $pattern = self::IDENTIFIER_PATTERN,
    ): bool {
        return preg_match($pattern, $value) === 1 && preg_match(self::SENSITIVE_FRAGMENT_PATTERN, $value) !== 1;
    }

    private function safeHeaderName(#[\SensitiveParameter] string $name): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) === 1;
    }

    private function safeHeaderValue(#[\SensitiveParameter] string $value): bool
    {
        return $value !== '' && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) !== 1;
    }

    private function retryAfter(#[\SensitiveParameter] string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function recoveryInstruction(#[\SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->truncateUtf8(trim($value), 2_048);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeErrorPayload(#[\SensitiveParameter] array $payload): array
    {
        $sanitized = $this->sanitizeErrorValue($payload);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function sanitizeErrorValue(
        #[\SensitiveParameter] mixed $value,
        int $depth = 0,
        #[\SensitiveParameter] ?string $key = null,
    ): mixed {
        if ($depth > 8) {
            return '[truncated]';
        }

        if ($key !== null && preg_match('/(?:authorization|cookie|credential|password|secret|token)/i', $key) === 1) {
            return '[redacted]';
        }

        if (is_string($value)) {
            if (preg_match(self::PRIVATE_KEY_MARKER_PATTERN, $value) === 1) {
                return '[redacted]';
            }

            $redacted = preg_replace(
                self::SENSITIVE_FRAGMENT_PATTERN,
                '[redacted]',
                $value,
            ) ?? '[redacted]';

            return strlen($redacted) > 4_096 ? $this->truncateUtf8($redacted, 4_093).'...' : $redacted;
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : '[unsupported]';
        }

        $sanitized = [];

        foreach (array_slice($value, 0, 50, true) as $childKey => $child) {
            $sanitizedKey = is_string($childKey)
                ? $this->sanitizeErrorValue($childKey, $depth + 1)
                : $childKey;
            $sanitized[is_string($sanitizedKey) ? $sanitizedKey : '[redacted]'] = $this->sanitizeErrorValue(
                $child,
                $depth + 1,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $sanitized;
    }

    private function truncateUtf8(#[\SensitiveParameter] string $value, int $maximumBytes): string
    {
        $truncated = substr($value, 0, $maximumBytes);

        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }

    /** @return array<string, mixed> */
    private function manifest(#[\SensitiveParameter] string $requestId): array
    {
        try {
            $manifest = ($this->contractManifest ?? ContractManifest::bundled())->toArray();
        } catch (Throwable $exception) {
            throw new CompatibilityException(
                message: 'The bundled Acceptora contract manifest could not be loaded.',
                errorCode: 'MANIFEST_INVALID',
                requestId: $requestId,
                previous: $exception,
            );
        }

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestMetadata(
        #[\SensitiveParameter] array $manifest,
        #[\SensitiveParameter] string $requestId,
    ): void {
        if (($manifest['schema_version'] ?? null) !== 1
            || ($manifest['http_endpoints'] ?? null) !== self::HTTP_ENDPOINTS
            || ($manifest['error_codes'] ?? null) !== self::PUBLIC_ERROR_CODES) {
            throw $this->compatibilityFailure('The bundled Acceptora contract manifest metadata is incompatible.', $requestId);
        }

        $package = $this->manifestSection($manifest, 'package', $requestId);
        $contract = $this->manifestSection($manifest, 'contract', $requestId);
        $integration = $this->manifestSection($manifest, 'integration', $requestId);
        $skill = $this->manifestSection($manifest, 'skill', $requestId);
        $server = $this->manifestSection($manifest, 'server', $requestId);

        $expected = [
            'package name' => ['acceptora/verification-laravel', $package['name'] ?? null],
            'package version' => [self::PACKAGE_VERSION, $package['version'] ?? null],
            'contract version' => [self::CONTRACT_VERSION, $contract['version'] ?? null],
            'integration version' => [self::INTEGRATION_VERSION, $integration['version'] ?? null],
            'skill version' => [self::SKILL_VERSION, $skill['version'] ?? null],
            'MCP protocol version' => [self::MCP_PROTOCOL_VERSION, $contract['mcp_protocol_version'] ?? null],
            'server name' => [self::SERVER_NAME, $server['name'] ?? null],
            'server version' => [self::SERVER_VERSION, $server['version'] ?? null],
        ];

        foreach ($expected as $label => [$expectedValue, $actualValue]) {
            $error = $this->compatibilityValueSpecification($label, $expectedValue, $actualValue, $requestId);

            if ($error !== null) {
                $this->throwErrorSpecification($error);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function manifestSection(
        #[\SensitiveParameter] array $manifest,
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] string $requestId,
    ): array {
        $section = $manifest[$key] ?? null;

        if (! is_array($section) || array_is_list($section)) {
            throw $this->compatibilityFailure(sprintf('The bundled contract manifest has an invalid %s section.', $key), $requestId);
        }

        return $section;
    }

    /**
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }|null
     */
    private function compatibilityValueSpecification(
        #[\SensitiveParameter] string $label,
        #[\SensitiveParameter] mixed $expected,
        #[\SensitiveParameter] mixed $actual,
        #[\SensitiveParameter] string $requestId,
    ): ?array {
        if (is_string($expected) && is_string($actual) && $expected === $actual) {
            return null;
        }

        $sanitizedMessage = $this->sanitizeErrorValue(
            sprintf('The Acceptora %s is incompatible with this package.', $label),
        );

        return [
            'class' => ContractMismatchException::class,
            'message' => is_string($sanitizedMessage) && trim($sanitizedMessage) !== ''
                ? $sanitizedMessage
                : 'The Acceptora response is incompatible with this package.',
            'error_code' => 'CONTRACT_UNSUPPORTED',
            'status_code' => null,
            'retryable' => false,
            'retry_after' => null,
            'correlation_id' => null,
            'request_id' => $requestId,
            'recovery_instruction' => null,
            'details' => $this->sanitizeErrorPayload([
                'expected' => is_scalar($expected) ? $expected : null,
                'actual' => is_scalar($actual) ? $actual : null,
            ]),
            'response_body' => [],
        ];
    }

    /** @param array<string, mixed> $details */
    private function compatibilityFailure(
        #[\SensitiveParameter] string $message,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] array $details = [],
    ): CompatibilityException {
        return new ContractMismatchException(
            message: $message,
            errorCode: 'CONTRACT_UNSUPPORTED',
            requestId: $requestId,
            details: $details,
        );
    }

    /**
     * @param  array{request_id: string, correlation_id: string, operation: string, status_code: int, duration_ms: int}  $response
     */
    private function logCompletedResponse(#[\SensitiveParameter] array $response): void
    {
        $this->logger->info('Acceptora verification request completed.', [
            'event' => 'acceptora_verification.request_completed',
            'operation' => $response['operation'],
            'external_service' => 'acceptora',
            'attempt' => 1,
            'retry_state' => 'not_retried',
            'request_id' => $response['request_id'],
            'correlation_id' => $response['correlation_id'],
            'status_code' => $response['status_code'],
            'duration_ms' => $response['duration_ms'],
            'outcome' => 'success',
        ]);
    }

    /**
     * @param  array{request_id: string, correlation_id: string, operation: string, status_code: int, duration_ms: int}  $response
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function resultValidationFailureSpecification(
        #[\SensitiveParameter] InvalidResponseException $exception,
        #[\SensitiveParameter] array $response,
    ): array {
        return $this->invalidResponseSpecification(
            message: $exception->getMessage(),
            requestId: $response['request_id'],
            code: $exception->errorCode,
            operation: $response['operation'],
            correlationId: $response['correlation_id'],
            statusCode: $response['status_code'],
        );
    }

    private function logMcpFailure(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
        #[\SensitiveParameter] string $errorCode,
        #[\SensitiveParameter] string $outcome,
    ): void {
        $this->logger->warning('Acceptora verification MCP request failed.', [
            'event' => 'acceptora_verification.request_failed',
            'operation' => $operation,
            'external_service' => 'acceptora',
            'attempt' => 1,
            'retry_state' => 'not_retried',
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'status_code' => $statusCode,
            'outcome' => $outcome,
            'error_code' => $this->safeErrorCode($errorCode),
        ]);
    }

    private function safeErrorCode(#[\SensitiveParameter] mixed $value): string
    {
        $sanitized = $this->sanitizeErrorValue($value);

        if (! is_int($sanitized) && ! is_string($sanitized)) {
            return 'JSON_RPC_ERROR';
        }

        $code = trim((string) $sanitized);

        return $code === '' ? 'JSON_RPC_ERROR' : $this->truncateUtf8($code, 200);
    }

    private function logPreflightFailure(
        #[\SensitiveParameter] CompatibilityException $exception,
        #[\SensitiveParameter] string $requestId,
    ): void {
        $context = [
            'event' => 'acceptora_verification.preflight_failed',
            'operation' => 'verification_preflight',
            'external_service' => 'acceptora',
            'attempt' => 1,
            'retry_state' => 'not_retried',
            'request_id' => $requestId,
            'outcome' => 'compatibility_error',
            'error_code' => $this->safeErrorCode($exception->errorCode),
        ];

        if ($exception->correlationId !== null && $this->isSafeIdentifier($exception->correlationId)) {
            $context['correlation_id'] = $exception->correlationId;
        }

        if ($exception->statusCode !== null) {
            $context['status_code'] = $exception->statusCode;
        }

        $this->logger->warning('Acceptora verification compatibility preflight failed.', $context);
    }

    private function invalidResponse(
        #[\SensitiveParameter] string $message,
        #[\SensitiveParameter] ?string $requestId,
        #[\SensitiveParameter] string $code = 'INVALID_RESPONSE',
        #[\SensitiveParameter] ?Throwable $previous = null,
        #[\SensitiveParameter] string $operation = 'response_validation',
        #[\SensitiveParameter] ?string $correlationId = null,
        ?int $statusCode = null,
    ): InvalidResponseException {
        $error = $this->invalidResponseSpecification(
            $message,
            $requestId,
            $code,
            $operation,
            $correlationId,
            $statusCode,
        );

        return new InvalidResponseException(
            message: $error['message'],
            errorCode: $error['error_code'],
            statusCode: $error['status_code'],
            retryable: $error['retryable'],
            retryAfter: $error['retry_after'],
            correlationId: $error['correlation_id'],
            requestId: $error['request_id'],
            recoveryInstruction: $error['recovery_instruction'],
            details: $error['details'],
            responseBody: $error['response_body'],
            previous: $previous,
        );
    }

    /**
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function invalidResponseSpecification(
        #[\SensitiveParameter] string $message,
        #[\SensitiveParameter] ?string $requestId,
        #[\SensitiveParameter] string $code = 'INVALID_RESPONSE',
        #[\SensitiveParameter] string $operation = 'response_validation',
        #[\SensitiveParameter] ?string $correlationId = null,
        ?int $statusCode = null,
    ): array {
        $sanitizedMessage = $this->sanitizeErrorValue($message);
        $sanitizedCode = $this->safeErrorCode($code);
        $context = [
            'event' => 'acceptora_verification.invalid_response',
            'operation' => $operation,
            'external_service' => 'acceptora',
            'attempt' => 1,
            'retry_state' => 'not_retried',
            'request_id' => $requestId,
            'outcome' => 'invalid_response',
            'error_code' => $sanitizedCode,
        ];

        if ($correlationId !== null) {
            $context['correlation_id'] = $correlationId;
        }

        if ($statusCode !== null) {
            $context['status_code'] = $statusCode;
        }

        $this->logger->warning('Acceptora verification response validation failed.', $context);

        return [
            'class' => InvalidResponseException::class,
            'message' => is_string($sanitizedMessage) && trim($sanitizedMessage) !== ''
                ? $sanitizedMessage
                : 'The Acceptora response was invalid.',
            'error_code' => $sanitizedCode,
            'status_code' => $statusCode,
            'retryable' => false,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'recovery_instruction' => null,
            'details' => [],
            'response_body' => [],
        ];
    }

    /**
     * @param  class-string  $exceptionClass
     * @return array{
     *     class: class-string<VerificationException>,
     *     message: string,
     *     error_code: string,
     *     status_code: int|null,
     *     retryable: bool,
     *     retry_after: int|null,
     *     correlation_id: string|null,
     *     request_id: string|null,
     *     recovery_instruction: string|null,
     *     details: array<string, mixed>,
     *     response_body: array<string, mixed>
     * }
     */
    private function responseStreamFailureSpecification(
        #[\SensitiveParameter] string $exceptionClass,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] string $correlationId,
        int $statusCode,
    ): array {
        $this->logger->warning('Acceptora verification response stream failed.', [
            'event' => 'acceptora_verification.request_failed',
            'operation' => $operation,
            'external_service' => 'acceptora',
            'attempt' => 1,
            'retry_state' => 'not_retried',
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'status_code' => $statusCode,
            'outcome' => 'transport_error',
            'exception' => $exceptionClass,
        ]);

        return [
            'class' => TransportException::class,
            'message' => 'The Acceptora response stream could not be read.',
            'error_code' => 'TRANSPORT_ERROR',
            'status_code' => null,
            'retryable' => true,
            'retry_after' => null,
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'recovery_instruction' => null,
            'details' => [],
            'response_body' => [],
        ];
    }
}
