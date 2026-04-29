<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Asset;
use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use SimpleXMLElement;

/**
 * GSTOOL XML import — Phase 1 (Zielobjekte → Asset).
 *
 * Reads the pragmatic v1 schema (gstool_xml_v1) — see
 * docs/features/GSTOOL_IMPORT.md for the full schema and migration strategy.
 *
 * Phase 1 covers Zielobjekte (target objects) with Schutzbedarf (CIA). Later
 * phases extend to Modellierung, Bausteine, Maßnahmen, and Risikoanalyse.
 *
 * The output is targeted at the current BSI IT-Grundschutz Kompendium
 * (2023+) — old GSTOOL Schichten and Schutzbedarf-Stufen are normalised on
 * import so the data is usable directly in the new framework.
 */
final class GstoolXmlImporter
{
    public const string SUPPORTED_VERSION = '1.0';
    public const string FORMAT_TAG = 'gstool_xml_v1';

    /**
     * Schutzbedarf-Wert (lowercase, trimmed) → Tool CIA-Score 1..5.
     * BSI 200-2 ist 4-stufig; Tool 5-stufig. Wert 3 wird nicht vergeben
     * (Sprung 2 → 4) damit keine Information erfunden wird.
     */
    private const array SCHUTZBEDARF_MAP = [
        'niedrig'   => 1,
        'gering'    => 1,
        'normal'    => 2,
        'mittel'    => 2,
        'hoch'      => 4,
        'sehr hoch' => 5,
        'sehrhoch'  => 5,
        'sehr-hoch' => 5,
    ];

    /**
     * BSI 200-3 Eintrittshäufigkeit (4-stufig) → Tool Risk.likelihood (1-5).
     * Wert 3 wird übersprungen (4-stufig BSI → 5-stufig Tool).
     */
    public const array EINTRITTSHAEUFIGKEIT_MAP = [
        'selten'      => 1,
        'mittel'      => 2,
        'gelegentlich'=> 2,
        'haeufig'     => 4,
        'häufig'      => 4,
        'sehr haeufig'=> 5,
        'sehr häufig' => 5,
        'sehrhaeufig' => 5,
        'sehrhäufig'  => 5,
    ];

    /**
     * BSI 200-3 Schadenshöhe (4-stufig) → Tool Risk.impact (1-5).
     */
    public const array SCHADENSHOEHE_MAP = [
        'vernachlaessigbar' => 1,
        'vernachlässigbar'  => 1,
        'begrenzt'          => 2,
        'betraechtlich'     => 4,
        'beträchtlich'      => 4,
        'existenzbedrohend' => 5,
    ];

    /**
     * BSI 200-3 Risikobehandlung → Tool Risk.treatmentStrategy.
     * GSTOOL und Tool nutzen weitgehend identische Begriffe — Mapping ist
     * kanonisch.
     */
    public const array RISIKOBEHANDLUNG_MAP = [
        'risikovermeidung' => 'Avoid',
        'risikoreduktion'  => 'Reduce',
        'risikotransfer'   => 'Transfer',
        'risikoakzeptanz'  => 'Accept',
        'avoid'            => 'Avoid',
        'reduce'           => 'Reduce',
        'transfer'         => 'Transfer',
        'accept'           => 'Accept',
    ];

    /**
     * GSTOOL-Umsetzungsstatus → Tool Control.implementationStatus.
     * The four GSTOOL values (ja/nein/teilweise/entbehrlich) are well
     * known — see BSI IT-Grundschutz-Methodik (BSI 200-2) §8.2.4.
     */
    public const array UMSETZUNGSSTATUS_MAP = [
        'ja'           => 'implemented',
        'yes'          => 'implemented',
        'vollstaendig' => 'implemented',
        'vollständig'  => 'implemented',
        'teilweise'    => 'partially_implemented',
        'partial'      => 'partially_implemented',
        'nein'         => 'not_implemented',
        'no'           => 'not_implemented',
        'entbehrlich'  => 'not_applicable',
        'n.a.'         => 'not_applicable',
        'na'           => 'not_applicable',
    ];

