<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Acceptora\Verification\Laravel\Client;
use PHPUnit\Framework\TestCase;

final class PackageMetadataTest extends TestCase
{
    /** @var list<string> */
    private const ERROR_CODES = [
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

    public function test_composer_metadata_is_standalone_publishable_and_auto_discovered(): void
    {
        $composer = $this->jsonFile(__DIR__.'/../composer.json');

        $this->assertSame('acceptora/verification-laravel', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame('^8.2', $composer['require']['php']);
        $this->assertSame('^12.0 || ^13.0', $composer['require']['illuminate/support']);
        $this->assertSame('^7.9 || ^8.0', $composer['require']['guzzlehttp/guzzle']);
        $this->assertSame('^3.0', $composer['require']['psr/log']);
        $this->assertSame('https://www.acceptora.com/docs', $composer['support']['docs']);
        $this->assertSame('https://github.com/Elvesora/acceptora-laravel/issues', $composer['support']['issues']);
        $this->assertSame('https://github.com/Elvesora/acceptora-laravel', $composer['support']['source']);
        $this->assertArrayNotHasKey('repositories', $composer);
        $this->assertArrayNotHasKey('acceptora/verification-php', $composer['require']);
        $this->assertSame(
            ['Acceptora\\Verification\\Laravel\\VerificationServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
        $this->assertArrayNotHasKey('aliases', $composer['extra']['laravel']);
        $this->assertSame('@composer validate --strict', $composer['scripts']['validate-package']);
        $this->assertSame('@composer audit --locked --no-interaction', $composer['scripts']['security-audit']);

        $attributes = (string) file_get_contents(__DIR__.'/../.gitattributes');
        $this->assertStringContainsString('/.git export-ignore', $attributes);

        foreach (['/.git', '/vendor', '/composer.lock', '/.phpstan.cache', '/.phpunit.cache', '/build', '/tests'] as $excluded) {
            $this->assertContains($excluded, $composer['archive']['exclude']);
        }
    }

    public function test_manifest_has_the_exact_public_machine_contract(): void
    {
        $manifest = $this->jsonFile(__DIR__.'/../resources/contract-manifest.json');

        $this->assertSame(['name' => 'acceptora/verification-laravel', 'version' => '1.0.1'], $manifest['package']);
        $this->assertSame(['version' => '1.0.0'], $manifest['integration']);
        $this->assertSame(['version' => '1.1.0'], $manifest['skill']);
        $this->assertSame(
            ['version' => '1.0.0', 'mcp_protocol_version' => '2025-11-25'],
            $manifest['contract'],
        );
        $this->assertSame(['name' => 'Acceptora Verification', 'version' => '1.0.0'], $manifest['server']);
        $this->assertSame('1.0.1', Client::PACKAGE_VERSION);
        $this->assertSame('1.0.0', Client::CONTRACT_VERSION);
        $this->assertSame('1.0.0', Client::INTEGRATION_VERSION);
        $this->assertSame('1.1.0', Client::SKILL_VERSION);
        $this->assertSame([
            ['name' => 'readiness', 'method' => 'GET', 'path' => '/api/health', 'authentication' => 'none'],
            ['name' => 'contract_info', 'method' => 'GET', 'path' => '/api/contract-version', 'authentication' => 'none'],
            ['name' => 'openapi', 'method' => 'GET', 'path' => '/api/v1/integrations/openapi.json', 'authentication' => 'none'],
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
        ], $manifest['http_endpoints']);
        $this->assertSame(self::ERROR_CODES, $manifest['error_codes']);
        $this->assertSame([
            'resolve_feature',
            'get_feature_context',
            'reconcile_checklist',
            'get_verification_feedback',
            'address_feedback',
            'get_verification_status',
            'check_completion_gate',
            'record_verification_exception',
        ], array_column($manifest['tools'], 'name'));
        $this->assertSame([
            'features:resolve',
            'features:read',
            'checklists:write',
            'feedback:read',
            'feedback:address',
            'features:read',
            'gates:read',
            'exceptions:write',
        ], array_column($manifest['tools'], 'required_scope'));
        $this->assertSame([false, true, false, true, false, true, true, false], array_column($manifest['tools'], 'read_only'));
    }

    public function test_public_documentation_explains_installation_setup_and_transport_boundaries(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../README.md');
        $changelog = (string) file_get_contents(__DIR__.'/../CHANGELOG.md');

        foreach ([
            'composer require acceptora/verification-laravel',
            'Laravel 12 with PHP 8.2, 8.3, 8.4, or 8.5',
            'Laravel 13 with PHP 8.3, 8.4, or 8.5',
            '**Settings > Credentials**',
            '**Settings > Connection**',
            '`projects:read`',
            '`exceptions:write`',
            'shows the secret once',
            'a passing MCP preflight does not prove REST availability',
            'A `404` from the OpenAPI path',
            '`openApiDocument()` and its `paths` and `components.schemas` objects',
            '`listTools()` returns every tool\'s `inputSchema`, `outputSchema`, and annotations',
            'it is not a complete payload schema or evidence that an endpoint is currently available',
            '`evidence_sufficiency`',
            '`missing_credentials`',
            'never creates a human decision or',
            'Laravel does not report them by default',
            'https://www.acceptora.com/contact',
        ] as $requiredPublicGuidance) {
            $this->assertStringContainsString($requiredPublicGuidance, $readme);
        }

        $this->assertStringContainsString('## Usage examples and client methods', $readme);
        $this->assertStringContainsString('without adding a local Acceptora password', $readme);
        $this->assertStringNotContainsString('Creating a credential requires the current Acceptora password', $readme);
        $this->assertStringContainsString('## [1.0.1] - 2026-08-23', $changelog);
        $this->assertStringContainsString('## [1.0.0]', $changelog);
        $this->assertStringNotContainsString('[Unreleased]', $changelog);
        $this->assertDoesNotMatchRegularExpression('/(?:no stable public release|development branches|pending publication)/i', $readme.$changelog);
    }

    public function test_release_assets_do_not_contain_internal_paths_or_working_notes(): void
    {
        $files = [
            'README.md',
            'CHANGELOG.md',
            'CODE_OF_CONDUCT.md',
            'CONTRIBUTING.md',
            'SECURITY.md',
            'LICENSE',
            'composer.json',
            'resources/contract-manifest.json',
            '.github/ISSUE_TEMPLATE/bug_report.yml',
            '.github/ISSUE_TEMPLATE/config.yml',
            '.github/PULL_REQUEST_TEMPLATE.md',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents(__DIR__.'/../'.$file);

            $this->assertDoesNotMatchRegularExpression('~(?:\b[A-Za-z]:[\\\\/]|\\\\\\\\[^\\\\/\s]+[\\\\/][^\\\\/\s]+)~', $contents, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(?:TODO|FIXME|WIP)\b/i', $contents, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(?:internal|staging|Laragon|Bugsnag)\b/i', $contents, $file);
            $this->assertDoesNotMatchRegularExpression('/(?:release candidate|private deployment|acceptora\.local)/i', $contents, $file);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
