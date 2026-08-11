<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Facades;

use Acceptora\Verification\Laravel\Contracts\VerificationClientInterface;
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
use Acceptora\Verification\Laravel\Results\ToolDefinition;
use Acceptora\Verification\Laravel\Results\ToolResult;
use Acceptora\Verification\Laravel\Results\VerificationExceptionResult;
use Acceptora\Verification\Laravel\Results\VerificationFeedbackResult;
use Acceptora\Verification\Laravel\Results\VerificationStatusResult;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static \Acceptora\Verification\Laravel\Results\HealthResult health(?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ContractInfoResult contractInfo(?string $requestId = null)
 * @method static array<string, mixed> openApiDocument(?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ProjectMetadataResult projectMetadata(?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\CompletionGateResult checkCompletionGate(array<string, mixed> $payload, ?string $requestId = null)
 * @method static list<\Acceptora\Verification\Laravel\Results\ToolDefinition> listTools(?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ToolResult callTool(string $tool, array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\RestOperationResult callRestOperation(string $operation, array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ResolveFeatureResult resolveFeatureRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\FeatureContextResult getFeatureContextRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ReconciliationResult reconcileChecklistRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationFeedbackResult getVerificationFeedbackRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\AddressFeedbackResult addressFeedbackRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationStatusResult getVerificationStatusRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\CompletionGateResult checkCompletionGateRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationExceptionResult recordVerificationExceptionRest(array<string, mixed> $payload, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ResolveFeatureResult resolveFeature(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\FeatureContextResult getFeatureContext(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\ReconciliationResult reconcileChecklist(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationFeedbackResult getVerificationFeedback(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\AddressFeedbackResult addressFeedback(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationStatusResult getVerificationStatus(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\CompletionGateResult checkCompletionGateTool(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\VerificationExceptionResult recordVerificationException(array<string, mixed> $arguments, ?string $requestId = null)
 * @method static \Acceptora\Verification\Laravel\Results\CompatibilityResult preflight(?string $requestId = null)
 */
final class Verification extends Facade
{
    public static function health(#[\SensitiveParameter] ?string $requestId = null): HealthResult
    {
        return self::client()->health($requestId);
    }

    public static function contractInfo(#[\SensitiveParameter] ?string $requestId = null): ContractInfoResult
    {
        return self::client()->contractInfo($requestId);
    }

    /** @return array<string, mixed> */
    public static function openApiDocument(#[\SensitiveParameter] ?string $requestId = null): array
    {
        return self::client()->openApiDocument($requestId);
    }

    public static function projectMetadata(#[\SensitiveParameter] ?string $requestId = null): ProjectMetadataResult
    {
        return self::client()->projectMetadata($requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function checkCompletionGate(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): CompletionGateResult {
        return self::client()->checkCompletionGate($payload, $requestId);
    }

    /** @return list<ToolDefinition> */
    public static function listTools(#[\SensitiveParameter] ?string $requestId = null): array
    {
        return self::client()->listTools($requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function callTool(
        #[\SensitiveParameter] string $tool,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ToolResult {
        return self::client()->callTool($tool, $arguments, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function callRestOperation(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): RestOperationResult {
        return self::client()->callRestOperation($operation, $payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function resolveFeatureRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): ResolveFeatureResult
    {
        return self::client()->resolveFeatureRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function getFeatureContextRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): FeatureContextResult
    {
        return self::client()->getFeatureContextRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function reconcileChecklistRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): ReconciliationResult
    {
        return self::client()->reconcileChecklistRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function getVerificationFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationFeedbackResult
    {
        return self::client()->getVerificationFeedbackRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function addressFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): AddressFeedbackResult
    {
        return self::client()->addressFeedbackRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function getVerificationStatusRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationStatusResult
    {
        return self::client()->getVerificationStatusRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function checkCompletionGateRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): CompletionGateResult
    {
        return self::client()->checkCompletionGateRest($payload, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public static function recordVerificationExceptionRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationExceptionResult
    {
        return self::client()->recordVerificationExceptionRest($payload, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function resolveFeature(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): ResolveFeatureResult
    {
        return self::client()->resolveFeature($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function getFeatureContext(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): FeatureContextResult
    {
        return self::client()->getFeatureContext($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function reconcileChecklist(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): ReconciliationResult
    {
        return self::client()->reconcileChecklist($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function getVerificationFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationFeedbackResult
    {
        return self::client()->getVerificationFeedback($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function addressFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): AddressFeedbackResult
    {
        return self::client()->addressFeedback($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function getVerificationStatus(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationStatusResult
    {
        return self::client()->getVerificationStatus($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function checkCompletionGateTool(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): CompletionGateResult
    {
        return self::client()->checkCompletionGateTool($arguments, $requestId);
    }

    /** @param array<string, mixed> $arguments */
    public static function recordVerificationException(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationExceptionResult
    {
        return self::client()->recordVerificationException($arguments, $requestId);
    }

    public static function preflight(#[\SensitiveParameter] ?string $requestId = null): CompatibilityResult
    {
        return self::client()->preflight($requestId);
    }

    private static function client(): VerificationClientInterface
    {
        $client = self::getFacadeRoot();

        if (! $client instanceof VerificationClientInterface) {
            throw new RuntimeException('The Acceptora verification facade has not been initialized.');
        }

        return $client;
    }

    protected static function getFacadeAccessor(): string
    {
        return VerificationClientInterface::class;
    }
}
