<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\Asset;

/**
 * Records whose lifecycle ends in a four-eyes step may not be deleted around
 * it. An asset leaves the inventory through retire → dispose (four eyes,
 * config/workflows/asset.yaml, ISO 27001 A.5.11 / A.7.14); a plain delete — in
 * the form, as a bulk action or over the API — would skip the second person.
 *
 * Allowed: the end state, or a record created less than CORRECTION_HOURS ago
 * (a typo, a duplicate — it was never in use). One rule for every entry point:
 * AssetController::delete, BulkDeleteService, TenantAwareStateProcessor (API).
 */
final class DeletionPolicy
{
    public const int CORRECTION_HOURS = 24;

    /** class => status from which a delete is allowed. */
    private const array END_STATE = [
        Asset::class => 'disposed',
    ];

    /** Null when the record may be deleted, else the reason (English, for logs / API). */
    public static function refusal(object $record): ?string
    {
        foreach (self::END_STATE as $class => $endState) {
            if (!$record instanceof $class) {
                continue;
            }
            /** @var Asset $record */
            if ($record->getStatus() === $endState) {
                return null;
            }
            $created = $record->getCreatedAt();
            if ($created !== null && $created > new \DateTimeImmutable(sprintf('-%d hours', self::CORRECTION_HOURS))) {
                return null;
            }

            return sprintf('delete only after the four-eyes disposal (status "%s")', $endState);
        }

        return null;
    }

    public static function governs(object $record): bool
    {
        foreach (array_keys(self::END_STATE) as $class) {
            if ($record instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
