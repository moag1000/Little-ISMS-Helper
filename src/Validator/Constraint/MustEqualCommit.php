<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Asserts that the value, when uppercased, equals the literal string "COMMIT".
 *
 * Used on PreviewConfirmType::confirmText to require an explicit user
 * confirmation before a destructive bulk-import commit is executed.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class MustEqualCommit extends Constraint
{
    public string $message = 'import.error.must_equal_commit';
}
