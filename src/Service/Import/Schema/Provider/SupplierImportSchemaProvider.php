<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Supplier;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Supplier} (ISO 27001 A.5.19-A.5.22 supplier
 * management; DORA Art. 28/30; GDPR Art. 28; LkSG §§ 4-10; BaFin MaRisk AT 9).
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\SupplierType}:
 *   - multi-select / JSON-list fields → TYPE_LIST (comma/semicolon-split into array)
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *
 * The import itself is not module-gated at the entity level (Supplier is core),
 * so {@see EntityImportSchema::$module} is null. Individual fields that the
 * FormType only builds behind a module gate carry a per-field `module`:
 *   - `privacy`   — GDPR Art. 28 processor fields
 *   - `nis2_dora` — DORA Register-of-Information (RoI) fields
 *   - `lksg`      — German Supply Chain Due Diligence Act fields
 *   - `marisk`    — BaFin MaRisk AT 9 outsourcing fields
 *
 * `name` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (serviceProvided, criticality) are flagged required.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lockVersion,
 * lifecycle-owned `status` (mapped=false in the FormType, owned by
 * supplier_lifecycle), the M:N `supportedAssets` collection (no single human
 * lookup field), and entity-only fields not surfaced by the FormType
 * (contractualSLAs, BCM block, exitStrategyDocument).
 */
