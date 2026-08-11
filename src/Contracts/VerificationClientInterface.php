<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Contracts;

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

interface VerificationClientInterface
{
    public function health(#[\SensitiveParameter] ?string $requestId = null): HealthResult;

    public function contractInfo(#[\SensitiveParameter] ?string $requestId = null): ContractInfoResult;

    /** @return array<string, mixed> */
    public function openApiDocument(#[\SensitiveParameter] ?string $requestId = null): array;

    public function projectMetadata(#[\SensitiveParameter] ?string $requestId = null): ProjectMetadataResult;

    /** @param array<string, mixed> $payload */
    public function checkCompletionGate(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): CompletionGateResult;

    /** @return list<ToolDefinition> */
    public function listTools(#[\SensitiveParameter] ?string $requestId = null): array;

    /** @param array<string, mixed> $arguments */
    public function callTool(
        #[\SensitiveParameter] string $tool,
        #[\SensitiveParameter] array $arguments,
        #[\SensitiveParameter] ?string $requestId = null,
    ): ToolResult;

    /** @param array<string, mixed> $payload */
    public function callRestOperation(
        #[\SensitiveParameter] string $operation,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] ?string $requestId = null,
    ): RestOperationResult;

    /** @param array<string, mixed> $payload */
    public function resolveFeatureRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): ResolveFeatureResult;

    /** @param array<string, mixed> $payload */
    public function getFeatureContextRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): FeatureContextResult;

    /** @param array<string, mixed> $payload */
    public function reconcileChecklistRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): ReconciliationResult;

    /** @param array<string, mixed> $payload */
    public function getVerificationFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationFeedbackResult;

    /** @param array<string, mixed> $payload */
    public function addressFeedbackRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): AddressFeedbackResult;

    /** @param array<string, mixed> $payload */
    public function getVerificationStatusRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationStatusResult;

    /** @param array<string, mixed> $payload */
    public function checkCompletionGateRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): CompletionGateResult;

    /** @param array<string, mixed> $payload */
    public function recordVerificationExceptionRest(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $requestId = null): VerificationExceptionResult;

    /** @param array<string, mixed> $arguments */
    public function resolveFeature(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): ResolveFeatureResult;

    /** @param array<string, mixed> $arguments */
    public function getFeatureContext(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): FeatureContextResult;

    /** @param array<string, mixed> $arguments */
    public function reconcileChecklist(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): ReconciliationResult;

    /** @param array<string, mixed> $arguments */
    public function getVerificationFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationFeedbackResult;

    /** @param array<string, mixed> $arguments */
    public function addressFeedback(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): AddressFeedbackResult;

    /** @param array<string, mixed> $arguments */
    public function getVerificationStatus(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationStatusResult;

    /** @param array<string, mixed> $arguments */
    public function checkCompletionGateTool(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): CompletionGateResult;

    /** @param array<string, mixed> $arguments */
    public function recordVerificationException(#[\SensitiveParameter] array $arguments, #[\SensitiveParameter] ?string $requestId = null): VerificationExceptionResult;

    public function preflight(#[\SensitiveParameter] ?string $requestId = null): CompatibilityResult;
}
