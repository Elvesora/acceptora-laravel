<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class ResolveFeatureResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'feature_id', 'canonical_title', 'resolution_kind', 'feature_url', 'status',
            'registered_aliases', 'candidates', 'current_checklist_revision', 'open_feedback_count', 'correlation_id',
        ], $requestId, 'resolve_feature');
        self::requiredString($data, 'resolution_kind', $requestId, 'resolve_feature');

        parent::__construct($data, $requestId, $correlationId);
    }

    public function featureId(): ?string
    {
        return is_string($this->data['feature_id']) ? $this->data['feature_id'] : null;
    }

    public function resolutionKind(): string
    {
        return $this->data['resolution_kind'];
    }
}
