<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\InstanceOperatorCheck;
use Psr\Log\LoggerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `instance_operator_missing()` — true when no active ROLE_SUPER_ADMIN
 * exists. /quick-fix shows a notice then. A failing check (broken
 * schema) counts as "unknown" and shows nothing; the schema sections of the
 * page report that state already.
 */
final readonly class InstanceOperatorExtension
{
    public function __construct(
        private InstanceOperatorCheck $operatorCheck,
        private LoggerInterface $logger,
    ) {
    }

    #[AsTwigFunction('instance_operator_missing')]
    public function isMissing(): bool
    {
        try {
            return !$this->operatorCheck->hasActiveSuperAdmin();
        } catch (\Throwable $e) {
            $this->logger->warning('Instance-operator check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
