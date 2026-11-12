<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Core;

enum AuthStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case RateLimited = 'rate_limited';
    case MfaRequired = 'mfa_required';
}
