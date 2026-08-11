<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel;

use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;
use Acceptora\Verification\Laravel\Exceptions\ConfigurationException;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class VerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'acceptora-verification');

        $this->app->scoped(
            VerificationClientInterface::class,
            function (Container $container): VerificationClientInterface {
                $config = $container->make(ConfigRepository::class);

                return new Client(
                    token: static function () use ($config): string {
                        $token = $config->get('acceptora-verification.token');

                        return is_string($token) ? $token : '';
                    },
                    httpClient: new HttpClient,
                    logger: $container->make(LoggerInterface::class),
                    baseUrl: $this->requiredString($config, 'acceptora-verification.base_url'),
                    timeout: $this->requiredFloat($config, 'acceptora-verification.timeout'),
                    connectTimeout: $this->requiredFloat($config, 'acceptora-verification.connect_timeout'),
                    maxRequestBytes: $this->requiredInteger($config, 'acceptora-verification.max_request_bytes'),
                    maxResponseBytes: $this->requiredInteger($config, 'acceptora-verification.max_response_bytes'),
                    maxToolPages: $this->requiredInteger($config, 'acceptora-verification.max_tool_pages'),
                    userAgent: $this->requiredString($config, 'acceptora-verification.user_agent'),
                );
            },
        );

        $this->app->alias(VerificationClientInterface::class, Client::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => config_path('acceptora-verification.php'),
        ], 'acceptora-verification-config');
    }

    private function configPath(): string
    {
        return dirname(__DIR__).'/config/acceptora-verification.php';
    }

    private function requiredString(
        #[\SensitiveParameter] ConfigRepository $config,
        #[\SensitiveParameter] string $key,
    ): string {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf('Acceptora Verification configuration value "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    private function requiredFloat(
        #[\SensitiveParameter] ConfigRepository $config,
        #[\SensitiveParameter] string $key,
    ): float {
        $value = $config->get($key);

        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            throw new ConfigurationException(sprintf('Acceptora Verification configuration value "%s" must be numeric.', $key));
        }

        return (float) $value;
    }

    private function requiredInteger(
        #[\SensitiveParameter] ConfigRepository $config,
        #[\SensitiveParameter] string $key,
    ): int {
        $value = $config->get($key);

        if (! is_int($value) && ! is_numeric($value)) {
            throw new ConfigurationException(sprintf('Acceptora Verification configuration value "%s" must be an integer.', $key));
        }

        return (int) $value;
    }
}
