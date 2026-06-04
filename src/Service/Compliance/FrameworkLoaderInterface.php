<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Contract for services (and command thin-wrappers) that know how to seed a
 * specific compliance framework's requirements into the database.
 *
 * Implementations are tagged `app.framework_loader` via the _instanceof rule in
 * config/services.yaml so that FrameworkLoaderRegistry can collect them without
 * explicit service entries.
 *
 * Commands that implement this interface become thin wrappers: their execute()
 * delegates to loadRequirements(). ComplianceFrameworkLoaderService and
 * ComplianceLoaderFixerService inject FrameworkLoaderRegistry instead of the
 * command objects directly, breaking the command-as-service anti-pattern.
 */
interface FrameworkLoaderInterface
{
    /**
     * Unique framework code that this loader handles, e.g. 'ISO27001', 'DORA'.
     *
     * Named getFrameworkCode() (not getCode()) to avoid conflict with
     * Symfony\Component\Console\Command\Command::getCode(): ?callable.
     */
    public function getFrameworkCode(): string;

    /**
     * Seed (or update) the framework requirements into the database.
     *
     * @param bool          $update When true, existing requirements are updated;
     *                              when false, existing requirements are skipped.
     * @param SymfonyStyle|null $io Console style for progress/error output.
     *                              May be null when called programmatically without
     *                              a console context.
     *
     * @return int 0 on success, non-zero on failure (mirrors Command exit codes)
     */
    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int;
}
