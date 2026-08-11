<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Countable;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RequestHistory implements Countable
{
    /** @var array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    private array $transactions = [];

    /** @return array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    public function &container(): array
    {
        $this->transactions = [];

        return $this->transactions;
    }

    public function request(int $index): RequestInterface
    {
        $transaction = $this->transactions[$index] ?? null;
        $request = $transaction !== null ? $transaction['request'] : null;

        if (! $request instanceof RequestInterface) {
            throw new LogicException(sprintf('No recorded request exists at index %d.', $index));
        }

        return $request;
    }

    /** @return array<mixed> */
    public function options(int $index): array
    {
        $transaction = $this->transactions[$index] ?? null;
        $options = $transaction !== null ? $transaction['options'] : null;

        if (! is_array($options)) {
            throw new LogicException(sprintf('No recorded request options exist at index %d.', $index));
        }

        return $options;
    }

    public function count(): int
    {
        return count($this->transactions);
    }
}
