<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\BusinessProcess;
use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see BusinessProcess} (ISO 22301 / BSI 200-4 BIA records).
 *
 * Supersedes the bespoke {@see \App\Service\Import\Mapper\BusinessProcessMapper},
 * which only handled name, criticality, rto, rpo, mtpd, financialImpactPerHour,
 * processOwner and the two legacy free-text dependency fields. This schema mirrors
 * every user-editable field exposed by {@see \App\Form\BusinessProcessType} and
 * additionally restores the BIA impact dimensions the old mapper silently dropped
 * (financialImpactPerDay, reputationalImpact, regulatoryImpact, operationalImpact,
 * mbco, recoveryStrategy) plus the structured owner relations.
 *
 * Field set mirrors the user-editable fields exposed by BusinessProcessType:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - IntegerType / 1-5 impact choices → TYPE_INT
 *   - MoneyType financial impacts → TYPE_FLOAT (decimal columns)
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *   - master-data FKs (User by email, Person by fullName) → TYPE_RELATION
 *
 * `name` is the unique upsert key (NotBlank on the entity). `criticality` is the
 * other NotBlank field, flagged required.
 *
 * The whole entity is NOT module-gated (BusinessProcessType carries no module
 * conditionals), so the schema declares no `module` and no per-field gates.
 *
 * Excluded by design: id, tenant, timestamps (createdAt/updatedAt), lifecycle-
 * owned status, computed fields (businessImpactScore, processRiskLevel, …), and
 * M:N collections without a single human lookup field — upstreamProcesses,
 * downstreamProcesses, upstreamDependencies, dependentProcesses, supportingAssets,
 * identifiedRisks, incidents, processOwnerDeputyPersons.
 */
final class BusinessProcessImportSchemaProvider implements ImportSchemaProviderInterface
{
    /** Accepted criticality values (BSI 200-4 / ISO 22301). */
    private const CRITICALITY_VALUES = ['critical', 'high', 'medium', 'low'];

    public function supports(string $entityType): bool
    {
        return $entityType === 'BusinessProcess';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'BusinessProcess',
            entityClass: BusinessProcess::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
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
                    name: 'criticality',
                    setter: 'setCriticality',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: self::CRITICALITY_VALUES,
                ),

                // ── Process owner (Pattern A dual-state + legacy string) ─────────
                new ImportFieldSpec(
                    name: 'processOwnerUser',
                    setter: 'setProcessOwnerUser',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: User::class,
                    relationLookup: 'email',
                ),
                new ImportFieldSpec(
                    name: 'processOwnerPerson',
                    setter: 'setProcessOwnerPerson',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Person::class,
                    relationLookup: 'fullName',
                ),
                new ImportFieldSpec(
                    name: 'processOwner',
                    setter: 'setProcessOwner',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Recovery targets (ISO 22301 Cl. 8.2.2 — hours) ──────────────
                new ImportFieldSpec(
                    name: 'rto',
                    setter: 'setRto',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'rpo',
                    setter: 'setRpo',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'mtpd',
                    setter: 'setMtpd',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'mbco',
                    setter: 'setMbco',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Financial impact (MoneyType — decimal) ──────────────────────
                new ImportFieldSpec(
                    name: 'financialImpactPerHour',
                    setter: 'setFinancialImpactPerHour',
                    type: ImportFieldSpec::TYPE_FLOAT,
                ),
                new ImportFieldSpec(
                    name: 'financialImpactPerDay',
                    setter: 'setFinancialImpactPerDay',
                    type: ImportFieldSpec::TYPE_FLOAT,
                ),

                // ── BIA impact dimensions (1-5 scale) ───────────────────────────
                new ImportFieldSpec(
                    name: 'reputationalImpact',
                    setter: 'setReputationalImpact',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'regulatoryImpact',
                    setter: 'setRegulatoryImpact',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'operationalImpact',
                    setter: 'setOperationalImpact',
                    type: ImportFieldSpec::TYPE_INT,
                ),

                // ── Dependencies (legacy free-text) + recovery strategy ─────────
                new ImportFieldSpec(
                    name: 'dependenciesUpstream',
                    setter: 'setDependenciesUpstream',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'dependenciesDownstream',
                    setter: 'setDependenciesDownstream',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'recoveryStrategy',
                    setter: 'setRecoveryStrategy',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
