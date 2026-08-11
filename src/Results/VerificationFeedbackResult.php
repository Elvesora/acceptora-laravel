<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class VerificationFeedbackResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, ['feature_id', 'current_checklist_revision', 'feedback', 'page', 'correlation_id'], $requestId, 'get_verification_feedback');
        self::requiredObjectList($data, 'feedback', $requestId, 'get_verification_feedback');
        parent::__construct($data, $requestId, $correlationId);
    }

    /** @return list<array<string, mixed>> */
    public function feedback(): array
    {
        return $this->data['feedback'];
    }
}
