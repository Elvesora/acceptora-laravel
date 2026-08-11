<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Support;

use Acceptora\Verification\Laravel\Exceptions\InvalidInputException;
use JsonException;
use RuntimeException;

final class ContractManifest
{
    /** @var array<string, mixed> */
    private readonly array $data;

    /** @param array<mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data)
    {
        if ($data === [] || array_is_list($data)) {
            throw new InvalidInputException('The Acceptora contract manifest must be a JSON object.');
        }

        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidInputException('The Acceptora contract manifest must use string keys.');
            }

            $normalized[$key] = $value;
        }

        $this->data = $normalized;
    }

    public static function bundled(): self
    {
        $path = dirname(__DIR__, 2).'/resources/contract-manifest.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The bundled Acceptora contract manifest could not be read.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The bundled Acceptora contract manifest is not valid JSON.', 0, $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('The bundled Acceptora contract manifest must be a JSON object.');
        }

        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
