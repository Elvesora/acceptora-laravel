<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class VerificationStatusResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'feature_id', 'feature_url', 'feature_status', 'current_checklist_revision', 'latest_source_revision_id',
            'latest_source_digest', 'counts_by_item_state', 'open_feedback_count', 'ready_for_recheck_count',
            'source_synchronization_state', 'final_acceptance_status', 'correlation_id',
        ], $requestId, 'get_verification_status');
        self::requiredString($data, 'feature_status', $requestId, 'get_verification_status');
        parent::__construct($data, $requestId, $correlationId);
    }

    public function featureStatus(): string
    {
        return $this->data['feature_status'];
    }
}
