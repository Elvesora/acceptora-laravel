<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Acceptora\Verification\Laravel\Client;
use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;
use Acceptora\Verification\Laravel\Exceptions\ApiException;
use Acceptora\Verification\Laravel\Exceptions\AuthenticationException;
use Acceptora\Verification\Laravel\Exceptions\AuthorizationException;
use Acceptora\Verification\Laravel\Exceptions\CompatibilityException;
use Acceptora\Verification\Laravel\Exceptions\ContractMismatchException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedApiException;
use Acceptora\Verification\Laravel\Exceptions\ExpectedToolException;
use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;
use Acceptora\Verification\Laravel\Exceptions\RateLimitException;
use Acceptora\Verification\Laravel\Exceptions\TokenResolutionException;
use Acceptora\Verification\Laravel\Exceptions\ToolException;
use Acceptora\Verification\Laravel\Exceptions\TransportException;
use Acceptora\Verification\Laravel\Facades\Verification;
use Acceptora\Verification\Laravel\Results\HealthResult;
use Acceptora\Verification\Laravel\VerificationServiceProvider;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ReflectionProperty;

final class ServiceProviderTest extends PackageTestCase
{
    public function test_provider_registers_one_client_per_request_or_job_lifecycle(): void
    {
        $application = $this->application();
        $firstLifecycle = $application->make(VerificationClientInterface::class);
        $concrete = $application->make(Client::class);

        $this->assertInstanceOf(Client::class, $firstLifecycle);
        $this->assertSame($firstLifecycle, $concrete);

        $application->forgetScopedInstances();

        $secondLifecycle = $application->make(VerificationClientInterface::class);

        $this->assertInstanceOf(Client::class, $secondLifecycle);
        $this->assertNotSame($firstLifecycle, $secondLifecycle);
        $this->assertSame($secondLifecycle, $application->make(Client::class));
    }

    public function test_default_configuration_is_merged_without_resolving_the_lazy_token(): void
    {
        $this->assertSame('https://www.acceptora.com', config('acceptora-verification.base_url'));
        $this->assertSame(2_000_000, config('acceptora-verification.max_request_bytes'));
        $this->assertSame(4_000_000, config('acceptora-verification.max_response_bytes'));
        $this->assertNull(config('acceptora-verification.token'));

        $this->assertInstanceOf(Client::class, $this->application()->make(VerificationClientInterface::class));
    }

    public function test_facade_resolves_the_interface_binding_without_a_global_alias(): void
    {
        $health = new HealthResult([
            'status' => 'ok',
            'ready' => true,
            'service' => 'acceptora',
            'checked_at' => '2026-08-03T00:00:00+00:00',
            'components' => [],
        ], 'request-facade', 'corr-facade');
        $client = $this->createMock(VerificationClientInterface::class);
        $client->expects($this->once())
            ->method('health')
            ->with('request-facade')
            ->willReturn($health);
        $application = $this->application();
        $application->instance(VerificationClientInterface::class, $client);
        Verification::clearResolvedInstance(VerificationClientInterface::class);
        Verification::setFacadeApplication($application);

        $this->assertSame($health, Verification::health('request-facade'));
    }

