<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;

final class ProjectMetadataResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'project_id', 'name', 'source_kind', 'platform', 'default_environment',
            'verification_policy', 'connection_status', 'granted_scopes',
            'credential_expires_at', 'versions', 'endpoints', 'supported_clients',
            'correlation_id',
        ], $requestId, 'project metadata');
        $projectId = self::requiredString($data, 'project_id', $requestId, 'project metadata');

        if (preg_match('/^proj_[0-9A-HJKMNP-TV-Z]{26}$/D', $projectId) !== 1) {
            throw new InvalidResponseException(
                message: 'The Acceptora project metadata response contains an invalid project ID.',
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        foreach (['name', 'source_kind', 'platform', 'default_environment', 'connection_status', 'correlation_id'] as $field) {
            self::requiredString($data, $field, $requestId, 'project metadata');
        }

        foreach (['verification_policy', 'versions', 'endpoints'] as $field) {
            self::requiredObject($data, $field, $requestId, 'project metadata');
        }

        self::requiredStringList($data, 'granted_scopes', $requestId, 'project metadata');
        self::requiredStringList($data, 'supported_clients', $requestId, 'project metadata');

        if ($data['credential_expires_at'] !== null && ! is_string($data['credential_expires_at'])) {
            throw new InvalidResponseException(
                message: 'The Acceptora project metadata credential expiry must be a string or null.',
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        parent::__construct($data, $requestId, $correlationId);
    }

    public function projectId(): string
    {
        return $this->data['project_id'];
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return $this->data['granted_scopes'];
    }
}
