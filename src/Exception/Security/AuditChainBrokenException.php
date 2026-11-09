<?php

declare(strict_types=1);

namespace App\Exception\Security;

use App\Exception\AppException;

/**
 * The audit-log HMAC chain check (AUD-02) could not confirm the log: signing is off
 * or a row was changed or removed. Fails the verify job, so its page turns red with
 * this (translated) message.
 */
final class AuditChainBrokenException extends AppException
{
}
