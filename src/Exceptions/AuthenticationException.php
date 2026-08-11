<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class AuthenticationException extends ApiException implements ShouldntReport {}
