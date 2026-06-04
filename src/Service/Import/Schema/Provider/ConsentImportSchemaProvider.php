<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Consent;
use App\Entity\ProcessingActivity;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for the {@see Consent} entity (GDPR Art. 7).
 *
 * Mirrors the user-editable field set of {@see \App\Form\ConsentType}: the
 * external data-subject identifier, the linked processing activity (resolved by
 * name), the granular purposes list, the grant timestamp/method/wording/channel,
 * the optional expiry and free-text notes.
 *
 * Deliberately NOT importable:
 *   - status            — lifecycle-owned (defaults to pending_verification; the
 *                         DPO-verification + revoke/withdraw actions drive it).
 *   - proofDocument / proofMetadata — proof/binary evidence, not bulk-mappable.
 *   - documentedBy / documentedAt / verified-, revocation- and withdrawal-fields
 *     — internal management + audit-trail fields set by dedicated server-side
 *     actions (e.g. Consent::recordWithdrawal), never by bulk import.
 *   - id / tenant / lockVersion / createdAt / updatedAt — infrastructure.
 *
 * The whole import is gated behind the `privacy` module (Consent only exists for
 * tenants that have GDPR/privacy active).
 */
final class ConsentImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Consent';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Consent',
            entityClass: Consent::class,
            module: 'privacy',
            fields: [
                new ImportFieldSpec(
                    name: 'dataSubjectIdentifier',
                    setter: 'setDataSubjectIdentifier',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    aliases: ['data_subject', 'data_subject_identifier', 'subject'],
                    label: 'consent.form.data_subject_identifier',
                ),
                new ImportFieldSpec(
                    name: 'identifierType',
                    setter: 'setIdentifierType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['email', 'customer_id', 'pseudonym', 'phone', 'other'],
                    aliases: ['identifier_type'],
                    label: 'consent.form.identifier_type_label',
                ),
                new ImportFieldSpec(
                    name: 'processingActivity',
                    setter: 'setProcessingActivity',
                    type: ImportFieldSpec::TYPE_RELATION,
                    required: true,
                    relationClass: ProcessingActivity::class,
                    relationLookup: 'name',
                    aliases: ['processing_activity', 'vvt', 'activity'],
                    label: 'consent.form.processing_activity',
                ),
                new ImportFieldSpec(
                    name: 'purposes',
                    setter: 'setPurposes',
                    type: ImportFieldSpec::TYPE_LIST,
                    aliases: ['purpose'],
                    label: 'consent.form.purposes',
                ),
                new ImportFieldSpec(
                    name: 'grantedAt',
                    setter: 'setGrantedAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                    aliases: ['granted_at', 'granted'],
                    label: 'consent.form.granted_at',
                ),
                new ImportFieldSpec(
                    name: 'consentMethod',
                    setter: 'setConsentMethod',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['double_opt_in', 'written_form', 'checkbox', 'oral', 'email', 'other'],
                    aliases: ['consent_method', 'method'],
                    label: 'consent.form.consent_method',
                ),
                new ImportFieldSpec(
                    name: 'consentText',
                    setter: 'setConsentText',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                    aliases: ['consent_text', 'wording'],
                    label: 'consent.form.consent_text',
                ),
                new ImportFieldSpec(
                    name: 'consentChannel',
                    setter: 'setConsentChannel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['website', 'email', 'paper_form', 'phone', 'in_person', 'other'],
                    aliases: ['consent_channel', 'channel'],
                    label: 'consent.form.consent_channel',
                ),
                new ImportFieldSpec(
                    name: 'expiresAt',
                    setter: 'setExpiresAt',
                    type: ImportFieldSpec::TYPE_DATE,
                    aliases: ['expires_at', 'expiry', 'valid_until'],
                    label: 'consent.form.expires_at',
                ),
                new ImportFieldSpec(
                    name: 'notes',
                    setter: 'setNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                    aliases: ['note', 'comment'],
                    label: 'consent.form.notes',
                ),
            ],
        );
    }
}
