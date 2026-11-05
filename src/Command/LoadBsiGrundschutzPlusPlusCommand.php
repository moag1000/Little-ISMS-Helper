<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Compliance\FrameworkLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registry-bound loader (`app.framework_loader`, code BSI-GSPP) for the BSI
 * Grundschutz++ Anwenderkatalog — the requirement catalogue of BSI-Standard
 * 300-1 "Methodik Grundschutz++" (published 2026-10-26).
 *
 * Reads fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json,
 * generated from the official OSCAL catalogue of the BSI
 * Stand-der-Technik-Bibliothek (CC BY-SA 4.0) by
 * scripts/library/build_bsi_grundschutz_plusplus.py.
 *
 * All 1000 requirements are top-level rows (no parentRequirement): Grundschutz++
 * counts every nested control as a requirement of its own with its own modal
 * verb, so each one belongs in the coverage denominator. The OSCAL parent is kept
 * in dataSourceMapping.gspp.parent for display.
 *
 * Tier (absicherungsStufe): basis = MUSS on the normal level, standard =
 * SOLLTE/KANN on the normal level, hoch = raised level ("erhöht").
 */
#[AsCommand(
    name: 'app:load-bsi-grundschutz-plusplus',
    description: 'Load the BSI Grundschutz++ Anwenderkatalog (BSI-Standard 300-1, 1000 requirements) from fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json.'
)]
final class LoadBsiGrundschutzPlusPlusCommand extends Command implements FrameworkLoaderInterface
{
    private const CODE = 'BSI-GSPP';
    private const CATALOGUE = 'fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json';

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return self::CODE;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements((bool) $input->getOption('update'), new SymfonyStyle($input, $output));
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $path = $this->projectDir . '/' . self::CATALOGUE;
        if (!is_readable($path)) {
            $io?->error("Catalogue not found: {$path}");
            return Command::FAILURE;
        }
        $catalogue = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalogue) || !is_array($catalogue['requirements'] ?? null)) {
            $io?->error('Catalogue JSON has no "requirements" list.');
            return Command::FAILURE;
        }

        $framework = $this->resolveFramework($catalogue['source'] ?? []);
        $topicTitles = $this->topicTitles($catalogue['groups'] ?? []);
        $repo = $this->em->getRepository(ComplianceRequirement::class);
        $existing = [];
        foreach ($repo->findBy(['framework' => $framework]) as $req) {
            $existing[(string) $req->getRequirementId()] = $req;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($catalogue['requirements'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $req = $existing[$id] ?? null;
            if ($req !== null && !$update) {
                $skipped++;
                continue;
            }
            if ($req === null) {
                $req = (new ComplianceRequirement())
                    ->setFramework($framework)
                    ->setRequirementId($id);
                $this->em->persist($req);
                $created++;
            } else {
                $updated++;
            }
            $this->apply($req, $row, $topicTitles);
        }

        $this->em->flush();
        $io?->success(sprintf(
            'BSI Grundschutz++: %d created, %d updated, %d skipped (total in catalogue: %d).',
            $created,
            $updated,
            $skipped,
            count($catalogue['requirements']),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $topicTitles
     */
    private function apply(ComplianceRequirement $req, array $row, array $topicTitles): void
    {
        $modalVerb = (string) ($row['modal_verb'] ?? '');
        $effort = isset($row['effort_level']) ? (int) $row['effort_level'] : null;
        $topic = (string) ($row['topic'] ?? '');

        $description = trim((string) ($row['statement'] ?? ''));
        $guidance = trim((string) ($row['guidance'] ?? ''));
        if ($guidance !== '') {
            $description .= "\n\n" . $guidance;
        }
        $description .= sprintf(
            "\n\nQuelle: BSI Anwenderkatalog Grundschutz++ (BSI-Standard 300-1), %s — %s. Lizenz CC BY-SA 4.0.",
            $topic,
            $topicTitles[$topic] ?? '',
        );

        $dataSourceMapping = [
            'gspp' => [
                'practice' => $row['practice'] ?? null,
                'topic' => $topic,
                'parent' => $row['parent'] ?? null,
                'kind' => $row['kind'] ?? null,
                'modal_verb' => $modalVerb,
                'sec_level' => $row['sec_level'] ?? null,
                'effort_level' => $effort,
                'target_object_categories' => $row['target_object_categories'] ?? [],
                'documentation' => $row['documentation'] ?? null,
                'security_targets' => $row['security_targets'] ?? [],
                'threats' => $row['threats'] ?? [],
                'tags' => $row['tags'] ?? [],
            ],
        ];
        if (($row['asset_types'] ?? []) !== []) {
            $dataSourceMapping['asset_types'] = $row['asset_types'];
        }
        foreach ((array) ($row['reuse_flags'] ?? []) as $flag => $value) {
            $dataSourceMapping[$flag] = $value;
        }

        $req->setTitle(mb_substr((string) ($row['title'] ?? $req->getRequirementId()), 0, 255))
            ->setDescription($description)
            ->setCategory($topic)
            ->setPriority($this->priority($modalVerb, $effort))
            ->setRequirementType('detailed')
            ->setAbsicherungsStufe((string) ($row['tier'] ?? 'standard'))
            ->setDataSourceMapping($dataSourceMapping);
    }

    /**
     * Effort level 0 marks requirements the BSI expects immediately ("Stufe 0").
     */
    private function priority(string $modalVerb, ?int $effort): string
    {
        return match (true) {
            $modalVerb === 'MUSS' && $effort === 0 => 'critical',
            $modalVerb === 'MUSS' => 'high',
            $modalVerb === 'SOLLTE' => 'medium',
            default => 'low',
        };
    }

    /**
     * @param list<array{id?: string, title?: string, topics?: list<array{id?: string, title?: string}>}> $groups
     * @return array<string, string>
     */
    private function topicTitles(array $groups): array
    {
        $titles = [];
        foreach ($groups as $group) {
            $titles[(string) ($group['id'] ?? '')] = (string) ($group['title'] ?? '');
            foreach ($group['topics'] ?? [] as $topic) {
                $titles[(string) ($topic['id'] ?? '')] = (string) ($topic['title'] ?? '');
            }
        }

        return $titles;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function resolveFramework(array $source): ComplianceFramework
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => self::CODE]);
        if (!$framework instanceof ComplianceFramework) {
            $framework = (new ComplianceFramework())
                ->setCode(self::CODE)
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('BSI (Bundesamt für Sicherheit in der Informationstechnik)')
                ->setMandatory(false)
                ->setActive(true);
            $this->em->persist($framework);
        }

        $framework
            ->setName('BSI Grundschutz++ (BSI-Standard 300-1)')
            ->setDescription(
                'Anwenderkatalog Grundschutz++ des BSI zum BSI-Standard 300-1 (Methodik Grundschutz++): '
                . '95 Methodik-Anforderungen, 4 Anforderungen zum Risikomanagement und 901 Anforderungen des '
                . 'Stand-der-Technik-Kernels. Zertifizierbar ab 2027, kompatibel zu ISO/IEC 27001.'
            )
            ->setVersion((string) ($source['version'] ?? '2026'))
            ->setScopeDescription(
                'Prozessorientierte Weiterentwicklung des IT-Grundschutzes für alle Institutionen; '
                . 'Sicherheitsniveaus normal (Stand der Technik) und erhöht.'
            );

        return $framework;
    }
}
