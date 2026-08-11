<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use Acceptora\Verification\Laravel\VerificationServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class PackageTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [VerificationServiceProvider::class];
    }
}