    /**
     * GSTOOL-Type (lowercase, normalised) → Tool Asset.assetType.
     * Schicht-Information wird zusätzlich für die spätere Phase-3
     * Baustein-Zuordnung im Audit-Log mitgeführt.
     */
    private const array TYPE_MAP = [
        'it-system'           => 'it_system',
        'it system'           => 'it_system',
        'server'              => 'it_system',
        'client'              => 'it_system',
        'anwendung'           => 'application',
        'software'            => 'application',
        'geschaeftsanwendung' => 'application',
        'geschäftsanwendung'  => 'application',
        'raum'                => 'physical_facility',
        'gebaeude'            => 'physical_facility',
        'gebäude'             => 'physical_facility',
        'netz'                => 'network',
        'netzwerk'            => 'network',
        'kommunikationsnetz'  => 'network',
        'mitarbeiter'         => 'personnel',
        'personal'            => 'personnel',
        'rolle'               => 'personnel',
        'information'         => 'information',
        'datenobjekt'         => 'information',
        'geschaeftsprozess'   => 'business_process',
        'geschäftsprozess'    => 'business_process',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly ImportSessionRecorder $sessionRecorder,
        private readonly GstoolMigrationTable $migrationTable,
    ) {
    }

    /**
     * Parse XML and return preview rows + counters without touching the DB.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, header_error: ?string}
     */
    public function analyse(string $path, Tenant $tenant): array
    {
        $summary = ['new' => 0, 'update' => 0, 'error' => 0];

        $rootResult = $this->loadRoot($path);
        if ($rootResult['error'] !== null) {
            return ['rows' => [], 'summary' => $summary, 'header_error' => $rootResult['error']];
        }
        $root = $rootResult['root'];

        $rows = [];
        $line = 0;
        foreach ($this->iterateZielobjekte($root) as $zo) {
            $line++;
            $row = $this->mapZielobjekt($zo);
            $row['line'] = $line;

            if ($row['error'] !== null) {
                $summary['error']++;
                $row['action'] = 'error';
                $rows[] = $row;
                continue;
            }

            $existing = $this->assetRepository->findOneBy([
                'tenant' => $tenant,
                'name' => $row['name'],
            ]);
            $row['action'] = $existing !== null ? 'update' : 'new';
            $summary[$row['action']]++;
            $rows[] = $row;
        }

        // Phase 3+4+5 previews — purely informational, no DB touch.
        $bausteinPreview = $this->previewBausteine($root);
        $massnahmenPreview = $this->previewMassnahmen($root);
        $risikenPreview = $this->previewRisiken($root);

        return [
            'rows' => $rows,
            'summary' => $summary,
            'header_error' => null,
            'bausteine_rows' => $bausteinPreview,
            'massnahmen_rows' => $massnahmenPreview,
            'risiken_rows' => $risikenPreview,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewBausteine(SimpleXMLElement $root): array
    {
        if (!isset($root->bausteine)) {
            return [];
        }
        $rows = [];
        foreach ($root->bausteine->{'baustein-ref'} as $ref) {
            $legacy = trim((string) $ref['id']);
            if ($legacy === '') {
                continue;
            }
            $migration = $this->migrationTable->resolveBaustein($legacy);
            $rows[] = [
                'legacy_id' => $legacy,
                'zielobjekt' => trim((string) $ref['zielobjekt']) ?: null,
                'kompendium_2023_id' => $migration['to'] ?? null,
                'title_old' => $migration['title_old'] ?? null,
                'title_new' => $migration['title_new'] ?? null,
                'status' => $migration['status'] ?? 'unknown',
                'note' => $migration['note'] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewMassnahmen(SimpleXMLElement $root): array
    {
        if (!isset($root->massnahmen)) {
            return [];
        }
        $rows = [];
        foreach ($root->massnahmen->massnahme as $m) {
            $bausteinRef = trim((string) $m['baustein']);
            $statusRaw = strtolower(trim((string) $m->umsetzungsstatus));
            $bausteinMigration = $bausteinRef !== '' ? $this->migrationTable->resolveBaustein($bausteinRef) : null;
            $rows[] = [
                'legacy_id' => trim((string) $m['id']),
                'titel' => trim((string) $m->titel) ?: null,
                'baustein_legacy' => $bausteinRef ?: null,
                'baustein_kompendium_2023' => $bausteinMigration['to'] ?? null,
                'umsetzungsstatus_raw' => $statusRaw ?: null,
                'implementation_status' => self::UMSETZUNGSSTATUS_MAP[$statusRaw] ?? null,
                'zielobjekt' => trim((string) $m['zielobjekt']) ?: null,
            ];
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewRisiken(SimpleXMLElement $root): array
    {
        if (!isset($root->risikoanalyse)) {
            return [];
        }
        $rows = [];
        foreach ($root->risikoanalyse->risiko as $r) {
            $haeufigkeitRaw = strtolower(trim((string) $r->eintrittshaeufigkeit));
            $schadenRaw = strtolower(trim((string) $r->schadenshoehe));
            $behandlungRaw = strtolower(trim((string) $r->risikobehandlung));
            $likelihood = self::EINTRITTSHAEUFIGKEIT_MAP[$haeufigkeitRaw] ?? null;
            $impact = self::SCHADENSHOEHE_MAP[$schadenRaw] ?? null;
            $rows[] = [
                'legacy_id' => trim((string) $r['id']),
                'titel' => trim((string) $r->titel) ?: null,
                'gefaehrdung' => trim((string) $r->gefaehrdung) ?: null,
                'zielobjekt' => trim((string) $r['zielobjekt']) ?: null,
                'eintrittshaeufigkeit_raw' => $haeufigkeitRaw ?: null,
                'inherent_likelihood' => $likelihood,
                'schadenshoehe_raw' => $schadenRaw ?: null,
                'inherent_impact' => $impact,
                'inherent_risk_score' => ($likelihood !== null && $impact !== null) ? $likelihood * $impact : null,
                'risikobehandlung_raw' => $behandlungRaw ?: null,
                'treatment_strategy' => self::RISIKOBEHANDLUNG_MAP[$behandlungRaw] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * Apply the parsed XML to the DB inside a single transaction.
     *
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   summary: array<string, int>,
     *   header_error: ?string,
     *   session_id: ?int,
     * }
     */
    public function apply(string $path, Tenant $tenant, ?User $user, string $originalFilename): array
    {
        $preview = $this->analyse($path, $tenant);
        if ($preview['header_error'] !== null) {
            return $preview + ['session_id' => null];
        }

        $session = $this->sessionRecorder->openSession(
            sourcePath: $path,
            format: self::FORMAT_TAG,
            originalName: $originalFilename,
            user: $user,
            tenant: $tenant,
        );

        $em = $this->entityManager;

        // Map GSTOOL-ID → Asset, used in Phase 2 (Modellierung) to resolve
        // dependsOn relationships after all Zielobjekte are persisted.
        $assetByGstoolId = [];

        foreach ($preview['rows'] as $row) {
            if ($row['error'] !== null) {
                $this->sessionRecorder->recordRow(
                    session: $session,
                    lineNumber: (int) $row['line'],
                    decision: ImportRowEvent::DECISION_ERROR,
                    targetEntityType: Asset::class,
                    targetEntityId: null,
                    beforeState: null,
                    afterState: null,
                    sourceRowRaw: $this->sourceRowFor($row),
                    errorMessage: $row['error'],
                );
                continue;
            }

            $asset = $this->assetRepository->findOneBy([
                'tenant' => $tenant,
                'name' => $row['name'],
            ]);
            $isNew = $asset === null;
            $beforeState = $isNew ? null : $this->snapshot($asset);

            if ($asset === null) {
                $asset = new Asset();
                $asset->setTenant($tenant);
                $asset->setName($row['name']);
            }
            $this->applyRowToAsset($asset, $row);
            $em->persist($asset);
            $em->flush();

            if ($row['id'] !== null) {
                $assetByGstoolId[$row['id']] = $asset;
            }

            $this->sessionRecorder->recordRow(
                session: $session,
                lineNumber: (int) $row['line'],
                decision: $isNew ? ImportRowEvent::DECISION_IMPORT : ImportRowEvent::DECISION_UPDATE,
                targetEntityType: Asset::class,
                targetEntityId: $asset->getId(),
                beforeState: $beforeState,
                afterState: $this->snapshot($asset),
                sourceRowRaw: $this->sourceRowFor($row),
                errorMessage: null,
            );
        }

        // Phase 2: resolve <modellierung>/<abhaengigkeit> entries to
        // Asset.dependsOn. We only link assets that participated in this
        // import (cross-import links would silently dangle otherwise).
        $rootResult = $this->loadRoot($path);
        if ($rootResult['root'] !== null) {
            $this->applyModellierung(
                root: $rootResult['root'],
                assetByGstoolId: $assetByGstoolId,
                session: $session,
            );

            // Phase 3+4: log Bausteine and Maßnahmen with their migration
            // mapping into the audit trail. The actual entity-creation is
            // deliberately deferred — the Compliance Manager reviews the
            // mapping and decides per migration whether to link
            // ComplianceRequirements / create Controls.
            $this->logBausteineMigration(
                root: $rootResult['root'],
                assetByGstoolId: $assetByGstoolId,
                session: $session,
            );
            $this->logMassnahmenMigration(
                root: $rootResult['root'],
                assetByGstoolId: $assetByGstoolId,
                session: $session,
            );
            $this->logRisikoanalyse(
                root: $rootResult['root'],
                assetByGstoolId: $assetByGstoolId,
                session: $session,
            );
        }

        $this->sessionRecorder->closeSession($session, ImportSession::STATUS_COMMITTED);

        return $preview + ['session_id' => $session->getId()];
    }

    /**
     * Phase 2: apply <modellierung>/<abhaengigkeit von="X" zu="Y"/> to
     * Asset.dependsOn. The semantics are "X depends on Y", which matches
     * BSI 200-2 § 3.6 Maximumprinzip — dependent assets inherit the maximum
     * Schutzbedarf of their dependencies.
     *
     * @param array<string, Asset> $assetByGstoolId
     */
    private function applyModellierung(SimpleXMLElement $root, array $assetByGstoolId, ImportSession $session): void
    {
        if (!isset($root->modellierung)) {
            return;
        }

        $line = 0;
        foreach ($root->modellierung->abhaengigkeit as $dep) {
            $line++;
            $from = trim((string) $dep['von']);
            $to = trim((string) $dep['zu']);

            $fromAsset = $assetByGstoolId[$from] ?? null;
            $toAsset = $assetByGstoolId[$to] ?? null;

            if ($fromAsset === null || $toAsset === null) {
                $this->sessionRecorder->recordRow(
                    session: $session,
                    lineNumber: 1000 + $line,
                    decision: ImportRowEvent::DECISION_SKIP,
                    targetEntityType: Asset::class,
                    targetEntityId: null,
                    beforeState: null,
                    afterState: null,
                    sourceRowRaw: ['from' => $from, 'to' => $to],
                    errorMessage: sprintf('Dependency references unknown Zielobjekt(e): von=%s, zu=%s', $from, $to),
                );
                continue;
            }

            $alreadyLinked = $fromAsset->getDependsOn()->exists(
                static fn (int $key, Asset $a) => $a->getId() === $toAsset->getId(),
            );
            if ($alreadyLinked) {
                continue;
            }

            $fromAsset->addDependsOn($toAsset);
            $this->entityManager->persist($fromAsset);

            $this->sessionRecorder->recordRow(
                session: $session,
                lineNumber: 1000 + $line,
                decision: ImportRowEvent::DECISION_MERGE,
                targetEntityType: Asset::class,
                targetEntityId: $fromAsset->getId(),
                beforeState: null,
                afterState: ['dependsOn' => $toAsset->getId(), 'fromGstool' => $from, 'toGstool' => $to],
                sourceRowRaw: ['from' => $from, 'to' => $to],
                errorMessage: null,
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Phase 3: log <bausteine>/<baustein-ref> entries with their migration
     * mapping into the audit trail. No ComplianceRequirement linking is
     * created automatically — this is deliberately a "review-then-act"
     * step because some legacy Bausteine map to multiple new modules.
     *
     * @param array<string, Asset> $assetByGstoolId
     */
    private function logBausteineMigration(SimpleXMLElement $root, array $assetByGstoolId, ImportSession $session): void
    {
        if (!isset($root->bausteine)) {
            return;
        }
        $line = 0;
        foreach ($root->bausteine->{'baustein-ref'} as $ref) {
            $line++;
            $legacy = trim((string) $ref['id']);
            $zoId = trim((string) $ref['zielobjekt']);
            if ($legacy === '') {
                continue;
            }

            $migration = $this->migrationTable->resolveBaustein($legacy);
            $asset = $assetByGstoolId[$zoId] ?? null;

            $payload = [
                'legacy_id' => $legacy,
                'zielobjekt' => $zoId !== '' ? $zoId : null,
                'asset_name' => $asset?->getName(),
                'kompendium_2023_id' => $migration['to'] ?? null,
                'title_old' => $migration['title_old'] ?? null,
                'title_new' => $migration['title_new'] ?? null,
                'status' => $migration['status'] ?? 'unknown',
                'note' => $migration['note'] ?? null,
            ];

            $this->sessionRecorder->recordRow(
                session: $session,
                lineNumber: 2000 + $line,
                decision: $migration === null ? ImportRowEvent::DECISION_SKIP : ImportRowEvent::DECISION_MERGE,
                targetEntityType: Asset::class,
                targetEntityId: $asset?->getId(),
                beforeState: null,
                afterState: $payload,
                sourceRowRaw: ['from' => $legacy, 'zielobjekt' => $zoId],
                errorMessage: $migration === null
                    ? sprintf('Unknown legacy Baustein "%s" — no migration entry available.', $legacy)
                    : null,
            );
        }
    }

    /**
     * Phase 4: log <massnahmen>/<massnahme> entries with their
     * Umsetzungsstatus → implementationStatus mapping.
     *
     * @param array<string, Asset> $assetByGstoolId
     */
    private function logMassnahmenMigration(SimpleXMLElement $root, array $assetByGstoolId, ImportSession $session): void
    {
        if (!isset($root->massnahmen)) {
            return;
        }
        $line = 0;
        foreach ($root->massnahmen->massnahme as $m) {
            $line++;
            $mId = trim((string) $m['id']);
            $bausteinRef = trim((string) $m['baustein']);
            $zoId = trim((string) $m['zielobjekt']);
            $titel = trim((string) $m->titel);
            $statusRaw = strtolower(trim((string) $m->umsetzungsstatus));
            $statusMapped = self::UMSETZUNGSSTATUS_MAP[$statusRaw] ?? null;
            $bausteinMigration = $bausteinRef !== ''
                ? $this->migrationTable->resolveBaustein($bausteinRef)
                : null;
            $asset = $assetByGstoolId[$zoId] ?? null;

            $payload = [
                'legacy_id' => $mId,
                'titel' => $titel !== '' ? $titel : null,
                'baustein_legacy' => $bausteinRef !== '' ? $bausteinRef : null,
                'baustein_kompendium_2023' => $bausteinMigration['to'] ?? null,
                'umsetzungsstatus_raw' => $statusRaw !== '' ? $statusRaw : null,
                'implementation_status' => $statusMapped,
                'asset_name' => $asset?->getName(),
            ];

            $decision = $statusMapped === null && $bausteinMigration === null
                ? ImportRowEvent::DECISION_SKIP
                : ImportRowEvent::DECISION_MERGE;

            $this->sessionRecorder->recordRow(
                session: $session,
                lineNumber: 3000 + $line,
                decision: $decision,
                targetEntityType: Asset::class,
                targetEntityId: $asset?->getId(),
                beforeState: null,
                afterState: $payload,
                sourceRowRaw: [
                    'massnahme' => $mId,
                    'baustein' => $bausteinRef,
                    'zielobjekt' => $zoId,
                    'umsetzung' => $statusRaw,
                ],
                errorMessage: $statusMapped === null && $statusRaw !== ''
                    ? sprintf('Unknown Umsetzungsstatus "%s" — expected ja/nein/teilweise/entbehrlich.', $statusRaw)
                    : null,
            );
        }
    }

    /**
     * Phase 5: log <risikoanalyse>/<risiko> entries with their BSI-200-3
     * Eintrittshäufigkeit + Schadenshöhe + Risikobehandlung mapping.
     *
     * No Risk-entity is created automatically because Risk-Owner-
     * Zuweisung, RiskAppetite-Vergleich und Treatment-Plan-Erstellung
     * verlangen Tenant-spezifische Entscheidungen.
     *
     * @param array<string, Asset> $assetByGstoolId
     */
    private function logRisikoanalyse(SimpleXMLElement $root, array $assetByGstoolId, ImportSession $session): void
    {
        if (!isset($root->risikoanalyse)) {
            return;
        }
        $line = 0;
        foreach ($root->risikoanalyse->risiko as $r) {
            $line++;
            $rId = trim((string) $r['id']);
            $zoId = trim((string) $r['zielobjekt']);
            $titel = trim((string) $r->titel);
            $gefaehrdung = trim((string) $r->gefaehrdung);
            $haeufigkeitRaw = strtolower(trim((string) $r->eintrittshaeufigkeit));
            $schadenRaw = strtolower(trim((string) $r->schadenshoehe));
            $behandlungRaw = strtolower(trim((string) $r->risikobehandlung));

            $likelihood = self::EINTRITTSHAEUFIGKEIT_MAP[$haeufigkeitRaw] ?? null;
            $impact = self::SCHADENSHOEHE_MAP[$schadenRaw] ?? null;
            $treatment = self::RISIKOBEHANDLUNG_MAP[$behandlungRaw] ?? null;
            $asset = $assetByGstoolId[$zoId] ?? null;

            $payload = [
                'legacy_id' => $rId,
                'titel' => $titel !== '' ? $titel : null,
                'gefaehrdung' => $gefaehrdung !== '' ? $gefaehrdung : null,
                'asset_name' => $asset?->getName(),
                'eintrittshaeufigkeit_raw' => $haeufigkeitRaw !== '' ? $haeufigkeitRaw : null,
                'inherent_likelihood' => $likelihood,
                'schadenshoehe_raw' => $schadenRaw !== '' ? $schadenRaw : null,
                'inherent_impact' => $impact,
                'inherent_risk_score' => ($likelihood !== null && $impact !== null) ? $likelihood * $impact : null,
                'risikobehandlung_raw' => $behandlungRaw !== '' ? $behandlungRaw : null,
                'treatment_strategy' => $treatment,
            ];

            $errors = [];
            if ($haeufigkeitRaw !== '' && $likelihood === null) {
                $errors[] = sprintf('unknown Eintrittshäufigkeit "%s"', $haeufigkeitRaw);
            }
            if ($schadenRaw !== '' && $impact === null) {
                $errors[] = sprintf('unknown Schadenshöhe "%s"', $schadenRaw);
            }
            if ($behandlungRaw !== '' && $treatment === null) {
                $errors[] = sprintf('unknown Risikobehandlung "%s"', $behandlungRaw);
            }

            $this->sessionRecorder->recordRow(
                session: $session,
                lineNumber: 4000 + $line,
                decision: $errors === [] && $likelihood !== null && $impact !== null
                    ? ImportRowEvent::DECISION_MERGE
                    : ImportRowEvent::DECISION_SKIP,
                targetEntityType: Asset::class,
                targetEntityId: $asset?->getId(),
                beforeState: null,
                afterState: $payload,
                sourceRowRaw: [
                    'risiko' => $rId,
                    'zielobjekt' => $zoId,
                    'haeufigkeit' => $haeufigkeitRaw,
                    'schaden' => $schadenRaw,
                    'behandlung' => $behandlungRaw,
                ],
                errorMessage: $errors === [] ? null : implode('; ', $errors),
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function applyRowToAsset(Asset $asset, array $row): void
    {
        $asset->setAssetType($row['assetType']);
        if ($row['description'] !== null) {
            $asset->setDescription($row['description']);
        }
        // Asset.owner is NOT NULL in schema; fall back to a marker so the
        // import does not break on Zielobjekte without <verantwortlich>.
        // Reviewers should assign the real owner after the import.
        if ($asset->getOwner() === null || $asset->getOwner() === '') {
            $asset->setOwner($row['owner'] ?? 'Imported from GSTOOL');
        } elseif ($row['owner'] !== null) {
            $asset->setOwner($row['owner']);
        }
        if ($row['locationLabel'] !== null) {
            $asset->setLocation($row['locationLabel']);
        }
        // CIA columns are NOT NULL — fall back to "normal" (= 2) when the
        // GSTOOL export omits the Schutzbedarf-Block. Reviewers should
        // re-assess after the import.
        $asset->setConfidentialityValue($row['confidentiality'] ?? $asset->getConfidentialityValue() ?? 2);
        $asset->setIntegrityValue($row['integrity'] ?? $asset->getIntegrityValue() ?? 2);
        $asset->setAvailabilityValue($row['availability'] ?? $asset->getAvailabilityValue() ?? 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Asset $asset): array
    {
        return [
            'id' => $asset->getId(),
            'name' => $asset->getName(),
            'assetType' => $asset->getAssetType(),
            'description' => $asset->getDescription(),
            'owner' => $asset->getOwner(),
            'location' => $asset->getLocation(),
            'cia' => [
                'c' => $asset->getConfidentialityValue(),
                'i' => $asset->getIntegrityValue(),
                'a' => $asset->getAvailabilityValue(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sourceRowFor(array $row): array
    {
        return [
            'gstool_id' => $row['id'] ?? null,
            'name' => $row['name'] ?? null,
            'type' => $row['rawType'] ?? null,
            'schutzbedarf' => [
                'c' => $row['rawSchutzbedarf']['c'] ?? null,
                'i' => $row['rawSchutzbedarf']['i'] ?? null,
                'a' => $row['rawSchutzbedarf']['a'] ?? null,
            ],
        ];
    }

    /**
     * @return array{root: ?SimpleXMLElement, error: ?string}
     */
    private function loadRoot(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['root' => null, 'error' => sprintf('File not readable: %s', $path)];
        }

        // XXE hardening: read the file into memory ourselves, then parse with
        // LIBXML_NONET so libxml refuses to fetch any external entities over
        // the network. Reading the file outside libxml means we don't need to
        // tweak the global external-entity loader (which has stricter typing
        // on PHP 8.5+).
        $xml = @file_get_contents($path);
        if ($xml === false) {
            return ['root' => null, 'error' => sprintf('Failed to read XML: %s', $path)];
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $root = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            if ($root === false) {
                $errors = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
                libxml_clear_errors();
                return ['root' => null, 'error' => 'XML parse error: ' . implode('; ', $errors)];
            }

            if ($root->getName() !== 'gstool-export') {
                return ['root' => null, 'error' => sprintf(
                    'Root element must be <gstool-export>, got <%s>',
                    $root->getName(),
                )];
            }

            $version = (string) $root['version'];
            if ($version !== self::SUPPORTED_VERSION) {
                return ['root' => null, 'error' => sprintf(
                    'Unsupported gstool-export version "%s"; expected "%s"',
                    $version,
                    self::SUPPORTED_VERSION,
                )];
            }

            return ['root' => $root, 'error' => null];
        } finally {
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * @return iterable<SimpleXMLElement>
     */
    private function iterateZielobjekte(SimpleXMLElement $root): iterable
    {
        if (!isset($root->zielobjekte)) {
            return [];
        }
        return $root->zielobjekte->zielobjekt;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapZielobjekt(SimpleXMLElement $zo): array
    {
        $name = trim((string) $zo->name);
        $rawType = trim((string) $zo['type']);
        $rawSchutzbedarf = [
            'c' => $zo->schutzbedarf !== null ? trim((string) $zo->schutzbedarf->vertraulichkeit) : '',
            'i' => $zo->schutzbedarf !== null ? trim((string) $zo->schutzbedarf->integritaet) : '',
            'a' => $zo->schutzbedarf !== null ? trim((string) $zo->schutzbedarf->verfuegbarkeit) : '',
        ];

        if ($name === '') {
            return [
                'id' => trim((string) $zo['id']) ?: null,
                'name' => null,
                'description' => null,
                'owner' => null,
                'locationLabel' => null,
                'assetType' => 'other',
                'confidentiality' => null,
                'integrity' => null,
                'availability' => null,
                'rawType' => $rawType,
                'rawSchutzbedarf' => $rawSchutzbedarf,
                'action' => 'error',
                'error' => 'Zielobjekt without <name>',
            ];
        }

        $assetType = self::TYPE_MAP[strtolower($rawType)] ?? 'other';

        $description = trim((string) $zo->kurzbeschreibung);
        $owner = trim((string) $zo->verantwortlich);
        $locationLabel = trim((string) $zo->standort);

        return [
            'id' => trim((string) $zo['id']) ?: null,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'owner' => $owner !== '' ? $owner : null,
            'locationLabel' => $locationLabel !== '' ? $locationLabel : null,
            'assetType' => $assetType,
            'confidentiality' => $this->mapSchutzbedarfValue($rawSchutzbedarf['c']),
            'integrity' => $this->mapSchutzbedarfValue($rawSchutzbedarf['i']),
            'availability' => $this->mapSchutzbedarfValue($rawSchutzbedarf['a']),
            'rawType' => $rawType,
            'rawSchutzbedarf' => $rawSchutzbedarf,
            'action' => 'unknown',
            'error' => null,
        ];
    }

    private function mapSchutzbedarfValue(string $raw): ?int
    {
        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }
        return self::SCHUTZBEDARF_MAP[$key] ?? null;
    }
}
