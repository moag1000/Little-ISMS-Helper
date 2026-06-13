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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registry-bound loader (`app.framework_loader`, code BSI-C5-2026) for the FULL
 * BSI C5:2026 catalogue (~174 criteria, ids like OIS-01 / IAM-08 / SSO-08).
 *
 * This is the single registry loader for code BSI-C5-2026: the cross-framework
 * mappings (bsi-c5-2026_to_iso27001-2022, bsi-c5-2026_to_nis2-art21,
 * iso27001-2022_to_bsi-c5-2026) reference the full-catalogue criterion ids, so
 * the partial LoadC52026RequirementsCommand (C5-2026-* delta ids) must NOT be
 * registry-bound or those mappings silent-skip 100 % of their pairs.
 */
#[AsCommand(
    name: 'app:load-c5-2026-full-catalogue',
    description: 'Load the full BSI C5:2026 catalogue (168 criteria) from the bundled BSI YAML in fixtures/library/catalogues/bsi-c5-2026-en/ as ComplianceRequirement rows.'
)]
final class LoadC52026FullCatalogueCommand extends Command implements FrameworkLoaderInterface
{
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
        return 'BSI-C5-2026';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $catDir = $this->projectDir . '/fixtures/library/catalogues/bsi-c5-2026-en';
        if (!is_dir($catDir)) {
            $io?->error("Catalogue directory not found: {$catDir}");
            return Command::FAILURE;
        }

        $framework = $this->resolveFramework();

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;

        foreach (glob($catDir . '/*.yml') ?: [] as $f) {
            $area = basename($f, '.yml');
            $content = file_get_contents($f);
            // The BSI YAML uses anchors with duplicate names which breaks Symfony YAML.
            // Parse with regex instead, since structure is regular. Two schemas
            // appear in the C5:2026 catalogue:
            //   1. anchored: `identifier: &ID_… '01'` + `name: '…'`  (most areas)
            //   2. plain:    `id: 'GC-01'` + `name: '…'`            (e.g. GC.yml)
            // The plain schema was silently dropped before, losing the 6 GC
            // (governance/jurisdiction) criteria — legally material for cloud
            // customers. Collect [$reqId, $num, $title] from BOTH schemas.
            $entries = [];
            preg_match_all(
                '/^  identifier:\s+&\S+\s+\'(\d+)\'\n  name:\s+\'([^\']+)\'/m',
                $content,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as [$_, $num, $title]) {
                $entries[] = [sprintf('%s-%s', $area, $num), $num, $title];
            }
            preg_match_all(
                '/^  id:\s+\'([^\']+)\'\n  name:\s+\'([^\']+)\'/m',
                $content,
                $idMatches,
                PREG_SET_ORDER
            );
            foreach ($idMatches as [$_, $fullId, $title]) {
                // The plain schema already carries the full prefixed id (e.g. GC-01).
                $num = str_starts_with($fullId, $area . '-') ? substr($fullId, strlen($area) + 1) : $fullId;
                $entries[] = [$fullId, $num, $title];
            }
            foreach ($entries as [$reqId, $num, $title]) {
                $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
                if ($req === null) {
                    $req = new ComplianceRequirement();
                    $req->setFramework($framework);
                    $req->setRequirementId($reqId);
                    $req->setRequirementType('core');
                    $req->setPriority('medium');
                    $created++;
                } else {
                    $updated++;
                }
                $req->setTitle(mb_substr($title, 0, 250));
                $req->setDescription(sprintf("BSI C5:2026 / %s / Kriterium %s. Quelle: BSI Cloud Computing Compliance Criteria Catalogue C5:2026 (final 2026-03-26, CC-BY-ND 4.0).\n\nTitel: %s", $area, $num, $title));
                $req->setCategory($area);
                $this->em->persist($req);
            }
        }
        $this->em->flush();
        $io?->success(sprintf('BSI C5:2026 full catalogue: %d created, %d updated.', $created, $updated));
        return Command::SUCCESS;
    }

    /**
     * Resolve (or create) the BSI-C5-2026 framework row so the loader works
     * standalone — the alignment migration normally seeds it, but a fresh DB
     * or programmatic invocation must not hard-fail.
     */
    private function resolveFramework(): ComplianceFramework
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI-C5-2026']);
        if ($framework instanceof ComplianceFramework) {
            return $framework;
        }

        $framework = new ComplianceFramework();
        $framework->setCode('BSI-C5-2026')
            ->setName('BSI C5:2026 - Cloud Computing Compliance Criteria Catalogue')
            ->setDescription('German Federal Office for Information Security (BSI) criteria catalogue for secure cloud computing, C5:2026 final release (full catalogue, ~174 criteria).')
            ->setVersion('2026')
            ->setApplicableIndustry('cloud_services')
            ->setRegulatoryBody('BSI - Bundesamt für Sicherheit in der Informationstechnik')
            ->setMandatory(false)
            ->setScopeDescription('Cloud service providers and cloud customers in Germany. Aligned with EUCS Substantial, ISO 27001:2022, NIS2.')
            ->setActive(true);
        $this->em->persist($framework);

        return $framework;
    }
}
