<?php

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\AppException;

/** The push endpoint is registered to another account and the caller cannot prove it holds it. */
final class PushSubscriptionTakenException extends AppException
{
    public function __construct()
    {
        parent::__construct('Push endpoint belongs to another account.');
    }
}
