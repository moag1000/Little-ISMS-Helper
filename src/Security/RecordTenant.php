<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;

/**
 * The organisation a record belongs to — via getTenant() or, for records that
 * keep a plain tenant-id column (e.g. CustomReport), getTenantId().
 *
 * Used by the tenant-scoped user picker ({@see \App\Form\Type\OwnerUserType})
 * and {@see \App\Validator\Constraint\SameTenantOwner} so both decide the
 * record's organisation the same way.
 */
final class RecordTenant
{
    /** Whether the record carries an organisation at all (accessor exists). */
    public static function isTenantOwned(mixed $record): bool
    {
        return is_object($record) && (method_exists($record, 'getTenant') || method_exists($record, 'getTenantId'));
    }

    /** The record's organisation (entity or id), null when not (yet) set. */
    public static function of(mixed $record): Tenant|int|null
    {
        if (!is_object($record)) {
            return null;
        }
        if (method_exists($record, 'getTenant')) {
            $tenant = $record->getTenant();
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }
        if (method_exists($record, 'getTenantId')) {
            $id = $record->getTenantId();
            if (is_int($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Whether $candidate is that organisation. Fails closed: a missing tenant
     * never matches; Tenant entities compare like TenantOwnership::isSameTenant().
     */
    public static function matches(?Tenant $candidate, Tenant|int $expected): bool
    {
        if (!$candidate instanceof Tenant) {
            return false;
        }
        if ($expected instanceof Tenant) {
            return TenantOwnership::isSameTenant($candidate, $expected);
        }

        return $candidate->getId() === $expected;
    }
}