final class SupplierImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Supplier';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Supplier',
            entityClass: Supplier::class,
            module: null,
            fields: [
                // ── Overview / identification ───────────────────────────────────
                new ImportFieldSpec(
                    name: 'name',
                    setter: 'setName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'serviceProvided',
                    setter: 'setServiceProvided',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                // criticality choices are tenant-dynamic (code list) — kept as a
                // free string rather than a fixed enum.
                new ImportFieldSpec(
                    name: 'criticality',
                    setter: 'setCriticality',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),

                // ── Contact ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'contactPerson',
                    setter: 'setContactPerson',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'email',
                    setter: 'setEmail',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'phone',
                    setter: 'setPhone',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'address',
                    setter: 'setAddress',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Risk assessment ─────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'securityScore',
                    setter: 'setSecurityScore',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'lastSecurityAssessment',
                    setter: 'setLastSecurityAssessment',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'nextAssessmentDate',
                    setter: 'setNextAssessmentDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'assessmentFindings',
                    setter: 'setAssessmentFindings',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'nonConformities',
                    setter: 'setNonConformities',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Contract ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'contractStartDate',
                    setter: 'setContractStartDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'contractEndDate',
                    setter: 'setContractEndDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'securityRequirements',
                    setter: 'setSecurityRequirements',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'hasISO27001',
                    setter: 'setHasISO27001',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'hasISO22301',
                    setter: 'setHasISO22301',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'certifications',
                    setter: 'setCertifications',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Privacy (GDPR Art. 28) — privacy module ─────────────────────
                new ImportFieldSpec(
                    name: 'hasDPA',
                    setter: 'setHasDPA',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'dpaSignedDate',
                    setter: 'setDpaSignedDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'gdprProcessorStatus',
                    setter: 'setGdprProcessorStatus',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'privacy',
                    enumValues: ['controller', 'processor', 'joint_controller', 'none'],
                ),
                new ImportFieldSpec(
                    name: 'gdprTransferMechanism',
                    setter: 'setGdprTransferMechanism',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'gdprAvContractSigned',
                    setter: 'setGdprAvContractSigned',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'privacy',
                ),
                new ImportFieldSpec(
                    name: 'gdprAvContractDate',
                    setter: 'setGdprAvContractDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'privacy',
                ),

                // ── DORA Register-of-Information — nis2_dora module ──────────────
                new ImportFieldSpec(
                    name: 'isDoraRelevant',
                    setter: 'setIsDoraRelevant',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'leiCode',
                    setter: 'setLeiCode',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'naceCode',
                    setter: 'setNaceCode',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'countryOfHeadOffice',
                    setter: 'setCountryOfHeadOffice',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'thirdCountryTransfer',
                    setter: 'setThirdCountryTransfer',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'transferSafeguards',
                    setter: 'setTransferSafeguards',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'nis2_dora',
                    enumValues: ['adequacy_decision', 'scc', 'bcr', 'certification', 'derogation', 'none'],
                ),
                new ImportFieldSpec(
                    name: 'ictCriticality',
                    setter: 'setIctCriticality',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'nis2_dora',
                    enumValues: ['non_ict', 'important', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'ictFunctionType',
                    setter: 'setIctFunctionType',
                    type: ImportFieldSpec::TYPE_STRING,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'substitutability',
                    setter: 'setSubstitutability',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'nis2_dora',
                    enumValues: ['easy', 'medium', 'hard'],
                ),
                new ImportFieldSpec(
                    name: 'hasSubcontractors',
                    setter: 'setHasSubcontractors',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'subcontractorChain',
                    setter: 'setSubcontractorChain',
                    type: ImportFieldSpec::TYPE_LIST,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'processingLocations',
                    setter: 'setProcessingLocations',
                    type: ImportFieldSpec::TYPE_LIST,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'lastDoraAuditDate',
                    setter: 'setLastDoraAuditDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'nis2_dora',
                ),
                new ImportFieldSpec(
                    name: 'hasExitStrategy',
                    setter: 'setHasExitStrategy',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'nis2_dora',
                ),

                // ── LkSG (Supply Chain Due Diligence) — lksg module ─────────────
                new ImportFieldSpec(
                    name: 'lksgReportingObligation',
                    setter: 'setLksgReportingObligation',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'lksg',
                ),
                new ImportFieldSpec(
                    name: 'lksgRiskCategory',
                    setter: 'setLksgRiskCategory',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'lksg',
                    enumValues: ['low', 'medium', 'high', 'critical'],
                ),
                new ImportFieldSpec(
                    name: 'lksgHumanRightsRiskScore',
                    setter: 'setLksgHumanRightsRiskScore',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'lksg',
                ),
                new ImportFieldSpec(
                    name: 'lksgEnvironmentalRiskScore',
                    setter: 'setLksgEnvironmentalRiskScore',
                    type: ImportFieldSpec::TYPE_INT,
                    module: 'lksg',
                ),
                new ImportFieldSpec(
                    name: 'lksgRiskAnalysisDate',
                    setter: 'setLksgRiskAnalysisDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'lksg',
                ),
                new ImportFieldSpec(
                    name: 'lksgComplaintMechanism',
                    setter: 'setLksgComplaintMechanism',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'lksg',
                ),
                new ImportFieldSpec(
                    name: 'lksgPreventionMeasures',
                    setter: 'setLksgPreventionMeasures',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'lksg',
                ),

                // ── MaRisk AT 9 (outsourcing) — marisk module ───────────────────
                new ImportFieldSpec(
                    name: 'outsourcingClassification',
                    setter: 'setOutsourcingClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    module: 'marisk',
                    enumValues: ['substantial', 'non_substantial'],
                ),
                new ImportFieldSpec(
                    name: 'outsourcingDueDiligenceCompleted',
                    setter: 'setOutsourcingDueDiligenceCompleted',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'outsourcingDueDiligenceDate',
                    setter: 'setOutsourcingDueDiligenceDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'outsourcingExitStrategy',
                    setter: 'setOutsourcingExitStrategy',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'bafinNotificationRequired',
                    setter: 'setBafinNotificationRequired',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'bafinNotificationDate',
                    setter: 'setBafinNotificationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'riskBearingCapacityImpact',
                    setter: 'setRiskBearingCapacityImpact',
                    type: ImportFieldSpec::TYPE_TEXT,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'boardLevelRiskAcceptance',
                    setter: 'setBoardLevelRiskAcceptance',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'complianceFunctionInvolvement',
                    setter: 'setComplianceFunctionInvolvement',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'marisk',
                ),
                new ImportFieldSpec(
                    name: 'internalAuditFunctionInvolvement',
                    setter: 'setInternalAuditFunctionInvolvement',
                    type: ImportFieldSpec::TYPE_BOOL,
                    module: 'marisk',
                ),
            ],
        );
    }
}
