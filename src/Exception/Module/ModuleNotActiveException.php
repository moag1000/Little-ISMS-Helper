<?php

declare(strict_types=1);

namespace App\Exception\Module;

use App\Exception\AppException;

/**
 * Thrown when a feature that is gated behind an optional compliance
 * module is accessed while the module is deactivated for the tenant.
 *
 * See `docs/MODULE_GATING_GUIDE.md` and `config/modules.yaml` for the
 * 21 supported module keys.
 */
final class ModuleNotActiveException extends AppException
{
    public function __construct(
        private readonly string $moduleKey,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf('Module "%s" is not active for this tenant.', $moduleKey),
            0,
            $previous,
        );
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }
}
