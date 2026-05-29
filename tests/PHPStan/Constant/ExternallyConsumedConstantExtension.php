<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Constant;

use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Rules\Constants\AlwaysUsedClassConstantsExtension;

/**
 * Marks class constants that are consumed outside the PHP source PHPStan
 * analyses (CI gate scripts, reflection in tests) as "always used", so
 * level-5 classConstant.unused does not flag them.
 *
 * BackupService::EXCLUDED_FROM_BACKUP has no PHP-src callers but is the
 * source of truth for two external consumers:
 *   - scripts/quality/check_backup_entity_coverage.py (Gate 43) parses the
 *     constant body via regex to enforce backup coverage.
 *   - BackupServiceTest reads it via ReflectionClass::getConstant().
 * Removing or "unusing" it breaks both — see the const's own comment.
 */
final class ExternallyConsumedConstantExtension implements AlwaysUsedClassConstantsExtension
{
    /** @var array<string, list<string>> FQCN => constant names */
    private const EXTERNALLY_CONSUMED = [
        'App\\Service\\BackupService' => ['EXCLUDED_FROM_BACKUP'],
    ];

    public function isAlwaysUsed(ClassConstantReflection $constant): bool
    {
        $class = $constant->getDeclaringClass()->getName();

        return in_array(
            $constant->getName(),
            self::EXTERNALLY_CONSUMED[$class] ?? [],
            true,
        );
    }
}
