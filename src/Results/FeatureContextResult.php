<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class FeatureContextResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'feature', 'current_checklist_revision', 'active_items', 'retired_items', 'decisions',
            'unresolved_feedback', 'previous_source_descriptor', 'previous_source_digest', 'known_limits',
            'project_rules', 'concurrency_token', 'correlation_id',
        ], $requestId, 'get_feature_context');
        self::requiredObject($data, 'feature', $requestId, 'get_feature_context');

        parent::__construct($data, $requestId, $correlationId);
    }

    /** @return array<string, mixed> */
    public function feature(): array
    {
        return $this->data['feature'];
    }
}
