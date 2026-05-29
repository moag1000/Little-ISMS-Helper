<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\Constants\AlwaysUsedClassConstantsExtension;
use PHPStan\Rules\Methods\AlwaysUsedMethodExtension;

/**
 * Marks class constants and methods that are consumed outside the PHP source
 * PHPStan analyses (CI gate scripts, reflection in tests) as "always used", so
 * level-5 classConstant.unused / method.unused do not flag them.
 *
 * These symbols have no PHP-src callers but are reached by external consumers
 * that static analysis cannot see:
 *   - BackupService::EXCLUDED_FROM_BACKUP — parsed by Gate 43
 *     (scripts/quality/check_backup_entity_coverage.py) and read via
 *     ReflectionClass::getConstant() in BackupServiceTest.
 *   - ComplianceWizardService::check{Consent,Dsr,Dpia}Coverage — private
 *     backward-compat delegators invoked via ReflectionMethod in
 *     ComplianceWizardServiceTest.
 *
 * Without this, an over-eager dead-code pass keeps re-flagging them; the symbols
 * carry their own "for test-reflection / Gate" comments documenting the why.
 */
final class ExternallyConsumedSymbolExtension implements
    AlwaysUsedClassConstantsExtension,
    AlwaysUsedMethodExtension
{
    /** @var array<string, list<string>> FQCN => constant names */
    private const EXTERNALLY_CONSUMED_CONSTANTS = [
        'App\\Service\\BackupService' => ['EXCLUDED_FROM_BACKUP'],
    ];

    /** @var array<string, list<string>> FQCN => method names */
    private const EXTERNALLY_CONSUMED_METHODS = [
        'App\\Service\\ComplianceWizardService' => [
            'checkConsentCoverage',
            'checkDsrCoverage',
            'checkDpiaCoverage',
        ],
    ];

    public function isAlwaysUsed(ClassConstantReflection|ExtendedMethodReflection $reflection): bool
    {
        $class = $reflection->getDeclaringClass()->getName();
        $map = $reflection instanceof ClassConstantReflection
            ? self::EXTERNALLY_CONSUMED_CONSTANTS
            : self::EXTERNALLY_CONSUMED_METHODS;

        return in_array($reflection->getName(), $map[$class] ?? [], true);
    }
}
