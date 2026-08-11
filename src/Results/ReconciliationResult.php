<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class ReconciliationResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'feature_id', 'feature_url', 'source_revision_id', 'source_digest', 'new_checklist_revision',
            'feature_status', 'counts', 'items_requiring_recheck', 'open_feedback_count', 'warnings',
            'known_limits', 'synchronized_at', 'idempotency_replayed', 'correlation_id',
        ], $requestId, 'reconcile_checklist');
        self::requiredInteger($data, 'new_checklist_revision', $requestId, 'reconcile_checklist');
        self::requiredBoolean($data, 'idempotency_replayed', $requestId, 'reconcile_checklist');

        parent::__construct($data, $requestId, $correlationId);
    }

    public function checklistRevision(): int
    {
        return $this->data['new_checklist_revision'];
    }

    public function idempotencyReplayed(): bool
    {
        return $this->data['idempotency_replayed'];
    }
}
