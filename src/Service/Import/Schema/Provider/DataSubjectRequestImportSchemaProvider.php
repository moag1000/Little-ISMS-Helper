<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\DataSubjectRequest;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see DataSubjectRequest} (GDPR Art. 15-22).
 *
 * Mirrors the editable field set of {@see \App\Form\DataSubjectRequestType}:
 * request details, data-subject information, identity verification and the
 * Art. 12(3) response-tracking fields. Lifecycle-owned (`status`), computed
 * (`deadlineAt`, `getDaysUntilDeadline`, …), audit (`createdAt`/`updatedAt`),
 * `lockVersion`, `tenant` and `id` are intentionally excluded.
 *
 * The whole import is gated behind the `privacy` module (set on the schema);
 * none of the individual fields are gated behind a non-privacy module, so no
 * per-field {@see ImportFieldSpec::$module} is set.
 *
 * Relation fields exposed by the FormType (assignedTo, assignedPerson,
 * assignedDeputyPersons, dpoPerson, processingActivity) are intentionally not
 * mapped here — they require human-readable lookup keys that are not part of
 * this scalar/enum/date import surface.
 */
final class DataSubjectRequestImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'DataSubjectRequest';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'DataSubjectRequest',
            entityClass: DataSubjectRequest::class,
            module: 'privacy',
            fields: [
                // ── Request details ──────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'requestType',
                    setter: 'setRequestType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: DataSubjectRequest::REQUEST_TYPES, // already lowercase
                    label: 'Request type',
                ),
                new ImportFieldSpec(
                    name: 'receivedAt',
                    setter: 'setReceivedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true, // entity column is NOT NULL
                    label: 'Received at',
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true, // entity column is NOT NULL
                    label: 'Description',
                ),

                // ── Data-subject information ──────────────────────────────────
                new ImportFieldSpec(
                    name: 'dataSubjectName',
                    setter: 'setDataSubjectName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true, // entity column is NOT NULL
                    label: 'Data subject name',
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectEmail',
                    setter: 'setDataSubjectEmail',
                    type: ImportFieldSpec::TYPE_STRING,
                    label: 'Data subject email',
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectIdentifier',
                    setter: 'setDataSubjectIdentifier',
                    type: ImportFieldSpec::TYPE_STRING,
                    label: 'Data subject identifier',
                ),

                // ── Identity verification ─────────────────────────────────────
                new ImportFieldSpec(
                    name: 'identityVerified',
                    setter: 'setIdentityVerified',
                    type: ImportFieldSpec::TYPE_BOOL,
                    label: 'Identity verified',
                ),
                new ImportFieldSpec(
                    name: 'identityVerificationMethod',
                    setter: 'setIdentityVerificationMethod',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: DataSubjectRequest::VERIFICATION_METHODS,
                    label: 'Identity verification method',
                ),

                // ── Response tracking (GDPR Art. 12(3)) ───────────────────────
                new ImportFieldSpec(
                    name: 'responseAt',
                    setter: 'setResponseAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    label: 'Response at',
                ),
                new ImportFieldSpec(
                    name: 'extendedDeadlineAt',
                    setter: 'setExtendedDeadlineAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    label: 'Extended deadline at',
                ),
                new ImportFieldSpec(
                    name: 'extensionReason',
                    setter: 'setExtensionReason',
                    type: ImportFieldSpec::TYPE_TEXT,
                    label: 'Extension reason',
                ),
                new ImportFieldSpec(
                    name: 'extensionNotifiedAt',
                    setter: 'setExtensionNotifiedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    label: 'Extension notified at',
                ),
                new ImportFieldSpec(
                    name: 'responseDocument',
                    setter: 'setResponseDocument',
                    type: ImportFieldSpec::TYPE_STRING,
                    label: 'Response document',
                ),
                new ImportFieldSpec(
                    name: 'responseMethod',
                    setter: 'setResponseMethod',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['email', 'letter', 'portal', 'in_person'],
                    label: 'Response method',
                ),
                new ImportFieldSpec(
                    name: 'rejectionReason',
                    setter: 'setRejectionReason',
                    type: ImportFieldSpec::TYPE_TEXT,
                    label: 'Rejection reason',
                ),

                // ── Internal notes ────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'notes',
                    setter: 'setNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                    label: 'Notes',
                ),
            ],
        );
    }
}
