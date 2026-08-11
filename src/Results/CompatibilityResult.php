<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

use JsonSerializable;

final class CompatibilityResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $serverInfo
     * @param  list<ToolDefinition>  $tools
     */
    public function __construct(
        #[\SensitiveParameter] public readonly HealthResult $health,
        #[\SensitiveParameter] public readonly ContractInfoResult $contract,
        #[\SensitiveParameter] public readonly array $serverInfo,
        #[\SensitiveParameter] public readonly array $tools,
        #[\SensitiveParameter] public readonly string $protocolVersion,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'compatible' => true,
            'health' => $this->health->toArray(),
            'contract' => $this->contract->toArray(),
            'server_info' => $this->serverInfo,
            'protocol_version' => $this->protocolVersion,
            'tools' => array_map(static fn (ToolDefinition $tool): array => $tool->jsonSerialize(), $this->tools),
        ];
    }
}
