<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Person;
use App\Entity\ThreatIntelligence;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see ThreatIntelligence} (ISO 27001 A.5.7 · NIS2 Art. 30).
 *
 * The whole entity lives under the `vulnerability_intel` module (see
 * config/modules.yaml — ThreatIntelligence is listed there), so the schema
 * declares `module: 'vulnerability_intel'` once at the {@see EntityImportSchema}
 * level. The per-field gates inside {@see \App\Form\ThreatIntelligenceType}
 * (TLP / MITRE / IOCs / confidence) are gated on the very same
 * `vulnerability_intel` module, so no additional per-field module gate is
 * needed — the entity-level gate already covers them.
 *
 * Field set mirrors the user-editable `->add()` calls of ThreatIntelligenceType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - JSON array tag fields → TYPE_LIST (comma/semicolon-split into array)
 *   - yes/no toggles → TYPE_BOOL
 *   - DateType single_text fields → TYPE_DATE
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - integer → TYPE_INT
 *   - master-data FK (Person) → TYPE_RELATION resolved by a human field
 *
 * `title` is the unique upsert key (NotBlank on the entity). Other NotBlank
 * fields (description, threatType) are flagged required. `severity`,
 * `affectsOrganization` and `detectionDate` are required in the FormType and
 * flagged accordingly.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt),
 * lockVersion, lifecycle-owned `status` (mapped=false in the FormType, owned by
 * threat_intelligence_lifecycle), the legacy/deprecated `affectedSystems` and
 * `indicators` JSON fields (no FormType ->add()), M:N collections without a
 * single-entity human lookup (affectedAssets, assignedDeputyPersons,
 * resultingIncidents), the User assignee relation (assignedTo — no human
 * lookup field; only Person relations are imported, mirroring the
 * ProcessingActivity provider convention), and the structured STIX-2.1 IOC
 * collection (iocsList — array of objects, no flat-CSV representation).
 */
final class ThreatIntelligenceImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'ThreatIntelligence';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'ThreatIntelligence',
            entityClass: ThreatIntelligence::class,
            module: 'vulnerability_intel',
            fields: [
                // ── Overview (ISO 27001 A.5.7) ──────────────────────────────────
                new ImportFieldSpec(
                    name: 'title',
                    setter: 'setTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'threatType',
                    setter: 'setThreatType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'malware',
                        'phishing',
                        'ransomware',
                        'ddos',
                        'zero_day',
                        'apt',
                        'insider_threat',
                        'social_engineering',
                        'data_breach',
                        'vulnerability',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'severity',
                    setter: 'setSeverity',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: ['critical', 'high', 'medium', 'low', 'informational'],
                ),

                // ── Intelligence source ─────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'source',
                    setter: 'setSource',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'cveId',
                    setter: 'setCveId',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'cvssScore',
                    setter: 'setCvssScore',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'detectionDate',
                    setter: 'setDetectionDate',
                    type: ImportFieldSpec::TYPE_DATE,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'mitigationDate',
                    setter: 'setMitigationDate',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Threat classification (NIS2 Art. 30 · MITRE ATT&CK) ─────────
                new ImportFieldSpec(
                    name: 'affectsOrganization',
                    setter: 'setAffectsOrganization',
                    type: ImportFieldSpec::TYPE_BOOL,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'tlpClassification',
                    setter: 'setTlpClassification',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['red', 'amber', 'green', 'white'],
                ),
                new ImportFieldSpec(
                    name: 'threatActorAttribution',
                    setter: 'setThreatActorAttribution',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'confidenceLevel',
                    setter: 'setConfidenceLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: ['low', 'medium', 'high'],
                ),
                new ImportFieldSpec(
                    name: 'mitreAttackTactics',
                    setter: 'setMitreAttackTactics',
                    type: ImportFieldSpec::TYPE_LIST,
                ),
                new ImportFieldSpec(
                    name: 'mitreAttackTechniques',
                    setter: 'setMitreAttackTechniques',
                    type: ImportFieldSpec::TYPE_LIST,
                ),
                new ImportFieldSpec(
                    name: 'sharedExternally',
                    setter: 'setSharedExternally',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── Mitigations ─────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'mitigationRecommendations',
                    setter: 'setMitigationRecommendations',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'actionsTaken',
                    setter: 'setActionsTaken',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'references',
                    setter: 'setReferences',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Assignee (Person master-data FK) ────────────────────────────
                new ImportFieldSpec(
                    name: 'assignedPerson',
                    setter: 'setAssignedPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
            ],
        );
    }
}
