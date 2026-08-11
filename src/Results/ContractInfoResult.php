<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class ContractInfoResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired(
            $data,
            ['contract_version', 'integration_version', 'skill_version', 'schema_registry'],
            $requestId,
            'contract information',
        );

        foreach (['contract_version', 'integration_version', 'skill_version', 'schema_registry'] as $key) {
            self::requiredString($data, $key, $requestId, 'contract information');
        }

        parent::__construct($data, $requestId, $correlationId);
    }

    public function contractVersion(): string
    {
        return $this->data['contract_version'];
    }

    public function integrationVersion(): string
    {
        return $this->data['integration_version'];
    }

    public function skillVersion(): string
    {
        return $this->data['skill_version'];
    }

    public function schemaRegistry(): string
    {
        return $this->data['schema_registry'];
    }
}
