<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class ExpectedToolException extends ToolException implements ShouldntReport {}
