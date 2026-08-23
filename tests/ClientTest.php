<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Acceptora\Verification\Laravel\Client;
use Acceptora\Verification\Laravel\Exceptions\ApiException;
use Acceptora\Verification\Laravel\Exceptions\AuthenticationException;
use Acceptora\Verification\Laravel\Exceptions\CompatibilityException;
use Acceptora\Verification\Laravel\Exceptions\ContractMismatchException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedApiException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedToolException;
use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;
use Acceptora\Verification\Laravel\Exceptions\RateLimitException;
use Acceptora\Verification\Laravel\Exceptions\TokenResolutionException;
use Acceptora\Verification\Laravel\Exceptions\ToolException;
use Acceptora\Verification\Laravel\Exceptions\TransportException;
use Acceptora\Verification\Laravel\Exceptions\VerificationException;
use Acceptora\Verification\Laravel\Results\AddressFeedbackResult;
use Acceptora\Verification\Laravel\Results\CompletionGateResult;
use Acceptora\Verification\Laravel\Results\FeatureContextResult;
use Acceptora\Verification\Laravel\Results\ReconciliationResult;
use Acceptora\Verification\Laravel\Results\ResolveFeatureResult;
use Acceptora\Verification\Laravel\Results\VerificationExceptionResult;
use Acceptora\Verification\Laravel\Results\VerificationFeedbackResult;
use Acceptora\Verification\Laravel\Results\VerificationStatusResult;
use Acceptora\Verification\Laravel\Support\ContractManifest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientTest extends TestCase
{
    public function test_public_discovery_endpoints_are_available_without_bearer_authentication(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->healthResponse(503, false),
            $this->contractResponse(),
            $this->jsonResponse(200, [
                'openapi' => '3.1.0',
                'info' => ['title' => 'Acceptora Integration API', 'version' => '1.0.0'],
                'paths' => ['/api/v1/integrations/project' => ['get' => []]],
                'components' => ['schemas' => ['Project' => ['type' => 'object']]],
            ]),
        ], $history);

        $health = $client->health('request-health');
        $contract = $client->contractInfo('request-contract');
        $openApi = $client->openApiDocument('request-openapi');

        $this->assertFalse($health->ready());
        $this->assertSame('degraded', $health->status());
        $this->assertSame('1.0.0', $contract->contractVersion());
        $this->assertSame('3.1.0', $openApi['openapi']);
        $this->assertSame('/api/health', $history->request(0)->getUri()->getPath());
        $this->assertSame('/api/contract-version', $history->request(1)->getUri()->getPath());
        $this->assertSame(Client::OPENAPI_PATH, $history->request(2)->getUri()->getPath());
        $this->assertFalse($history->request(0)->hasHeader('Authorization'));
        $this->assertFalse($history->request(1)->hasHeader('Authorization'));
        $this->assertFalse($history->request(2)->hasHeader('Authorization'));
        $this->assertSame('request-health', $history->request(0)->getHeaderLine('X-Request-ID'));
        $this->assertSame('request-health', $history->request(0)->getHeaderLine('X-Correlation-ID'));
        $this->assertSame('request-openapi', $history->request(2)->getHeaderLine('X-Request-ID'));
    }

    public function test_completion_gate_is_authenticated_capped_and_versioned(): void
    {
        $history = new RequestHistory;
        $logger = new ArrayLogger;
        $client = $this->client([$this->completionGateResponse()], $history, $logger);

        $result = $client->checkCompletionGate(['project_id' => 'proj_01J00000000000000000000001'], 'request-gate');

        $this->assertTrue($result->passed());
        $this->assertSame('corr-gate', $result->correlationId());
        $request = $history->request(0);
        $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/api/integrations/completion-gate', $request->getUri()->getPath());
        $this->assertSame('Bearer '.$this->token(), $request->getHeaderLine('Authorization'));
        $this->assertSame('acceptora-verification-laravel', $payload['versions']['integration_name']);
        $this->assertSame('1.0.0', $payload['versions']['contract_version']);
        $this->assertFalse($history->options(0)['allow_redirects']);
        $this->assertTrue($history->options(0)['stream']);
        $this->assertTrue($history->options(0)['verify']);
        $this->assertSame(15.0, $history->options(0)['timeout']);
        $this->assertSame(15.0, $history->options(0)['read_timeout']);
        $this->assertSame(5.0, $history->options(0)['connect_timeout']);
        $this->assertCount(1, $logger->records);
        $this->assertOperationalContext($logger->records[0]['context'], 'POST /api/integrations/completion-gate', 'success');
        $this->assertSame(200, $logger->records[0]['context']['status_code']);
    }

    public function test_openapi_discovery_rejects_non_31_documents_before_success_logging(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->jsonResponse(200, [
                'openapi' => '3.0.3',
                'paths' => [],
                'components' => ['schemas' => []],
            ], ['X-Correlation-ID' => 'corr-openapi-invalid']),
        ], logger: $logger);

        try {
            $client->openApiDocument('request-openapi-invalid');
            $this->fail('Expected an incompatible OpenAPI document to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('INVALID_RESPONSE', $exception->errorCode);
            $this->assertSame('corr-openapi-invalid', $exception->correlationId);
            $this->assertCount(1, $logger->records);
            $this->assertOperationalContext(
                $logger->records[0]['context'],
                'GET /api/v1/integrations/openapi.json',
                'invalid_response',
            );
            $this->assertSame(200, $logger->records[0]['context']['status_code']);
        }
    }

    public function test_all_eight_named_mcp_methods_initialize_once_and_return_typed_results(): void
    {
        $history = new RequestHistory;
        $responses = [
            $this->initializeResponse(1, ['MCP-Session-Id' => 'session:one']),
            new Response(202),
        ];

        foreach (array_values($this->toolOutputs()) as $index => $output) {
            $responses[] = $this->rpcResponse($index + 2, [
                'content' => [['type' => 'text', 'text' => 'ok']],
                'isError' => false,
                'structuredContent' => $output,
            ]);
        }

        $client = $this->client($responses, $history);
        $arguments = ['project_id' => 'proj_01J00000000000000000000001'];
        $results = [
            $client->resolveFeature($arguments, 'request-workflow'),
            $client->getFeatureContext($arguments, 'request-workflow'),
            $client->reconcileChecklist($arguments, 'request-workflow'),
            $client->getVerificationFeedback($arguments, 'request-workflow'),
            $client->addressFeedback($arguments, 'request-workflow'),
            $client->getVerificationStatus($arguments, 'request-workflow'),
            $client->checkCompletionGateTool($arguments, 'request-workflow'),
            $client->recordVerificationException($arguments, 'request-workflow'),
        ];

        $expectedClasses = [
            ResolveFeatureResult::class,
            FeatureContextResult::class,
            ReconciliationResult::class,
            VerificationFeedbackResult::class,
            AddressFeedbackResult::class,
            VerificationStatusResult::class,
            CompletionGateResult::class,
            VerificationExceptionResult::class,
        ];

        foreach ($results as $index => $result) {
            $this->assertInstanceOf($expectedClasses[$index], $result);
            $this->assertSame('corr-tool', $result->correlationId());
        }

        $this->assertCount(10, $history);
        $this->assertSame('initialize', $this->requestPayload($history, 0)['method']);
        $this->assertSame('notifications/initialized', $this->requestPayload($history, 1)['method']);
        $this->assertSame('session:one', $history->request(1)->getHeaderLine('MCP-Session-Id'));
        $this->assertSame(Client::MCP_PROTOCOL_VERSION, $history->request(1)->getHeaderLine('MCP-Protocol-Version'));

        foreach (range(2, 9) as $index) {
            $payload = $this->requestPayload($history, $index);
            $this->assertSame('tools/call', $payload['method']);
            $this->assertSame(Client::SUPPORTED_TOOLS[$index - 2], $payload['params']['name']);
            $this->assertSame('1.0.0', $payload['params']['arguments']['versions']['contract_version']);
            $this->assertSame('session:one', $history->request($index)->getHeaderLine('MCP-Session-Id'));
        }
    }

    public function test_mcp_supports_sse_and_follows_tool_pagination(): void
    {
        $history = new RequestHistory;
        $initialize = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => Client::MCP_PROTOCOL_VERSION,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
            ],
        ];
        $firstPage = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [$this->toolDefinition('resolve_feature')],
                'nextCursor' => 'next-page',
            ],
        ];
        $secondPage = [
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['tools' => [$this->toolDefinition('get_feature_context')]],
        ];
        $client = $this->client([
            $this->sseResponse($initialize, ['MCP-Session-Id' => 'session-sse']),
            new Response(202),
            $this->sseResponse($firstPage),
            $this->sseResponse($secondPage),
        ], $history);

        $tools = $client->listTools('request-tools');

        $this->assertSame(['resolve_feature', 'get_feature_context'], array_map(
            static fn ($tool): string => $tool->name,
            $tools,
        ));
        $this->assertSame('next-page', $this->requestPayload($history, 3)['params']['cursor']);
        $this->assertSame('session-sse', $history->request(3)->getHeaderLine('MCP-Session-Id'));
    }

    public function test_tool_pagination_rejects_an_oversized_aggregate_definition_count(): void
    {
        $logger = new ArrayLogger;
        $firstPage = array_fill(0, 128, $this->toolDefinition('tool_one'));
        $secondPage = array_fill(0, 129, $this->toolDefinition('tool_two'));
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->rpcResponse(2, ['tools' => $firstPage, 'nextCursor' => 'next-page']),
            $this->rpcResponse(3, ['tools' => $secondPage]),
        ], logger: $logger, maxToolPages: 2);

        try {
            $client->listTools('request-tool-count-limit');
            $this->fail('Expected the aggregate tool-definition count to be bounded.');
        } catch (InvalidResponseException $exception) {
            $this->assertStringContainsString('aggregate tool-definition count limit', $exception->getMessage());
            $this->assertOperationalContext($logger->last()['context'], 'POST /mcp', 'invalid_response');
        }
    }

    public function test_tool_pagination_rejects_an_oversized_aggregate_definition_payload(): void
    {
        $logger = new ArrayLogger;
        $definition = $this->toolDefinition('large_tool');
        $definition['inputSchema']['description'] = str_repeat('x', 3_000_000);
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->rpcResponse(2, ['tools' => [$definition], 'nextCursor' => 'second-page']),
            $this->rpcResponse(3, ['tools' => [$definition], 'nextCursor' => 'third-page']),
            $this->rpcResponse(4, ['tools' => [$definition]]),
        ], logger: $logger, maxToolPages: 3);

        try {
            $client->listTools('request-tool-byte-limit');
            $this->fail('Expected the aggregate tool-definition payload to be bounded.');
        } catch (InvalidResponseException $exception) {
            $this->assertStringContainsString('aggregate tool-definition byte limit', $exception->getMessage());
            $this->assertOperationalContext($logger->last()['context'], 'POST /mcp', 'invalid_response');
        }
    }

    public function test_tool_errors_preserve_unknown_codes_and_redact_remote_secrets(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['error' => [
                        'code' => 'FUTURE_CONFLICT',
                        'message' => 'Rejected '.$this->token(),
                        'http_status' => 409,
                        'retryable' => false,
                        'correlation_id' => 'corr-future',
                        'recovery_instruction' => 'Restore state without '.$this->token().str_repeat('x', 3_000),
                        'details' => [
                            'token' => $this->token(),
                            $this->token() => ['echo' => $this->token()],
                        ],
                    ]], JSON_THROW_ON_ERROR),
                ]],
                'isError' => true,
            ]),
        ], logger: $logger);

        try {
            $client->callTool('resolve_feature', []);
            $this->fail('Expected a typed tool exception.');
        } catch (ToolException $exception) {
            $serialized = json_encode([
                'exception' => $exception,
                'response' => $exception->responseBody,
                'logger' => $logger->records,
            ], JSON_THROW_ON_ERROR);

            $this->assertSame('FUTURE_CONFLICT', $exception->errorCode);
            $this->assertNotInstanceOf(ExpectedToolException::class, $exception);
            $this->assertSame(409, $exception->statusCode);
            $this->assertSame('corr-future', $exception->correlationId);
            $this->assertNotNull($exception->recoveryInstruction);
            $this->assertLessThanOrEqual(2_048, strlen($exception->recoveryInstruction));
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($this->token(), $exception->getMessage());
            $this->assertStringNotContainsString($this->token(), $serialized);
            $this->assertSame(['echo' => '[redacted]'], $exception->details['[redacted]'] ?? null);
            $this->assertSame(
                ['echo' => '[redacted]'],
                $exception->responseBody['error']['details']['[redacted]'] ?? null,
            );
            $lastRecord = $logger->last();
            $this->assertSame('acceptora_verification.request_failed', $lastRecord['context']['event']);
            $this->assertOperationalContext($lastRecord['context'], 'POST /mcp', 'tool_error');
        }
    }

    public function test_http_errors_are_typed_retry_aware_and_secret_safe(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->jsonResponse(429, ['error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Slow down '.$this->token(),
                'http_status' => 429,
                'retryable' => true,
                'correlation_id' => 'corr-rate',
                'recovery_instruction' => 'Retry safely without '.$this->token(),
                'details' => ['Authorization' => 'Bearer '.$this->token(), 'echo' => $this->token()],
            ]], ['Retry-After' => '17']),
        ], logger: $logger);

        try {
            $client->checkCompletionGate([]);
            $this->fail('Expected a rate-limit exception.');
        } catch (RateLimitException $exception) {
            $serialized = json_encode([
                'exception' => $exception,
                'response' => $exception->responseBody,
                'logger' => $logger->records,
            ], JSON_THROW_ON_ERROR);

            $this->assertSame(17, $exception->retryAfter);
            $this->assertTrue($exception->retryable);
            $this->assertSame('Retry safely without [redacted]', $exception->recoveryInstruction);
            $this->assertStringNotContainsString($this->token(), $exception->getMessage());
            $this->assertStringNotContainsString($this->token(), $serialized);
            $this->assertOperationalContext($logger->records[0]['context'], 'POST /api/integrations/completion-gate', 'http_error');
            $this->assertSame(429, $logger->records[0]['context']['status_code']);
        }

        $unknown = $this->client([$this->jsonResponse(409, ['error' => [
            'code' => 'FUTURE_CONFLICT',
            'message' => 'A future contract conflict.',
            'http_status' => 409,
            'retryable' => false,
            'correlation_id' => 'corr-unknown',
            'details' => [],
        ]])]);

        try {
            $unknown->checkCompletionGate([]);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame('FUTURE_CONFLICT', $exception->errorCode);
            $this->assertNotInstanceOf(ExpectedApiException::class, $exception);
        }

        $known = $this->client([$this->jsonResponse(409, ['error' => [
            'code' => 'REVISION_CONFLICT',
            'message' => 'The source revision changed.',
            'http_status' => 409,
            'retryable' => false,
            'correlation_id' => 'corr-known',
            'details' => [],
        ]])]);

        try {
            $known->checkCompletionGate([]);
            $this->fail('Expected a known domain conflict.');
        } catch (ApiException $exception) {
            $this->assertInstanceOf(ExpectedApiException::class, $exception);
            $this->assertSame('REVISION_CONFLICT', $exception->errorCode);
        }

        $unavailable = $this->client([$this->jsonResponse(503, ['error' => [
            'code' => 'SERVICE_UNAVAILABLE',
            'message' => 'Temporarily unavailable.',
            'http_status' => 503,
            'retryable' => true,
            'correlation_id' => 'corr-unavailable',
            'details' => [],
        ]])]);

        try {
            $unavailable->checkCompletionGate([]);
            $this->fail('Expected a service-unavailable API exception.');
        } catch (ApiException $exception) {
            $this->assertNotInstanceOf(ExpectedApiException::class, $exception);
            $this->assertSame(503, $exception->statusCode);
        }
    }

    public function test_mcp_rate_limits_use_the_retry_aware_exception_type(): void
    {
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->jsonResponse(200, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'error' => [
                    'code' => -32029,
                    'message' => 'Rate limited.',
                    'data' => ['error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Slow down.',
                        'http_status' => 429,
                        'retryable' => true,
                    ]],
                ],
            ]),
        ]);

        try {
            $client->getVerificationStatus([]);
            $this->fail('Expected an MCP rate-limit exception.');
        } catch (RateLimitException $exception) {
            $this->assertSame(429, $exception->statusCode);
            $this->assertTrue($exception->retryable);
        }
    }

    public function test_fallback_json_rpc_errors_are_bounded_and_secret_safe(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->jsonResponse(200, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'error' => [
                    'code' => 'FUTURE_'.$this->token(),
                    'message' => str_repeat('x', 5_000).$this->token(),
                    'data' => ['access_token' => $this->token(), 'echo' => $this->token()],
                ],
            ]),
        ], logger: $logger);

        try {
            $client->callTool(Client::TOOL_GET_VERIFICATION_STATUS, []);
            $this->fail('Expected a JSON-RPC tool exception.');
        } catch (ToolException $exception) {
            $serialized = json_encode(['exception' => $exception, 'logger' => $logger->records], JSON_THROW_ON_ERROR);

            $this->assertLessThanOrEqual(4_096, strlen($exception->getMessage()));
            $this->assertStringNotContainsString($this->token(), $serialized);
            $this->assertSame('[redacted]', $exception->details['access_token']);
            $this->assertSame('[redacted]', $exception->details['echo']);
            $lastRecord = $logger->last();
            $this->assertOperationalContext($lastRecord['context'], 'POST /mcp', 'rpc_error');
        }
    }

    public function test_decoded_error_exceptions_do_not_retain_remote_tokens_in_native_traces(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            $httpClient = $this->client([
                $this->jsonResponse(429, ['error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Rejected '.$this->token(),
                    'http_status' => 429,
                    'details' => [$this->token() => $this->token()],
                ]]),
            ]);
            $toolClient = $this->client([
                $this->initializeResponse(1),
                new Response(202),
                $this->rpcResponse(2, [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode(['error' => [
                            'code' => 'FEATURE_ARCHIVED',
                            'message' => 'Rejected '.$this->token(),
                            'http_status' => 409,
                            'details' => [$this->token() => $this->token()],
                        ]], JSON_THROW_ON_ERROR),
                    ]],
                    'isError' => true,
                ]),
            ]);
            $rpcClient = $this->client([
                $this->initializeResponse(1),
                new Response(202),
                $this->jsonResponse(200, [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Rejected '.$this->token(),
                        'data' => [$this->token() => $this->token()],
                    ],
                ]),
            ]);

            foreach ([
                static fn () => $httpClient->checkCompletionGate([]),
                static fn () => $toolClient->getFeatureContext([]),
                static fn () => $rpcClient->getFeatureContext([]),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected a decoded remote error.');
                } catch (VerificationException $exception) {
                    $this->assertStringNotContainsString($this->token(), serialize($exception));
                    $this->assertStringNotContainsString($this->token(), var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_configuration_rejections_are_natively_serializable_without_secret_arguments(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $token = $this->token();

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            try {
                new Client(
                    token: $token,
                    baseUrl: 'https://'.$token.'.example.com',
                    userAgent: 'acceptora '.$token,
                );
                $this->fail('Expected invalid client configuration.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString($token, serialize($exception));
                $this->assertStringNotContainsString($token, var_export($exception->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_response_validation_failures_do_not_retain_remote_tokens_in_native_traces(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => static fn (): bool => false,
            'read' => function (int $length): string {
                throw new RuntimeException('Stream failed with '.$this->token().' at '.$length);
            },
        ]);
        $semanticPayload = [
            'data' => [
                'contract_version' => null,
                'integration_version' => '1.0.0',
                'skill_version' => '1.1.0',
                'schema_registry' => 'contracts/v1/mcp-tools.json',
                $this->token() => $this->token(),
            ],
        ];
        $cases = [
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], '{"echo":"'.$this->token().'"')]), InvalidResponseException::class, 'contractInfo'],
            [$this->client([new Response(200, ['Content-Type' => 'text/event-stream'], 'data: {"echo":"'.$this->token().'"')]), InvalidResponseException::class, 'contractInfo'],
            [$this->client([new Response(200, ['Content-Length' => '4096'], $this->token())], maxResponseBytes: 1_024), InvalidResponseException::class, 'contractInfo'],
            [$this->client([new Response(200, [], $this->token().str_repeat('x', 1_024))], maxResponseBytes: 1_024), InvalidResponseException::class, 'contractInfo'],
            [$this->client([new Response(200, [], $stream)]), TransportException::class, 'contractInfo'],
            [$this->client([$this->jsonResponse(200, $semanticPayload)]), InvalidResponseException::class, 'contractInfo'],
            [$this->client([
                $this->initializeResponse(1),
                new Response(202),
                $this->jsonResponse(200, [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'result' => [$this->token()],
                ]),
            ]), InvalidResponseException::class, 'getFeatureContext'],
        ];

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            foreach ($cases as [$client, $expectedException, $method]) {
                try {
                    $method === 'contractInfo' ? $client->contractInfo() : $client->getFeatureContext([]);
                    $this->fail('Expected response validation to fail.');
                } catch (VerificationException $exception) {
                    $this->assertInstanceOf($expectedException, $exception);
                    $this->assertStringNotContainsString($this->token(), serialize($exception));
                    $this->assertStringNotContainsString($this->token(), var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_external_inputs_do_not_retain_tokens_in_native_traces_or_serialization(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $token = $this->token();
        $localLimitClient = $this->client([], maxRequestBytes: 1_024);
        $remoteFailureClient = $this->client([
            $this->jsonResponse(400, ['error' => [
                'code' => 'INVALID_REQUEST',
                'message' => 'Invalid request.',
            ]]),
        ]);
        $identifierClient = $this->client([]);

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            foreach ([
                static fn () => $localLimitClient->checkCompletionGate([
                    'secret' => $token,
                    'payload' => str_repeat('x', 1_024),
                ]),
                static fn () => $remoteFailureClient->checkCompletionGate(['secret' => $token]),
                static fn () => $identifierClient->health($token),
                static fn () => $identifierClient->callTool($token, []),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected external input rejection.');
                } catch (\Throwable $exception) {
                    $this->assertStringNotContainsString($this->token(), serialize($exception));
                    $this->assertStringNotContainsString($this->token(), var_export($exception->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
        }
    }

    public function test_plain_http_loopback_requests_ignore_ambient_proxies(): void
    {
        [$process, $pipes, $capturePath, $proxyUrl] = $this->startProxyCapture();
        $targetOrigin = $this->unusedLoopbackOrigin();
        $environment = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'NO_PROXY', 'no_proxy'];
        $previousEnvironment = [];
        $caught = null;

        foreach ($environment as $name) {
            $previousEnvironment[$name] = getenv($name);
            putenv($name.(str_contains($name, 'NO_PROXY') || $name === 'no_proxy' ? '=' : '='.$proxyUrl));
        }

        try {
            $client = new Client(
                token: $this->token(),
                baseUrl: $targetOrigin,
                timeout: 0.5,
                connectTimeout: 0.25,
            );
            $client->checkCompletionGate(['project_id' => 'proj_proxy_test']);
        } catch (\Throwable $exception) {
            $caught = $exception;
        } finally {
            foreach ($previousEnvironment as $name => $value) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
            $capture = is_file($capturePath) ? (string) file_get_contents($capturePath) : '';

            if (is_file($capturePath)) {
                unlink($capturePath);
            }
        }

        $this->assertInstanceOf(TransportException::class, $caught);
        $this->assertStringContainsString('READY', $capture);
        $this->assertStringNotContainsString('REQUEST', $capture);
        $this->assertStringNotContainsString('Authorization', $capture);
        $this->assertStringNotContainsString($this->token(), $capture);
    }

    public function test_remote_errors_redact_high_confidence_github_tokens_and_private_keys(): void
    {
        $githubToken = 'ghp_'.str_repeat('B', 36);
        $privateKeyMarker = '-----'.'BEGIN PRIVATE KEY-----';
        $privateKey = $privateKeyMarker."\nQUJDREVGRw==\n-----END PRIVATE KEY-----";
        $client = $this->client([
            $this->jsonResponse(400, ['error' => [
                'code' => 'INVALID_REQUEST',
                'message' => 'Rejected '.$githubToken,
                'details' => [
                    $githubToken => $githubToken,
                    'github_echo' => $githubToken,
                    'embedded' => 'prefix'.$githubToken.'suffix',
                    'material' => $privateKey,
                ],
            ]]),
        ]);

        try {
            $client->checkCompletionGate(['project_id' => 'proj_redaction_test']);
            $this->fail('Expected a remote error.');
        } catch (ApiException $exception) {
            $serialized = serialize($exception);

            $this->assertSame('Rejected [redacted]', $exception->getMessage());
            $this->assertSame('[redacted]', $exception->details['github_echo']);
            $this->assertSame('prefix[redacted]suffix', $exception->details['embedded']);
            $this->assertSame('[redacted]', $exception->details['material']);
            $this->assertSame('[redacted]', $exception->details['[redacted]']);
            $this->assertStringNotContainsString($githubToken, $serialized);
            $this->assertStringNotContainsString($privateKeyMarker, $serialized);
        }
    }

    public function test_authenticated_transport_errors_do_not_retain_the_guzzle_request_or_token(): void
    {
        $logger = new ArrayLogger;
        $request = new Request('POST', 'https://www.acceptora.com/mcp', [
            'Authorization' => 'Bearer '.$this->token(),
        ]);
        $client = $this->client([
            new ConnectException('Connection failed with '.$this->token(), $request),
        ], logger: $logger);

        try {
            $client->callTool('resolve_feature', []);
            $this->fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            $serialized = json_encode(['exception' => $exception, 'logger' => $logger->records], JSON_THROW_ON_ERROR);

            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($this->token(), $exception->getMessage());
            $this->assertStringNotContainsString($this->token(), $serialized);
            $this->assertOperationalContext($logger->records[0]['context'], 'POST /mcp', 'transport_error');
        }
    }

    public function test_oversized_requests_are_rejected_before_network_access(): void
    {
        $history = new RequestHistory;
        $client = $this->client([], $history, maxRequestBytes: 1_024);

        try {
            $client->checkCompletionGate(['source_manifest' => str_repeat('x', 1_024)]);
            $this->fail('Expected an oversized request rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('byte limit', $exception->getMessage());
        }

        $this->assertCount(0, $history);
    }

    public function test_malformed_oversized_and_redirect_responses_fail_closed_without_retries(): void
    {
        $cases = [
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], '{')]), InvalidResponseException::class],
            [$this->client([new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 1_025))], maxResponseBytes: 1_024), InvalidResponseException::class],
            [$this->client([new Response(302, ['Location' => 'https://example.test'])]), ApiException::class],
        ];

        foreach ($cases as [$client, $expectedException]) {
            try {
                $client->contractInfo();
                $this->fail('Expected the response to fail closed.');
            } catch (\Throwable $exception) {
                $this->assertInstanceOf($expectedException, $exception);
            }
        }
    }

    public function test_non_scalar_json_rpc_ids_fail_as_invalid_responses_without_php_warnings(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->jsonResponse(200, [
                'jsonrpc' => '2.0',
                'id' => ['unexpected'],
                'result' => [
                    'protocolVersion' => Client::MCP_PROTOCOL_VERSION,
                    'capabilities' => [],
                    'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
                ],
            ]),
        ], logger: $logger);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        });

        try {
            $client->listTools('request-invalid-rpc-id');
            $this->fail('Expected a non-scalar JSON-RPC ID to be rejected.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('INVALID_RESPONSE', $exception->errorCode);
            $this->assertSame([], $warnings);
            $this->assertSame('acceptora_verification.invalid_response', $logger->records[0]['context']['event']);
        } finally {
            restore_error_handler();
        }
    }

    public function test_malformed_json_is_never_logged_as_success_and_has_invalid_response_context(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{'),
        ], logger: $logger);

        try {
            $client->contractInfo('request-malformed');
            $this->fail('Expected malformed JSON rejection.');
        } catch (InvalidResponseException) {
            $events = array_column(array_column($logger->records, 'context'), 'event');
            $this->assertNotContains('acceptora_verification.request_completed', $events);
            $this->assertSame(['acceptora_verification.invalid_response'], $events);
            $this->assertOperationalContext($logger->records[0]['context'], 'GET /api/contract-version', 'invalid_response');
            $this->assertSame('request-malformed', $logger->records[0]['context']['request_id']);
            $this->assertSame('request-malformed', $logger->records[0]['context']['correlation_id']);
            $this->assertSame(200, $logger->records[0]['context']['status_code']);
            $this->assertSame('INVALID_RESPONSE', $logger->records[0]['context']['error_code']);
        }
    }

    public function test_valid_json_with_an_invalid_typed_shape_is_not_logged_as_success(): void
    {
        $logger = new ArrayLogger;
        $client = $this->client([
            $this->jsonResponse(200, ['data' => [
                'status' => 'ok',
                'ready' => 'yes',
                'service' => 'acceptora',
                'checked_at' => '2026-08-03T00:00:00+00:00',
                'components' => [],
            ]], ['X-Correlation-ID' => 'corr-invalid-shape']),
        ], logger: $logger);

        try {
            $client->health('request-invalid-shape');
            $this->fail('Expected typed readiness validation to fail.');
        } catch (InvalidResponseException) {
            $this->assertCount(1, $logger->records);
            $this->assertSame('acceptora_verification.invalid_response', $logger->records[0]['context']['event']);
            $this->assertOperationalContext($logger->records[0]['context'], 'GET /api/health', 'invalid_response');
            $this->assertSame('corr-invalid-shape', $logger->records[0]['context']['correlation_id']);
            $this->assertSame(200, $logger->records[0]['context']['status_code']);
        }
    }

    public function test_invalid_named_tool_shapes_fail_closed_before_success_logging(): void
    {
        $logger = new ArrayLogger;
        $output = $this->toolOutputs()[Client::TOOL_RECONCILE_CHECKLIST];
        $output['new_checklist_revision'] = '2';
        $client = $this->client([
            $this->initializeResponse(1),
            new Response(202),
            $this->rpcResponse(2, [
                'content' => [],
                'isError' => false,
                'structuredContent' => $output,
            ]),
        ], logger: $logger);

        try {
            $client->reconcileChecklist([]);
            $this->fail('Expected typed reconciliation validation to fail.');
        } catch (InvalidResponseException) {
            $events = array_column(array_column($logger->records, 'context'), 'event');

            $this->assertSame(2, count(array_filter($events, static fn (string $event): bool => $event === 'acceptora_verification.request_completed')));
            $this->assertSame('acceptora_verification.invalid_response', end($events));
        }
    }

    public function test_body_limit_and_stream_failures_preserve_server_correlation_in_logs(): void
    {
        $oversizedLogger = new ArrayLogger;
        $oversized = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'Content-Length' => '2048',
                'X-Correlation-ID' => 'corr-oversized',
            ], '{}'),
        ], logger: $oversizedLogger, maxResponseBytes: 1_024);

        try {
            $oversized->contractInfo('request-oversized');
            $this->fail('Expected the response limit to fail closed.');
        } catch (InvalidResponseException $exception) {
            $this->assertSame('corr-oversized', $exception->correlationId);
            $this->assertSame('corr-oversized', $oversizedLogger->records[0]['context']['correlation_id']);
            $this->assertSame(200, $oversizedLogger->records[0]['context']['status_code']);
            $this->assertOperationalContext($oversizedLogger->records[0]['context'], 'GET /api/contract-version', 'invalid_response');
        }

        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => static fn (): bool => false,
            'read' => static function (): never {
                throw new RuntimeException('Unsafe stream detail must not be retained.');
            },
        ]);
        $streamLogger = new ArrayLogger;
        $streamFailure = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Correlation-ID' => 'corr-stream',
            ], $stream),
        ], logger: $streamLogger);

        try {
            $streamFailure->contractInfo('request-stream');
            $this->fail('Expected the response stream failure.');
        } catch (TransportException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertSame('corr-stream', $exception->correlationId);
            $this->assertSame('corr-stream', $streamLogger->records[0]['context']['correlation_id']);
            $this->assertSame(200, $streamLogger->records[0]['context']['status_code']);
            $this->assertOperationalContext($streamLogger->records[0]['context'], 'GET /api/contract-version', 'transport_error');
        }
    }

    public function test_secret_shaped_request_and_response_identifiers_never_enter_logs(): void
    {
        $logger = new ArrayLogger;
        $embeddedToken = 'request-'.$this->token();
        $client = $this->client([
            $this->healthResponse(headers: ['X-Correlation-ID' => $embeddedToken]),
        ], logger: $logger);

        $health = $client->health('request-safe');

        $this->assertSame('request-safe', $health->correlationId());
        $this->assertSame('request-safe', $logger->records[0]['context']['correlation_id']);
        $this->assertStringNotContainsString($this->token(), json_encode($logger->records, JSON_THROW_ON_ERROR));

        try {
            $client->health($embeddedToken);
            $this->fail('Expected a secret-shaped request ID rejection.');
        } catch (InvalidArgumentException) {
            $this->assertCount(1, $logger->records);
        }
    }

    public function test_token_provider_failures_remain_reportable_operational_exceptions(): void
    {
        $logger = new ArrayLogger;
        $failure = new RuntimeException('Secret manager rejected '.$this->token());
        $client = new Client(
            token: static fn (): string => throw $failure,
            httpClient: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler)]),
            logger: $logger,
        );

        try {
            $client->checkCompletionGate([]);
            $this->fail('Expected the token provider failure.');
        } catch (TokenResolutionException $exception) {
            $serialized = json_encode(['exception' => $exception, 'logger' => $logger->records], JSON_THROW_ON_ERROR);

            $this->assertNull($exception->getPrevious());
            $this->assertSame('TOKEN_RESOLUTION_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString($this->token(), $serialized);
            $this->assertSame('acceptora_verification.token_resolution_failed', $logger->records[0]['context']['event']);
        }
    }

    public function test_lazy_tokens_are_not_resolved_for_public_endpoints(): void
    {
        $resolved = 0;
        $history = new RequestHistory;
        $mock = new MockHandler([$this->healthResponse(), $this->completionGateResponse()]);
        $stack = HandlerStack::create($mock);
        $transactions = &$history->container();
        $stack->push(Middleware::history($transactions));
        $client = new Client(
            token: function () use (&$resolved): string {
                $resolved++;

                return $this->token();
            },
            httpClient: new GuzzleClient(['handler' => $stack]),
        );

        $client->health();
        $this->assertSame(0, $resolved);
        $client->checkCompletionGate([]);
        $this->assertSame(1, $resolved);
    }

    public function test_public_endpoints_allow_an_explicitly_tokenless_client(): void
    {
        $mock = new MockHandler([$this->contractResponse()]);
        $client = new Client(token: null, httpClient: new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        $this->assertSame('1.0.0', $client->contractInfo()->contractVersion());

        $this->expectException(AuthenticationException::class);
        $client->checkCompletionGate([]);
    }

    public function test_preflight_stops_on_readiness_version_and_tool_registry_mismatches(): void
    {
        $preflightLogger = new ArrayLogger;
        $notReady = $this->client([$this->healthResponse(503, false)], logger: $preflightLogger);

        try {
            $notReady->preflight('request-not-ready');
            $this->fail('Expected readiness to block preflight.');
        } catch (CompatibilityException $exception) {
            $this->assertSame('SERVICE_UNAVAILABLE', $exception->errorCode);
            $this->assertTrue($exception->retryable);
            $this->assertSame('acceptora_verification.preflight_failed', $preflightLogger->records[1]['context']['event']);
            $this->assertOperationalContext($preflightLogger->records[1]['context'], 'verification_preflight', 'compatibility_error');
        }

        $wrongContract = $this->client([
            $this->healthResponse(),
            $this->contractResponse(contractVersion: '2.0.0'),
        ]);

        try {
            $wrongContract->preflight('request-contract-mismatch');
            $this->fail('Expected contract mismatch to block preflight.');
        } catch (CompatibilityException $exception) {
            $this->assertSame('CONTRACT_UNSUPPORTED', $exception->errorCode);
            $this->assertInstanceOf(ContractMismatchException::class, $exception);
        }

        $wrongSkill = $this->client([
            $this->healthResponse(),
            $this->contractResponse(skillVersion: '1.0.0'),
        ]);

        try {
            $wrongSkill->preflight('request-skill-mismatch');
            $this->fail('Expected skill mismatch to block preflight.');
        } catch (CompatibilityException $exception) {
            $this->assertSame('CONTRACT_UNSUPPORTED', $exception->errorCode);
            $this->assertInstanceOf(ContractMismatchException::class, $exception);
        }

        $wrongTools = $this->client([
            $this->healthResponse(),
            $this->contractResponse(),
            $this->initializeResponse(1),
            new Response(202),
            $this->rpcResponse(2, ['tools' => []]),
        ]);

        try {
            $wrongTools->preflight('request-tools-mismatch');
            $this->fail('Expected tool mismatch to block preflight.');
        } catch (CompatibilityException $exception) {
            $this->assertSame('CONTRACT_UNSUPPORTED', $exception->errorCode);
            $this->assertInstanceOf(ContractMismatchException::class, $exception);
            $this->assertSame(Client::SUPPORTED_TOOLS, $exception->details['expected_tools']);
        }
    }

    public function test_preflight_succeeds_when_manifest_versions_tools_and_schema_digests_match(): void
    {
        $inputSchema = [
            'type' => 'object',
            'properties' => ['project_id' => ['type' => 'string']],
            'required' => ['project_id'],
        ];
        $outputSchema = [
            'type' => 'object',
            'properties' => ['correlation_id' => ['type' => 'string']],
            'required' => ['correlation_id'],
        ];
        $manifestData = ContractManifest::bundled()->toArray();
        $manifestData['tools'] = array_map(fn (string $name): array => [
            'name' => $name,
            'read_only' => Client::READ_ONLY_TOOLS[$name],
            'required_scope' => Client::REQUIRED_SCOPES[$name],
            'input_schema_sha256' => $this->digest($inputSchema),
            'output_schema_sha256' => $this->digest($outputSchema),
        ], Client::SUPPORTED_TOOLS);
        $definitions = array_map(fn (string $name): array => [
            'name' => $name,
            'inputSchema' => $inputSchema,
            'outputSchema' => $outputSchema,
            'annotations' => ['readOnlyHint' => Client::READ_ONLY_TOOLS[$name]],
        ], Client::SUPPORTED_TOOLS);
        $client = $this->client([
            $this->healthResponse(),
            $this->contractResponse(),
            $this->initializeResponse(1, ['MCP-Session-Id' => 'session-preflight']),
            new Response(202),
            $this->rpcResponse(2, ['tools' => $definitions]),
        ], contractManifest: new ContractManifest($manifestData));

        $result = $client->preflight('request-compatible');

        $this->assertTrue($result->health->ready());
        $this->assertSame('1.0.0', $result->contract->contractVersion());
        $this->assertSame('1.0.0', $result->contract->integrationVersion());
        $this->assertSame('1.1.0', $result->contract->skillVersion());
        $this->assertSame(Client::MCP_PROTOCOL_VERSION, $result->protocolVersion);
        $this->assertSame(Client::SUPPORTED_TOOLS, array_map(
            static fn ($tool): string => $tool->name,
            $result->tools,
        ));
    }

    public function test_configuration_and_unsupported_tools_are_rejected_before_network_access(): void
    {
        try {
            new Client('invalid-token');
            $this->fail('Expected invalid token rejection.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            fn (): Client => new Client($this->token(), baseUrl: 'http://example.com'),
            fn (): Client => new Client($this->token(), baseUrl: 'https://user@example.com'),
            fn (): Client => new Client($this->token(), baseUrl: 'https://example.com?token=value'),
            fn (): Client => new Client($this->token(), baseUrl: 'https://example.com/api'),
            fn (): Client => new Client($this->token(), baseUrl: "https://example.com\n"),
            fn (): Client => new Client($this->token(), baseUrl: "https://example.com/\x07"),
            fn (): Client => new Client($this->token(), baseUrl: 'https://example.com:'),
            fn (): Client => new Client($this->token(), baseUrl: 'https://example.com:bad'),
            fn (): Client => new Client($this->token(), baseUrl: 'https://example.com/unsafe\\path'),
            fn (): Client => new Client($this->token(), userAgent: "acceptora\r\ninjected"),
            fn (): Client => new Client($this->token(), userAgent: "acceptora\x07invalid"),
            fn (): Client => new Client($this->token(), userAgent: "acceptora\x7Finvalid"),
            fn (): Client => new Client($this->token(), userAgent: str_repeat('a', 256)),
            fn (): Client => new Client($this->token(), userAgent: ' acceptora'),
            fn (): Client => new Client($this->token(), userAgent: 'acceptora '),
            fn (): Client => new Client($this->token(), userAgent: 'acceptora '.$this->token()),
            fn (): Client => new Client($this->token(), timeout: 0),
            fn (): Client => new Client($this->token(), timeout: NAN),
            fn (): Client => new Client($this->token(), timeout: INF),
            fn (): Client => new Client($this->token(), connectTimeout: NAN),
            fn (): Client => new Client($this->token(), connectTimeout: INF),
            fn (): Client => new Client($this->token(), maxRequestBytes: 10),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Expected invalid client configuration.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertInstanceOf(Client::class, new Client($this->token(), baseUrl: 'http://[::1]'));
        $this->assertInstanceOf(Client::class, new Client($this->token(), baseUrl: 'https://www.acceptora.com/'));
        $this->assertInstanceOf(Client::class, new Client($this->token(), baseUrl: 'HTTPS://www.acceptora.com'));
        $this->assertInstanceOf(Client::class, new Client($this->token(), userAgent: "acceptora\tclient"));

        $history = new RequestHistory;
        $client = $this->client([], $history);

        try {
            $client->callTool('delete_human_feedback', []);
            $this->fail('Expected unsupported tool rejection.');
        } catch (InvalidArgumentException) {
            $this->assertCount(0, $history);
        }
    }

    public function test_newline_terminated_tokens_are_rejected_before_network_access_without_disclosure(): void
    {
        $token = $this->token();
        $history = new RequestHistory;
        $mock = new MockHandler([]);
        $stack = HandlerStack::create($mock);
        $transactions = &$history->container();
        $stack->push(Middleware::history($transactions));
        $client = new Client(
            token: static fn (): string => $token."\n",
            httpClient: new GuzzleClient(['handler' => $stack]),
        );

        try {
            $client->projectMetadata();
            $this->fail('Expected newline-terminated token rejection.');
        } catch (AuthenticationException $exception) {
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertCount(0, $history);
        }
    }

    public function test_token_shaped_base_urls_are_rejected_before_network_access(): void
    {
        try {
            new Client(
                token: $this->token(),
                baseUrl: 'https://'.$this->token().'.example.com',
            );
            $this->fail('Expected token-bearing base URL rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString($this->token(), $exception->getMessage());
        }
    }

    public function test_project_metadata_uses_the_scoped_rest_endpoint_and_returns_a_typed_result(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, [
                'project_id' => 'proj_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'name' => 'Example project',
                'source_kind' => 'git',
                'platform' => 'web',
                'default_environment' => 'production',
                'verification_policy' => ['verification_enabled' => true],
                'connection_status' => 'connected',
                'granted_scopes' => ['projects:read', 'features:read'],
                'credential_expires_at' => null,
                'versions' => ['contract_version' => '1.0.0'],
                'endpoints' => ['mcp' => 'https://www.acceptora.com/mcp'],
                'supported_clients' => ['codex', 'claude', 'gemini'],
                'correlation_id' => 'corr-project',
            ], ['X-Correlation-ID' => 'corr-header']),
        ], $history);

        $result = $client->projectMetadata('request-project');

        $this->assertSame('proj_01ARZ3NDEKTSV4RRFFQ69G5FAV', $result->projectId());
        $this->assertSame('Example project', $result->name());
        $this->assertSame('corr-project', $result->correlationId());
        $this->assertSame('/api/v1/integrations/project', $history->request(0)->getUri()->getPath());
        $this->assertSame('GET', $history->request(0)->getMethod());
        $this->assertSame('Bearer '.$this->token(), $history->request(0)->getHeaderLine('Authorization'));
    }

    public function test_rest_operations_use_the_canonical_routes_and_add_versions_without_mcp_initialization(): void
    {
        $history = new RequestHistory;
        $outputs = $this->toolOutputs();
        $responses = [];

        foreach (array_keys(Client::REST_OPERATION_PATHS) as $operation) {
            $responses[] = $this->jsonResponse(200, $outputs[$operation]);
        }

        $client = $this->client($responses, $history);
        $index = 0;

        foreach (Client::REST_OPERATION_PATHS as $operation => $path) {
            $result = $client->callRestOperation($operation, ['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);
            $request = $history->request($index++);
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($operation, $result->operationName);
            $this->assertSame($path, $request->getUri()->getPath());
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(Client::CONTRACT_VERSION, $payload['versions']['contract_version']);
            $this->assertSame(Client::SKILL_VERSION, $payload['versions']['skill_version']);
            $this->assertSame('acceptora-verification-laravel', $payload['versions']['integration_name']);
        }

        $this->assertCount(8, $history);
    }

    public function test_named_rest_method_returns_the_existing_typed_result_without_an_mcp_session(): void
    {
        $history = new RequestHistory;
        $client = $this->client([
            $this->jsonResponse(200, $this->toolOutputs()[Client::TOOL_GET_VERIFICATION_STATUS]),
        ], $history);

        $result = $client->getVerificationStatusRest([
            'feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ], 'request-status-rest');

        $this->assertSame('verification_in_progress', $result->featureStatus());
        $this->assertSame('/api/v1/integrations/status', $history->request(0)->getUri()->getPath());
        $this->assertFalse($history->request(0)->hasHeader('MCP-Session-Id'));
        $this->assertCount(1, $history);
    }

    public function test_unknown_rest_operations_are_rejected_before_network_access(): void
    {
        $history = new RequestHistory;
        $client = $this->client([], $history);

        try {
            $client->callRestOperation('accept_human_decision', ['feature_id' => 'feat_01ARZ3NDEKTSV4RRFFQ69G5FAV']);
            $this->fail('Expected unsupported REST operation rejection.');
        } catch (InvalidArgumentException) {
            $this->assertCount(0, $history);
        }
    }

    /** @return array{resource, array<int, resource>, string, string} */
    private function startProxyCapture(): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage ?? 'Unable to open the proxy-capture listener.');
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'acceptora-proxy-');
        $this->assertIsString($capturePath);
        $server = <<<'PHP'
$capturePath = $argv[1];
$port = (int) $argv[2];
$listener = @stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);

if (! is_resource($listener)) {
    file_put_contents($capturePath, 'BIND_FAILED '.$errorCode.' '.$errorMessage);
    exit(2);
}

file_put_contents($capturePath, "READY\n");
$connection = @stream_socket_accept($listener, 1);

if (is_resource($connection)) {
    stream_set_timeout($connection, 1);
    $request = '';

    while (! feof($connection) && ! str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8_192);

        if (! is_string($chunk) || $chunk === '') {
            break;
        }

        $request .= $chunk;
    }

    file_put_contents($capturePath, "REQUEST\n".$request, FILE_APPEND);
    fwrite($connection, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
}

fclose($listener);
PHP;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $server, '--', $capturePath, (string) $port],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        $ready = false;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            clearstatcache(true, $capturePath);
            $contents = is_file($capturePath) ? (string) file_get_contents($capturePath) : '';

            if (str_contains($contents, 'READY')) {
                $ready = true;
                break;
            }

            usleep(10_000);
        }

        if (! $ready) {
            proc_terminate($process);

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
            $failure = is_file($capturePath) ? (string) file_get_contents($capturePath) : 'Proxy capture did not start.';

            if (is_file($capturePath)) {
                unlink($capturePath);
            }

            $this->fail($failure);
        }

        return [$process, $pipes, $capturePath, 'http://127.0.0.1:'.$port];
    }

    private function unusedLoopbackOrigin(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage ?? 'Unable to allocate an unused loopback origin.');
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);

        return 'http://'.$address;
    }

    /**
     * @param  list<Response|\Throwable>  $queue
     */
    private function client(
        array $queue,
        ?RequestHistory $history = null,
        ?ArrayLogger $logger = null,
        int $maxRequestBytes = 2_000_000,
        int $maxResponseBytes = 4_000_000,
        int $maxToolPages = 20,
        ?ContractManifest $contractManifest = null,
    ): Client {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $history ??= new RequestHistory;
        $transactions = &$history->container();
        $stack->push(Middleware::history($transactions));

        return new Client(
            token: $this->token(),
            httpClient: new GuzzleClient(['handler' => $stack]),
            logger: $logger,
            maxRequestBytes: $maxRequestBytes,
            maxResponseBytes: $maxResponseBytes,
            maxToolPages: $maxToolPages,
            contractManifest: $contractManifest,
        );
    }

    private function token(): string
    {
        return 'avt_01ARZ3NDEKTSV4RRFFQ69G5FAV_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    }

    /** @param array<string, string> $headers */
    private function healthResponse(int $status = 200, bool $ready = true, array $headers = []): Response
    {
        return $this->jsonResponse($status, [
            'data' => [
                'status' => $ready ? 'ok' : 'degraded',
                'ready' => $ready,
                'service' => 'acceptora',
                'checked_at' => '2026-08-03T00:00:00+00:00',
                'components' => ['database' => ['status' => $ready ? 'healthy' : 'unhealthy', 'required' => true]],
            ],
            'meta' => ['contract_version' => '1.0.0'],
        ], $headers);
    }

    private function contractResponse(string $contractVersion = '1.0.0', string $skillVersion = '1.1.0'): Response
    {
        return $this->jsonResponse(200, [
            'data' => [
                'contract_version' => $contractVersion,
                'integration_version' => '1.0.0',
                'skill_version' => $skillVersion,
                'schema_registry' => 'contracts/v1/mcp-tools.json',
            ],
            'meta' => ['contract_version' => $contractVersion],
        ]);
    }

    private function completionGateResponse(): Response
    {
        return $this->jsonResponse(200, [
            'outcome' => 'pass',
            'feature_id' => 'feat_01J00000000000000000000001',
            'reason_code' => 'SYNCHRONIZED',
            'reason' => 'The final source is synchronized.',
            'last_synchronized_digest' => 'sha256:'.str_repeat('a', 64),
            'last_synchronized_checklist_revision' => 3,
            'recovery_instruction' => null,
            'correlation_id' => 'corr-gate',
        ], ['X-Correlation-ID' => 'corr-gate']);
    }

    /** @param array<string, string> $headers */
    private function initializeResponse(int $id, array $headers = []): Response
    {
        return $this->rpcResponse($id, [
            'protocolVersion' => Client::MCP_PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'Acceptora Verification', 'version' => '1.0.0'],
        ], $headers);
    }

    /** @return array<string, array<string, mixed>> */
    private function toolOutputs(): array
    {
        $correlation = ['correlation_id' => 'corr-tool'];

        return [
            'resolve_feature' => [
                'feature_id' => 'feat_01J00000000000000000000001', 'canonical_title' => 'Feature',
                'resolution_kind' => 'explicit', 'feature_url' => 'https://www.acceptora.com/features/1',
                'status' => 'drafting', 'registered_aliases' => [], 'candidates' => [],
                'current_checklist_revision' => 1, 'open_feedback_count' => 0, ...$correlation,
            ],
            'get_feature_context' => [
                'feature' => [
                    'feature_id' => 'feat_01J00000000000000000000001',
                    'canonical_title' => 'Feature',
                    'intent_summary' => 'Verify the release.',
                    'status' => 'drafting',
                    'feature_url' => 'https://www.acceptora.com/features/1',
                ], 'current_checklist_revision' => 1, 'active_items' => [], 'retired_items' => [],
                'decisions' => [], 'unresolved_feedback' => [], 'previous_source_descriptor' => null,
                'previous_source_digest' => null, 'known_limits' => [], 'project_rules' => [],
                'concurrency_token' => 'revision:1', ...$correlation,
            ],
            'reconcile_checklist' => [
                'feature_id' => 'feat_01J00000000000000000000001', 'feature_url' => 'https://www.acceptora.com/features/1',
                'source_revision_id' => 'src_01J00000000000000000000001', 'source_digest' => 'sha256:'.str_repeat('a', 64),
                'new_checklist_revision' => 2, 'feature_status' => 'verification_in_progress', 'counts' => [
                    'added' => 0,
                    'materially_updated' => 0,
                    'editorially_updated' => 0,
                    'reopened' => 0,
                    'retained' => 0,
                    'retired' => 0,
                ],
                'items_requiring_recheck' => [], 'open_feedback_count' => 0, 'warnings' => [], 'known_limits' => [],
                'synchronized_at' => '2026-08-03T00:00:00+00:00', 'idempotency_replayed' => false, ...$correlation,
            ],
            'get_verification_feedback' => [
                'feature_id' => 'feat_01J00000000000000000000001', 'current_checklist_revision' => 2,
                'feedback' => [], 'page' => ['next_cursor' => null, 'has_more' => false], ...$correlation,
            ],
            'address_feedback' => [
                'feature_id' => 'feat_01J00000000000000000000001', 'source_digest' => 'sha256:'.str_repeat('a', 64),
                'resolution_ids' => ['resolution_01J00000000000000000000001'],
                'thread_states' => [[
                    'thread_id' => 'thread_01J00000000000000000000001',
                    'thread_version' => 2,
                    'state' => 'fix_submitted',
                ]], 'completion_gate' => 'continue_sync',
                'idempotency_replayed' => false, ...$correlation,
            ],
            'get_verification_status' => [
                'feature_id' => 'feat_01J00000000000000000000001', 'feature_url' => 'https://www.acceptora.com/features/1',
                'feature_status' => 'verification_in_progress', 'current_checklist_revision' => 2,
                'latest_source_revision_id' => 'src_01J00000000000000000000001',
                'latest_source_digest' => 'sha256:'.str_repeat('a', 64), 'counts_by_item_state' => [
                    'pending' => 0,
                    'accepted' => 0,
                    'declined' => 0,
                    'blocked' => 0,
                    'skipped' => 0,
                    'ready_for_recheck' => 0,
                    'retired' => 0,
                ],
                'open_feedback_count' => 0, 'ready_for_recheck_count' => 0, 'source_synchronization_state' => 'synchronized',
                'final_acceptance_status' => 'never_accepted', ...$correlation,
            ],
            'check_completion_gate' => [
                'outcome' => 'pass', 'feature_id' => 'feat_01J00000000000000000000001',
                'reason_code' => 'SYNCHRONIZED', 'reason' => 'Synchronized.',
                'last_synchronized_digest' => 'sha256:'.str_repeat('a', 64),
                'last_synchronized_checklist_revision' => 2, 'recovery_instruction' => null, ...$correlation,
            ],
            'record_verification_exception' => [
                'exception_id' => 'exception_01J00000000000000000000001', 'feature_id' => 'feat_01J00000000000000000000001',
                'feature_url' => 'https://www.acceptora.com/features/1', 'source_digest' => 'sha256:'.str_repeat('a', 64),
                'feature_status' => 'no_manual_verification_required', 'completion_gate' => 'pass',
                'idempotency_replayed' => false, ...$correlation,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function toolDefinition(string $name): array
    {
        return [
            'name' => $name,
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $headers
     */
    private function rpcResponse(int $id, array $result, array $headers = []): Response
    {
        return $this->jsonResponse(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function jsonResponse(int $status, array $payload, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function sseResponse(array $payload, array $headers = []): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'text/event-stream', ...$headers],
            "event: message\ndata: ".json_encode($payload, JSON_THROW_ON_ERROR)."\n\n",
        );
    }

    /** @return array<string, mixed> */
    private function requestPayload(RequestHistory $history, int $index): array
    {
        $payload = json_decode((string) $history->request($index)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);

        return $payload;
    }

    /** @param array<string, mixed> $context */
    private function assertOperationalContext(array $context, string $operation, string $outcome): void
    {
        $this->assertSame($operation, $context['operation']);
        $this->assertSame('acceptora', $context['external_service']);
        $this->assertSame(1, $context['attempt']);
        $this->assertSame('not_retried', $context['retry_state']);
        $this->assertSame($outcome, $context['outcome']);
        $this->assertArrayHasKey('request_id', $context);
    }

    /** @param array<string, mixed> $schema */
    private function digest(array $schema): string
    {
        return 'sha256:'.hash('sha256', json_encode(
            $this->canonicalize($schema),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
