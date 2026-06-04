<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Vulnerability;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Risk} (ISO 27005 / ISO 27001 Cl. 6.1.2-6.1.3).
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\RiskType}:
 *   - fixed-choice ChoiceType / EnumType → TYPE_ENUM (lowercased value list)
 *   - yes/no CheckboxType toggles → TYPE_BOOL
 *   - IntegerType → TYPE_INT, NumberType → TYPE_FLOAT
 *   - DateType single_text → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (Asset, Person, Location, Supplier, Vulnerability,
 *     ThreatIntelligence) → TYPE_RELATION resolved by a human lookup field
 *
 * `title` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (category, description, probability, impact) are flagged required.
 *
 * Module gating mirrors RiskType: the privacy block is gated on `privacy`,
 * the vulnerability/threat-intel cross-links on `vulnerability_intel`, and the
 * quantitative SLE/ARO fields on `risk_quant`. The entity itself is not
 * module-gated (core ISMS), so the {@see EntityImportSchema} module is null.
 *
 * Excluded by design: id, tenant, timestamps, lockVersion, lifecycle-owned
 * `status` (owned by `risk_lifecycle` — disabled + mapped:false in the form),
 * acceptance-approval audit fields (acceptanceApprovedByUser /
 * acceptanceApprovedBy / acceptanceApprovedAt) and decision-approval audit
 * fields (decisionApprovedByUser / decisionApprovalDate), User relations
 * (riskOwner / *ApprovedByUser — no human lookup field), the
 * riskOwnerDeputyPersons M:N collection (no single-value lookup), and the
 * FAIR loss-frequency/magnitude quant fields not exposed by the form.
 */
final class RiskImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Risk';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Risk',
            entityClass: Risk::class,
            module: null,
            fields: [
                // ── Overview (ISO 27001 Cl. 6.1.2) ──────────────────────────────
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'category',
                    setter: 'setCategory',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'financial',
                        'operational',
                        'compliance',
                        'strategic',
                        'reputational',
                        'security',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'threat',
                    setter: 'setThreat',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'vulnerability',
                    setter: 'setVulnerability',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Risk subject (master-data FKs) ──────────────────────────────
                new ImportFieldSpec(
                    name: 'asset',
                    setter: 'setAsset',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Asset::class,
                    relationLookup: 'name',
                ),
                new ImportFieldSpec(
                    name: 'person',
                    setter: 'setPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'location',
                    setter: 'setLocation',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Location::class,
                    relationLookup: 'name',
                ),
                new ImportFieldSpec(
                    name: 'supplier',
                    setter: 'setSupplier',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Supplier::class,
                    relationLookup: 'name',
                ),

                // ── Risk assessment (ISO 27005 likelihood × impact) ─────────────
                new ImportFieldSpec(
                    name: 'probability',
                    setter: 'setProbability',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'impact',
                    setter: 'setImpact',
                    type: ImportFieldSpec::TYPE_INT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'residualProbability',
                    setter: 'setResidualProbability',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'residualImpact',
                    setter: 'setResidualImpact',
                    type: ImportFieldSpec::TYPE_INT,
                ),

                // ── Risk owner (Person master-data FK) ──────────────────────────
                new ImportFieldSpec(
                    name: 'riskOwnerPerson',
                    setter: 'setRiskOwnerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),

                // ── Treatment (ISO 27001 Cl. 6.1.3) ─────────────────────────────
                new ImportFieldSpec(
                    name: 'treatmentStrategy',
                    setter: 'setTreatmentStrategy',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'accept',
                        'mitigate',
                        'transfer',
                        'avoid',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'treatmentDescription',
                    setter: 'setTreatmentDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Acceptance justification & expiry (ISO 27001 Cl. 8.3) ───────
                new ImportFieldSpec(
                    name: 'acceptanceJustification',
                    setter: 'setAcceptanceJustification',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'acceptanceExpiryDate',
                    setter: 'setAcceptanceExpiryDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Decision-trail justifications (ISO 27001 Cl. 6.1.2.d/6.1.3) ──
                new ImportFieldSpec(
                    name: 'likelihoodJustification',
                    setter: 'setLikelihoodJustification',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'impactJustification',
                    setter: 'setImpactJustification',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'decisionRationale',
                    setter: 'setDecisionRationale',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Review ──────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'reviewDate',
                    setter: 'setReviewDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── DSGVO/GDPR risk extension (privacy module) ──────────────────
                new ImportFieldSpec(
                    name: 'involvesPersonalData',
                    setter: 'setInvolvesPersonalData',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'involvesSpecialCategoryData',
                    setter: 'setInvolvesSpecialCategoryData',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'legalBasis',
                    setter: 'setLegalBasis',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'privacy',
                    enumValues: [
                        'consent',
                        'contract',
                        'legal_obligation',
                        'vital_interests',
                        'public_task',
                        'legitimate_interests',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'processingScale',
                    setter: 'setProcessingScale',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'privacy',
                    enumValues: [
                        'small',
                        'medium',
                        'large_scale',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'requiresDPIA',
                    setter: 'setRequiresDPIA',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'dataSubjectImpact',
                    setter: 'setDataSubjectImpact',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'privacy',
                ),

                // ── Vulnerability & Threat-Intel cross-links (vulnerability_intel) ─
                new ImportFieldSpec(
                    name: 'threatIntelligence',
                    setter: 'setThreatIntelligence',
                    type: ImportFieldSpec::TYPE_RELATION,
                    module: 'vulnerability_intel',
                    relationClass: ThreatIntelligence::class,
                    relationLookup: 'title',
                ),
                new ImportFieldSpec(
                    name: 'linkedVulnerability',
                    setter: 'setLinkedVulnerability',
                    type: ImportFieldSpec::TYPE_RELATION,
                    module: 'vulnerability_intel',
                    relationClass: Vulnerability::class,
                    relationLookup: 'cveId',
                ),

                // ── Quantitative risk SLE/ARO (risk_quant module) ───────────────
                new ImportFieldSpec(
                    name: 'singleLossExpectancy',
                    setter: 'setSingleLossExpectancy',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'risk_quant',
                ),
                new ImportFieldSpec(
                    name: 'annualRateOfOccurrence',
                    setter: 'setAnnualRateOfOccurrence',
                    type: ImportFieldSpec::TYPE_FLOAT,
                    module: 'risk_quant',
                ),
            ],
        );
    }
}
