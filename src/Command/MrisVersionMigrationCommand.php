<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\MrisMaturityService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Migriert ein MRIS-Framework von einer Version auf eine neue (z. B. v1.5 → v1.6).
 *
 * Diff-Logik gegen die Library-YAML-Files:
 *   - Added:    in der neuen Version, aber nicht im Bestand → werden als
 *               neue ComplianceRequirements vorgemerkt (apply legt sie an).
 *   - Removed:  im Bestand, aber nicht mehr in der neuen Version → werden auf
 *               lifecycle_state=deprecated gesetzt (Soft-Delete, Audit-Trail bleibt).
 *   - Renamed/Changed: gleiche requirement_id, anderer title oder description →
 *               Update vorgemerkt.
 *   - Maturity-Stages-Wechsel: pro MHC werden die definierten Reifegradstufen
 *               verglichen; bei Änderung wird das lokale maturityCurrent als
 *               unbestimmt markiert (User muss neu setzen).
 * Forward- und Reverse-Mapping-YAMLs werden ebenfalls verglichen.
 *
 * Sicher per Default: ohne --apply wird nur der Migrationsplan gedruckt.
 *
 * Quelle MRIS-Konzepte: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: CC BY 4.0.
 */
#[AsCommand(
    name: 'app:mris:migrate-version',
    description: 'Diff zwischen zwei MRIS-Whitepaper-Versionen (Quelle: Peddi 2026, MRIS v1.5, CC BY 4.0); zeigt Migrationsplan, --apply migriert verbindlich.',
)]
final class MrisVersionMigrationCommand extends Command
{
    private const string FRAMEWORK_CODE_PREFIX = 'MRIS-';
    private const string DEPRECATED_KEY        = 'lifecycle_state';
    private const string DEPRECATED_VALUE      = 'deprecated';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly MrisMaturityService $maturityService,
        private readonly AuditLogger $auditLogger,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Quell-Version (z. B. "v1.5"); ohne Angabe wird die aktuell aktive DB-Version verwendet.')
            ->addOption('to',   null, InputOption::VALUE_REQUIRED, 'Ziel-Version (z. B. "v1.6"); Pflicht. Datei muss unter fixtures/frameworks/mris-<v>.yaml liegen.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur Vorschau (Default-Verhalten — auch ohne Flag wird ohne --apply nichts geschrieben).')
            ->addOption('apply',   null, InputOption::VALUE_NONE, 'Wendet die Migration verbindlich an. Ohne dieses Flag werden keine Daten geschrieben.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $to = $input->getOption('to');
        if (!is_string($to) || $to === '') {
            $io->error('--to=<version> ist Pflicht.');
            return Command::FAILURE;
        }
        $to = $this->normalizeVersion($to);

        $from = $input->getOption('from');
        if (!is_string($from) || $from === '') {
            $from = $this->detectActiveVersion();
            if ($from === null) {
                $io->error('Konnte aktive MRIS-Version nicht aus DB ermitteln. Bitte --from=<version> angeben.');
                return Command::FAILURE;
            }
            $io->note(sprintf('--from nicht angegeben — verwende aktive DB-Version: %s', $from));
        }
        $from = $this->normalizeVersion($from);

        if ($from === $to) {
            $io->error(sprintf('--from und --to sind identisch (%s). Migration nicht sinnvoll.', $from));
            return Command::FAILURE;
        }

        $apply  = (bool) $input->getOption('apply');
        $dryRun = !$apply; // Default: nur Vorschau, nichts schreiben.

        $fromFile = $this->frameworkFixturePath($from);
        $toFile   = $this->frameworkFixturePath($to);
        if (!is_file($fromFile)) {
            $io->error(sprintf('Quell-YAML nicht gefunden: %s', $fromFile));
            return Command::FAILURE;
        }
        if (!is_file($toFile)) {
            $io->error(sprintf('Ziel-YAML nicht gefunden: %s', $toFile));
            return Command::FAILURE;
        }

        try {
            $fromYaml = Yaml::parseFile($fromFile);
            $toYaml   = Yaml::parseFile($toFile);
        } catch (\Throwable $e) {
            $io->error('YAML-Parsing-Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $fromReqs = $this->indexRequirements($fromYaml);
        $toReqs   = $this->indexRequirements($toYaml);

        $diff = $this->diffRequirements($fromReqs, $toReqs);
        $mappingDiff = $this->diffMappings($from, $to);

        $io->title(sprintf('MRIS-Versions-Migration %s → %s', $from, $to));
        $io->writeln('Quelle: Peddi (2026) MRIS v1.5 — CC BY 4.0.');
        $io->writeln($apply ? '<info>Modus: APPLY (Daten werden geschrieben)</info>' : '<comment>Modus: DRY-RUN (keine Änderungen)</comment>');
        $io->newLine();

        $this->printPlan($io, $from, $to, $diff, $mappingDiff);

        if ($dryRun) {
            $io->success('Dry-Run abgeschlossen. Mit --apply ausführen, um die Migration anzuwenden.');
            return Command::SUCCESS;
        }

        // Apply-Pfad: Framework holen, sonst abbrechen.
        $framework = $this->loadFrameworkForVersion($from);
        if ($framework === null) {
            $io->error(sprintf('Framework "%s%s" nicht im System — bitte zuerst Library laden.', self::FRAMEWORK_CODE_PREFIX, $from));
            return Command::FAILURE;
        }

        try {
            $applied = $this->applyDiff($framework, $diff, $from, $to);
        } catch (\Throwable $e) {
            $io->error('Apply-Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->writeln(sprintf('Angewendet: <info>%d</info> Operationen.', $applied));
        $io->success(sprintf('Migration %s → %s abgeschlossen.', $from, $to));
        return Command::SUCCESS;
    }

    /**
     * Liest die aktuell aktive MRIS-Version aus der DB (höchste vorhandene MRIS-*-Code).
     */
    private function detectActiveVersion(): ?string
    {
        $frameworks = $this->frameworkRepository->findAll();
        $versions   = [];
        foreach ($frameworks as $fw) {
            if (!$fw instanceof ComplianceFramework) {
                continue;
            }
            $code = (string) $fw->getCode();
            if (str_starts_with($code, self::FRAMEWORK_CODE_PREFIX)) {
                $versions[] = substr($code, strlen(self::FRAMEWORK_CODE_PREFIX));
            }
        }
        if ($versions === []) {
            return null;
        }
        usort($versions, [$this, 'compareVersions']);
        return end($versions) ?: null;
    }

    private function loadFrameworkForVersion(string $version): ?ComplianceFramework
    {
        $code = self::FRAMEWORK_CODE_PREFIX . $version;
        $fw = $this->frameworkRepository->findOneBy(['code' => $code]);
        return $fw instanceof ComplianceFramework ? $fw : null;
    }

    private function frameworkFixturePath(string $version): string
    {
        return $this->projectDir . '/fixtures/frameworks/mris-' . $version . '.yaml';
    }

    private function normalizeVersion(string $v): string
    {
        $v = trim($v);
        // Akzeptiert "v1.5", "1.5", "V1.5"
        if (!str_starts_with(strtolower($v), 'v')) {
            $v = 'v' . $v;
        } else {
            $v = 'v' . substr($v, 1);
        }
        return $v;
    }

    /**
     * @return array<string, array{title: string, description: string, maturity_levels: array<string,string>, raw: array<string,mixed>}>
     */
    private function indexRequirements(array $yaml): array
    {
        $out = [];
        foreach (($yaml['requirements'] ?? []) as $req) {
            if (!is_array($req)) {
                continue;
            }
            $id = (string) ($req['requirement_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'title' => (string) ($req['title'] ?? ''),
                'description' => (string) ($req['description'] ?? ''),
                'maturity_levels' => (array) ($req['maturity_levels'] ?? []),
                'raw' => $req,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array<string,mixed>> $from
     * @param array<string, array<string,mixed>> $to
     * @return array{added: list<string>, removed: list<string>, renamed: list<string>, maturity_changed: list<string>}
     */
    private function diffRequirements(array $from, array $to): array
    {
        $added   = array_values(array_diff(array_keys($to), array_keys($from)));
        $removed = array_values(array_diff(array_keys($from), array_keys($to)));
        $renamed = [];
        $maturityChanged = [];

        foreach ($to as $id => $newReq) {
            if (!isset($from[$id])) {
                continue;
            }
            $oldReq = $from[$id];
            if ($oldReq['title'] !== $newReq['title'] || $oldReq['description'] !== $newReq['description']) {
                $renamed[] = $id;
            }
            if ($oldReq['maturity_levels'] !== $newReq['maturity_levels']) {
                $maturityChanged[] = $id;
            }
        }

        sort($added);
        sort($removed);
        sort($renamed);
        sort($maturityChanged);
        return [
            'added' => $added,
            'removed' => $removed,
            'renamed' => $renamed,
            'maturity_changed' => $maturityChanged,
            'to' => $to,
            'from' => $from,
        ];
    }

    /**
     * @return array{updated: int, removed: int}
     */
    private function diffMappings(string $from, string $to): array
    {
        $dir = $this->projectDir . '/fixtures/library/mappings';
        $updated = 0;
        $removed = 0;

        $directions = [
            ['mris-' . $from . '_to_iso27001-2022_v1.0.yaml', 'mris-' . $to . '_to_iso27001-2022_v1.0.yaml'],
            ['iso27001-2022_to_mris-' . $from . '_v1.0.yaml', 'iso27001-2022_to_mris-' . $to . '_v1.0.yaml'],
        ];
        foreach ($directions as [$oldName, $newName]) {
            $oldPath = $dir . '/' . $oldName;
            $newPath = $dir . '/' . $newName;
            if (!is_file($oldPath) || !is_file($newPath)) {
                continue;
            }
            try {
                $oldYaml = Yaml::parseFile($oldPath);
                $newYaml = Yaml::parseFile($newPath);
            } catch (\Throwable) {
                continue;
            }
            $oldPairs = $this->mappingPairs($oldYaml);
            $newPairs = $this->mappingPairs($newYaml);

            $updated += count(array_diff($newPairs, $oldPairs));
            $removed += count(array_diff($oldPairs, $newPairs));
        }

        return ['updated' => $updated, 'removed' => $removed];
    }

    /**
     * @return list<string>
     */
    private function mappingPairs(array $yaml): array
    {
        $pairs = [];
        foreach (($yaml['mappings'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $s = (string) ($m['source'] ?? '');
            $t = (string) ($m['target'] ?? '');
            if ($s !== '' && $t !== '') {
                $pairs[] = $s . '|' . $t;
            }
        }
        sort($pairs);
        return $pairs;
    }

    /**
     * @param array{added: list<string>, removed: list<string>, renamed: list<string>, maturity_changed: list<string>} $diff
     * @param array{updated:int, removed:int} $mappingDiff
     */
    private function printPlan(SymfonyStyle $io, string $from, string $to, array $diff, array $mappingDiff): void
    {
        $io->section(sprintf('Migration %s → %s', $from, $to));

        $countAdded   = count($diff['added']);
        $countRemoved = count($diff['removed']);
        $countRenamed = count($diff['renamed']);
        $countMat     = count($diff['maturity_changed']);

        $rows = [
            ['MHC added',         $countAdded,   $countAdded   > 0 ? implode(', ', $diff['added'])           : '-'],
            ['MHC removed',       $countRemoved, $countRemoved > 0 ? implode(', ', $diff['removed'])         : '-'],
            ['MHC renamed',       $countRenamed, $countRenamed > 0 ? implode(', ', $diff['renamed'])         : '-'],
            ['Maturity changed',  $countMat,     $countMat     > 0 ? implode(', ', $diff['maturity_changed']): '-'],
            ['Mappings updated',  $mappingDiff['updated'], '-'],
            ['Mappings removed',  $mappingDiff['removed'], '-'],
        ];
        $io->table(['Kategorie', 'Anzahl', 'IDs'], $rows);

        // Nummerierte Operationsliste — Reihenfolge entspricht apply-Reihenfolge.
        $io->section('Geplante Operationen (Reihenfolge wie sie laufen würden)');
        $n = 0;
        foreach ($diff['added'] as $id) {
            $io->writeln(sprintf('  %d. ADD     ComplianceRequirement %s', ++$n, $id));
        }
        foreach ($diff['renamed'] as $id) {
            $io->writeln(sprintf('  %d. UPDATE  ComplianceRequirement %s (title/description)', ++$n, $id));
        }
        foreach ($diff['maturity_changed'] as $id) {
            $io->writeln(sprintf('  %d. RESET   maturity_current %s (Stages geändert — User muss neu setzen)', ++$n, $id));
        }
        foreach ($diff['removed'] as $id) {
            $io->writeln(sprintf('  %d. DEPRECATE ComplianceRequirement %s (Soft-Delete, Audit-Trail bleibt)', ++$n, $id));
        }
        if ($n === 0) {
            $io->writeln('  (keine Operationen — Versionen sind inhaltlich identisch)');
        }
    }

    /**
     * @param array{added: list<string>, removed: list<string>, renamed: list<string>, maturity_changed: list<string>, to: array<string, array<string,mixed>>, from: array<string, array<string,mixed>>} $diff
     */
    private function applyDiff(ComplianceFramework $framework, array $diff, string $from, string $to): int
    {
        $applied = 0;

        // ADD
        foreach ($diff['added'] as $id) {
            $payload = $diff['to'][$id]['raw'] ?? [];
            $req = new ComplianceRequirement();
            $req->setFramework($framework);
            $req->setRequirementId($id);
            $req->setTitle((string) ($payload['title'] ?? $id));
            $req->setDescription((string) ($payload['description'] ?? ''));
            $req->setCategory((string) ($payload['category'] ?? null) ?: null);
            $req->setPriority((string) ($payload['priority'] ?? 'medium'));
            $this->entityManager->persist($req);
            $this->entityManager->flush();

            $this->auditLogger->logCustom(
                action: 'MrisVersionMigration',
                entityType: 'ComplianceRequirement',
                entityId: $req->getId(),
                oldValues: null,
                newValues: ['requirement_id' => $id, 'title' => $req->getTitle()],
                description: sprintf('MRIS-Migration %s → %s: ADD %s', $from, $to, $id),
            );
            $applied++;
        }

        // UPDATE (title/description)
        foreach ($diff['renamed'] as $id) {
            $req = $this->requirementRepository->findOneBy(['framework' => $framework, 'requirementId' => $id]);
            if (!$req instanceof ComplianceRequirement) {
                continue;
            }
            $oldTitle = $req->getTitle();
            $oldDesc  = $req->getDescription();
            $newTitle = (string) ($diff['to'][$id]['title'] ?? $oldTitle);
            $newDesc  = (string) ($diff['to'][$id]['description'] ?? $oldDesc);

            $req->setTitle($newTitle);
            $req->setDescription($newDesc);
            $this->entityManager->flush();

            $this->auditLogger->logCustom(
                action: 'MrisVersionMigration',
                entityType: 'ComplianceRequirement',
                entityId: $req->getId(),
                oldValues: ['title' => $oldTitle, 'description' => $oldDesc],
                newValues: ['title' => $newTitle, 'description' => $newDesc],
                description: sprintf('MRIS-Migration %s → %s: UPDATE %s (title/description)', $from, $to, $id),
            );
            $applied++;
        }

        // MATURITY RESET
        foreach ($diff['maturity_changed'] as $id) {
            $req = $this->requirementRepository->findOneBy(['framework' => $framework, 'requirementId' => $id]);
            if (!$req instanceof ComplianceRequirement) {
                continue;
            }
            // MrisMaturityService loggt selbst.
            $this->maturityService->setCurrent($req, null);
            $applied++;
        }

        // DEPRECATE (soft-delete, kein DB-Delete)
        foreach ($diff['removed'] as $id) {
            $req = $this->requirementRepository->findOneBy(['framework' => $framework, 'requirementId' => $id]);
            if (!$req instanceof ComplianceRequirement) {
                continue;
            }
            $mapping = $req->getDataSourceMapping() ?? [];
            $oldState = $mapping[self::DEPRECATED_KEY] ?? 'active';
            $mapping[self::DEPRECATED_KEY]   = self::DEPRECATED_VALUE;
            $mapping['deprecated_in']        = $to;
            $mapping['deprecated_at']        = (new DateTimeImmutable())->format('c');
            $req->setDataSourceMapping($mapping);
            $this->entityManager->flush();

            $this->auditLogger->logCustom(
                action: 'MrisVersionMigration',
                entityType: 'ComplianceRequirement',
                entityId: $req->getId(),
                oldValues: ['lifecycle_state' => $oldState],
                newValues: ['lifecycle_state' => self::DEPRECATED_VALUE, 'deprecated_in' => $to],
                description: sprintf('MRIS-Migration %s → %s: DEPRECATE %s (Soft-Delete, Audit-Trail bleibt)', $from, $to, $id),
            );
            $applied++;
        }

        return $applied;
    }

    /**
     * Vergleicht zwei Versions-Strings (vX.Y) numerisch. Rückgabe wie strcmp.
     */
    private function compareVersions(string $a, string $b): int
    {
        $a = ltrim($a, 'vV');
        $b = ltrim($b, 'vV');
        return version_compare($a, $b);
    }
}
