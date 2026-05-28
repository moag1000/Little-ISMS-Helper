<?php

declare(strict_types=1);

namespace App\Service\Restore;

/**
 * Shared constants for restore strategy semantics.
 *
 * Collaborators (RestoreValidator, RestoreDataPurger, RestoreEntityWriter,
 * RestoreSecretsHandler) reference these without depending on the facade.
 * RestoreService re-exports them as public const so existing call-sites
 * keep working without changes.
 */
final class RestoreOptions
{
    // Strategies for handling missing fields on restore.
    public const string STRATEGY_SKIP_FIELD = 'skip_field';
    public const string STRATEGY_USE_DEFAULT = 'use_default';
    public const string STRATEGY_FAIL = 'fail';

    // Strategies for handling existing data on restore.
    public const string EXISTING_SKIP = 'skip';
    public const string EXISTING_UPDATE = 'update';
    public const string EXISTING_REPLACE = 'replace';
}
