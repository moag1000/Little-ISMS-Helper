<?php

declare(strict_types=1);

namespace App\Exception\Module;

use App\Exception\AppException;

/**
 * Thrown when the module configuration itself is invalid (unknown module
 * key, malformed activation file, conflicting dependencies).
 */
final class ModuleConfigurationException extends AppException
{
    public function __construct(
        string $message,
        private readonly ?string $moduleKey = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unknownKey(string $moduleKey): self
    {
        return new self(
            \sprintf('Unknown module key "%s" — not defined in config/modules.yaml.', $moduleKey),
            $moduleKey,
        );
    }

    public function getModuleKey(): ?string
    {
        return $this->moduleKey;
    }
}
