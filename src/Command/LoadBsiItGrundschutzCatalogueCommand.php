<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Single canonical loader for the BSI IT-Grundschutz-Kompendium 2023 catalogue.
 *
 * Reads YAML files from `fixtures/library/catalogues/bsi-it-grundschutz-2023/`
 * (one per Schicht: ISMS, ORP, CON, OPS, DER, APP, SYS, IND, NET, INF) and
 * persists Bausteine + Anforderungen as ComplianceRequirement rows.
 *
 * Replaces the 5 fragmented legacy loaders:
 *   - app:load-bsi-grundschutz-requirements
 *   - app:load-bsi-requirements
 *   - app:supplement-bsi-grundschutz-requirements
 *   - app:load-bsi-kompendium-delta
 *   - app:load-bsi-kompendium-extended
 *
 * Old commands remain functional as a Compat-Layer; they are marked
 * `@deprecated` and emit a notice. New deployments should use this loader.
 *
 * Idempotent: existing requirements (by framework+requirementId) are
 * UPDATEd in place when --update is set, otherwise SKIPped.
 */
#[AsCommand(
    name: 'app:load-bsi-grundschutz-catalogue',
    description: 'Load BSI IT-Grundschutz-Kompendium 2023 from canonical YAML catalogue tree (10 Schichten, 106 Bausteine, ~324 Anforderungen).',
)]
final class LoadBsiItGrundschutzCatalogueCommand
{
    private const CATALOGUE_DIR = 'fixtures/library/catalogues/bsi-it-grundschutz-2023';
    private const FRAMEWORK_CODE = 'BSI_GRUNDSCHUTZ';
    private const LAYERS = ['ISMS', 'ORP', 'CON', 'OPS', 'DER', 'APP', 'SYS', 'IND', 'NET', 'INF'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping')]
        bool $update = false,
        #[Option(name: 'layer', description: 'Only load a specific Schicht (ISMS|ORP|CON|OPS|DER|APP|SYS|IND|NET|INF)')]
        ?string $layer = null,
    ): int {
        $framework = $this->ensureFramework();

        $layers = $layer !== null ? [strtoupper($layer)] : self::LAYERS;
        $stats = ['bausteine' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($layers as $code) {
            $path = sprintf('%s/%s/%s.yml', $this->projectDir, self::CATALOGUE_DIR, $code);
            if (!is_file($path)) {
                $io->warning(sprintf('Catalogue file missing: %s', $path));
                continue;
            }
            $data = Yaml::parseFile($path);
            if (!is_array($data) || !isset($data['bausteine']) || !is_array($data['bausteine'])) {
                $io->warning(sprintf('Catalogue file %s.yml has no "bausteine" key — skipping.', $code));
                continue;
            }
            foreach ($data['bausteine'] as $baustein) {
                $stats['bausteine']++;
                $this->upsertBaustein($framework, $baustein, $update, $stats);
            }
        }

        $this->em->flush();
        $io->success(sprintf(
            'BSI Grundschutz catalogue: %d Bausteine processed — %d Anforderungen created, %d updated, %d skipped.',
            $stats['bausteine'],
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
        ));
        return Command::SUCCESS;
    }

    private function ensureFramework(): ComplianceFramework
    {
        $framework = $this->em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => self::FRAMEWORK_CODE]);

        if (!$framework instanceof ComplianceFramework) {
            $framework = (new ComplianceFramework())
                ->setCode(self::FRAMEWORK_CODE)
                ->setName('BSI IT-Grundschutz')
                ->setDescription('BSI IT-Grundschutz-Kompendium Edition 2023 — 10 Schichten, 106 Bausteine, Basis/Standard/Hoch-Anforderungen.')
                ->setVersion('Edition 2023')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('BSI (Bundesamt fuer Sicherheit in der Informationstechnik)')
                ->setMandatory(false)
                ->setScopeDescription('Comprehensive IT security standard for German organisations of all sizes; basis for ISO 27001 auf Basis von IT-Grundschutz.')
                ->setActive(true);
            $this->em->persist($framework);
            $this->em->flush();
        }
        return $framework;
    }

    /**
     * @param array<string, mixed> $baustein
     * @param array{bausteine:int, created:int, updated:int, skipped:int} $stats
     */
    private function upsertBaustein(
        ComplianceFramework $framework,
        array $baustein,
        bool $update,
        array &$stats,
    ): void {
        $bausteinId = (string) ($baustein['id'] ?? '');
        $bausteinTitle = (string) ($baustein['title'] ?? '');
        $bausteinDesc = (string) ($baustein['description'] ?? '');
        if ($bausteinId === '') {
            return;
        }

        $anforderungen = $baustein['anforderungen'] ?? [];
        if (!is_array($anforderungen)) {
            return;
        }

        foreach (['basis' => 'high', 'standard' => 'medium', 'hoch' => 'low'] as $stufe => $priority) {
            $items = $anforderungen[$stufe] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $anforderung) {
                if (!is_array($anforderung) || !isset($anforderung['id'])) {
                    continue;
                }
                $this->upsertRequirement(
                    framework: $framework,
                    requirementId: (string) $anforderung['id'],
                    title: (string) ($anforderung['title'] ?? ''),
                    text: (string) ($anforderung['requirement_text'] ?? ''),
                    bausteinId: $bausteinId,
                    bausteinTitle: $bausteinTitle,
                    bausteinDesc: $bausteinDesc,
                    stufe: $stufe,
                    priority: $priority,
                    update: $update,
                    stats: $stats,
                );
            }
        }
    }

    /**
     * @param array{bausteine:int, created:int, updated:int, skipped:int} $stats
     */
    private function upsertRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        string $title,
        string $text,
        string $bausteinId,
        string $bausteinTitle,
        string $bausteinDesc,
        string $stufe,
        string $priority,
        bool $update,
        array &$stats,
    ): void {
        $repo = $this->em->getRepository(ComplianceRequirement::class);
        $existing = $repo->findOneBy([
            'framework' => $framework,
            'requirementId' => $requirementId,
        ]);

        if ($existing instanceof ComplianceRequirement && !$update) {
            $stats['skipped']++;
            return;
        }

        $requirement = $existing ?? (new ComplianceRequirement())
            ->setFramework($framework)
            ->setRequirementId($requirementId);

        $requirement
            ->setTitle($title !== '' ? $title : $requirementId)
            ->setDescription($text !== '' ? $text : sprintf('%s — siehe BSI Kompendium 2023, Baustein %s.', $bausteinTitle, $bausteinId))
            ->setCategory($bausteinId)
            ->setPriority($priority)
            ->setRequirementType('detailed')
            ->setAbsicherungsStufe($stufe);

        if ($existing === null) {
            $this->em->persist($requirement);
            $stats['created']++;
        } else {
            $stats['updated']++;
        }
    }
}