    public function test_facade_payload_failures_do_not_retain_secrets_or_use_magic_forwarding(): void
    {
        $previousIgnoreArguments = ini_set('zend.exception_ignore_args', '0');
        $token = 'avt_01ARZ3NDEKTSV4RRFFQ69G5FAV_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $application = $this->application();
        $clients = [
            new Client(
                token: $token,
                httpClient: new GuzzleClient(['handler' => new MockHandler([])]),
                maxRequestBytes: 1_024,
            ),
            new Client(
                token: $token,
                httpClient: new GuzzleClient(['handler' => new MockHandler([
                    new Response(400, ['Content-Type' => 'application/json'], json_encode([
                        'error' => [
                            'code' => 'INVALID_REQUEST',
                            'message' => 'Invalid request.',
                        ],
                    ], JSON_THROW_ON_ERROR)),
                ])]),
            ),
        ];

        $this->assertNotFalse($previousIgnoreArguments);
        $this->assertNotSame('1', ini_get('zend.exception_ignore_args'));

        try {
            foreach ($clients as $index => $client) {
                $application->instance(VerificationClientInterface::class, $client);
                Verification::clearResolvedInstance(VerificationClientInterface::class);
                Verification::setFacadeApplication($application);

                try {
                    Verification::checkCompletionGate([
                        'secret' => $token,
                        'payload' => $index === 0 ? str_repeat('x', 1_024) : 'remote-failure',
                    ]);
                    $this->fail('Expected the facade request to fail.');
                } catch (\Throwable $exception) {
                    $application->forgetInstance(VerificationClientInterface::class);
                    Verification::clearResolvedInstance(VerificationClientInterface::class);
                    $serialized = serialize($exception);
                    $trace = $exception->getTrace();

                    $this->assertStringNotContainsString($token, $serialized);
                    $this->assertStringNotContainsString($token, print_r($trace, true));

                    foreach ($trace as $frame) {
                        $this->assertFalse(
                            ($frame['class'] ?? null) === Facade::class
                            && $frame['function'] === '__callStatic',
                        );
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            Verification::clearResolvedInstance(VerificationClientInterface::class);
        }
    }

    public function test_config_publish_tag_points_to_the_package_configuration(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            VerificationServiceProvider::class,
            'acceptora-verification-config',
        );

        $this->assertCount(1, $paths);
        $this->assertSame(
            realpath(__DIR__.'/../config/acceptora-verification.php'),
            realpath((string) array_key_first($paths)),
        );
        $this->assertSame('acceptora-verification.php', basename((string) reset($paths)));
    }

    public function test_package_configuration_is_serializable_for_config_cache(): void
    {
        $application = $this->application();
        $cachedConfigPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acceptora-verification-config-'.bin2hex(random_bytes(8)).'.php';
        $configuration = $application->make('config')->all();
        $contents = '<?php return '.var_export($configuration, true).';';
        $this->assertGreaterThan(0, file_put_contents($cachedConfigPath, $contents));

        try {
            $cachedConfiguration = require $cachedConfigPath;

            $this->assertIsArray($cachedConfiguration);
            $this->assertSame(
                'https://www.acceptora.com',
                $cachedConfiguration['acceptora-verification']['base_url'] ?? null,
            );
            $this->assertSame(
                20,
                $cachedConfiguration['acceptora-verification']['max_tool_pages'] ?? null,
            );
        } finally {
            if (is_file($cachedConfigPath)) {
                unlink($cachedConfigPath);
            }
        }
    }

    public function test_missing_token_fails_only_when_a_protected_call_is_made(): void
    {
        $client = $this->application()->make(VerificationClientInterface::class);
        $this->assertInstanceOf(Client::class, $client);
        $httpClient = new ReflectionProperty(Client::class, 'httpClient');
        $httpClient->setValue($client, new GuzzleClient(['handler' => new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'status' => 'ok',
                    'ready' => true,
                    'service' => 'acceptora',
                    'checked_at' => '2026-08-03T00:00:00+00:00',
                    'components' => [],
                ],
            ], JSON_THROW_ON_ERROR)),
        ])]));

        $this->assertTrue($client->health()->ready());

        $this->expectException(AuthenticationException::class);
        $client->checkCompletionGate([]);
    }

    public function test_expected_control_flow_is_not_reported_but_operational_defects_are_reportable(): void
    {
        $handler = $this->application()->make(ExceptionHandler::class);
        $expected = [
            new AuthenticationException('Authentication required.', 'AUTH_REQUIRED', 401),
            new AuthorizationException('Scope denied.', 'SCOPE_DENIED', 403),
            new RateLimitException('Rate limited.', 'RATE_LIMITED', 429),
            new ExpectedApiException('Revision conflict.', 'REVISION_CONFLICT', 409),
            new ExpectedToolException('Feature archived.', 'FEATURE_ARCHIVED', 409),
            new ContractMismatchException('Contract unsupported.', 'CONTRACT_UNSUPPORTED', 422),
        ];
        $unexpected = [
            new ApiException('Service unavailable.', 'SERVICE_UNAVAILABLE', 503),
            new ToolException('Tool service unavailable.', 'SERVICE_UNAVAILABLE', 503),
            new TokenResolutionException('Token provider failed.', 'TOKEN_RESOLUTION_FAILED'),
            new CompatibilityException('Manifest invalid.', 'MANIFEST_INVALID'),
            new InvalidResponseException('Malformed response.', 'INVALID_RESPONSE'),
            new TransportException('Transport failed.', 'TRANSPORT_ERROR'),
        ];

        foreach ($expected as $exception) {
            $this->assertFalse($handler->shouldReport($exception), $exception::class);
        }

        foreach ($unexpected as $exception) {
            $this->assertTrue($handler->shouldReport($exception), $exception::class);
        }
    }

    private function application(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The Testbench application is not available.');
        }

        return $this->app;
    }
}
