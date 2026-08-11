<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Acceptora\Verification\Laravel\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContractParityTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    public function test_manifest_and_client_match_authoritative_acceptora_contracts(): void
    {
        $configuredPath = getenv('ACCEPTORA_CONTRACTS_V1_PATH');

        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            self::markTestSkipped('Set ACCEPTORA_CONTRACTS_V1_PATH to run cross-repository contract parity.');
        }

        $contractsPath = realpath($configuredPath);

        if ($contractsPath === false || ! is_file($contractsPath.'/mcp-tools.json')) {
            $this->fail('ACCEPTORA_CONTRACTS_V1_PATH must reference the authoritative contracts/v1 directory.');
        }

        $registry = $this->jsonFile($contractsPath.'/mcp-tools.json');
        $httpApi = $this->jsonFile($contractsPath.'/http-api.json');
        $errorCodes = $this->jsonFile($contractsPath.'/enums/error-code.schema.json');
        $manifest = $this->jsonFile(__DIR__.'/../resources/contract-manifest.json');
        $manifestTools = array_column($manifest['tools'], null, 'name');
        $expectedTools = [];

        foreach ($registry['tools'] as $tool) {
            $name = $tool['name'];
            $expectedTools[$name] = [
                'required_scope' => $tool['required_scope'],
                'read_only' => $tool['read_only'],
                'input_schema_sha256' => $this->schemaDigest($contractsPath, $tool['input_schema']),
                'output_schema_sha256' => $this->schemaDigest($contractsPath, $tool['output_schema']),
            ];
        }

        $actualTools = [];

        foreach ($manifestTools as $name => $tool) {
            $actualTools[$name] = [
                'required_scope' => $tool['required_scope'],
                'read_only' => $tool['read_only'],
                'input_schema_sha256' => $tool['input_schema_sha256'],
                'output_schema_sha256' => $tool['output_schema_sha256'],
            ];
        }

        $this->assertSame((string) $registry['contract_version'], $manifest['contract']['version']);
        $this->assertSame($expectedTools, $actualTools);
        $this->assertSame($errorCodes['enum'], $manifest['error_codes']);
        $this->assertSame($httpApi['schema_version'], $manifest['schema_version']);

        $operations = array_column($httpApi['operations'], null, 'name');
        $manifestEndpoints = array_column($manifest['http_endpoints'], null, 'name');
        $expectedPaths = [];
        $expectedScopes = [];
        $expectedReadOnly = [];

        foreach ($operations as $name => $operation) {
            $expectedEndpoint = [
                'name' => $name,
                'method' => $operation['method'],
                'path' => $operation['path'],
                'authentication' => 'bearer',
                'required_scope' => $operation['required_scope'],
            ];

            $this->assertSame($expectedEndpoint, $manifestEndpoints[$name] ?? null, $name);
            $expectedPaths[$name] = $operation['path'];
            $expectedScopes[$name] = $operation['required_scope'];
            $expectedReadOnly[$name] = $operation['read_only'];
        }

        $this->assertSame([
            ...$httpApi['project'],
            'authentication' => 'bearer',
        ], $manifestEndpoints['project'] ?? null);
        $this->assertSame([
            'name' => 'openapi',
            'method' => 'GET',
            'path' => $httpApi['openapi_path'],
            'authentication' => 'none',
        ], $manifestEndpoints['openapi'] ?? null);

        foreach ($httpApi['legacy_aliases'] as $legacyAlias) {
            $operation = $operations[$legacyAlias['operation']] ?? null;

            if (! is_array($operation)) {
                $this->fail('An authoritative legacy HTTP alias references an unknown operation.');
            }

            $this->assertSame([
                'name' => $legacyAlias['name'],
                'method' => $legacyAlias['method'],
                'path' => $legacyAlias['path'],
                'authentication' => 'bearer',
                'required_scope' => $operation['required_scope'],
            ], $manifestEndpoints[$legacyAlias['name']] ?? null, $legacyAlias['name']);
        }

        $this->assertSame($httpApi['openapi_path'], Client::OPENAPI_PATH);
        $this->assertSame($expectedPaths, Client::REST_OPERATION_PATHS);
        $this->assertSame($expectedScopes, Client::REQUIRED_SCOPES);
        $this->assertSame($expectedReadOnly, Client::READ_ONLY_TOOLS);
    }

    private function schemaDigest(string $contractsPath, string $relativePath): string
    {
        $file = realpath($contractsPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($file === false || ! str_starts_with($file, $contractsPath.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A contract schema path leaves the configured contracts directory.');
        }

        $resolved = $this->resolveValue($this->document($file, $contractsPath), $file, $contractsPath);
        unset($resolved['$schema'], $resolved['$id']);

        $encoded = json_encode(
            $this->sortRecursively($resolved),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'sha256:'.hash('sha256', $encoded);
    }

    /** @return array<string, mixed> */
    private function document(string $file, string $contractsPath): array
    {
        $real = realpath($file);

        if ($real === false || ! str_starts_with($real, $contractsPath.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A contract schema reference leaves the configured contracts directory.');
        }

        if (! isset($this->documents[$real])) {
            $this->documents[$real] = $this->jsonFile($real);
        }

        return $this->documents[$real];
    }

    private function resolveValue(mixed $value, string $currentFile, string $contractsPath): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value['$ref']) && is_string($value['$ref'])) {
            [$relative, $pointer] = array_pad(explode('#', $value['$ref'], 2), 2, '');
            $targetFile = $relative === ''
                ? $currentFile
                : dirname($currentFile).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $target = $this->document($targetFile, $contractsPath);

            if ($pointer !== '') {
                foreach (explode('/', ltrim($pointer, '/')) as $segment) {
                    $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

                    if (! is_array($target) || ! array_key_exists($segment, $target)) {
                        throw new RuntimeException('A contract schema contains an invalid JSON pointer.');
                    }

                    $target = $target[$segment];
                }
            }

            $siblings = $value;
            unset($siblings['$ref']);
            $resolvedTarget = $this->resolveValue($target, realpath($targetFile) ?: $targetFile, $contractsPath);

            return is_array($resolvedTarget)
                ? array_merge($resolvedTarget, $this->resolveValue($siblings, $currentFile, $contractsPath))
                : $resolvedTarget;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->resolveValue($child, $currentFile, $contractsPath);
        }

        return $value;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->sortRecursively(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('The contract file must contain a JSON object.');
        }

        return $decoded;
    }
}
