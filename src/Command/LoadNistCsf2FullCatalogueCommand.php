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
 * Registry-bound loader (`app.framework_loader`, code NIST-CSF-2.0) for the FULL
 * NIST CSF 2.0 catalogue (106 active subcategories, ids like GV.OC-01) from
 * fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json.
 *
 * NIST code reconciliation: the canonical DB code is NIST-CSF-2.0 — the
 * nist-csf-2-0 ↔ iso27001-2022 mappings declare it, migration
 * Version20260506213529 merged the legacy NIST-CSF row INTO NIST-CSF-2.0, and
 * the framework metadata row uses it. The partial LoadNistCsfRequirementsCommand
 * registered the obsolete code NIST-CSF (66 subcategories) — for which NO mapping
 * exists — so the 106-source NIST→ISO mapping had no registered loader at all and
 * silent-skipped every pair. This loader binds the full 106-subcategory superset
 * under the canonical code; the partial command is no longer registry-bound.
 */
#[AsCommand(
    name: 'app:load-nist-csf-2-0-full-catalogue',
    description: 'Load the full NIST CSF 2.0 catalogue (106 active subcategories) from fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json as ComplianceRequirement rows.'
)]
final class LoadNistCsf2FullCatalogueCommand extends Command implements FrameworkLoaderInterface
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
        return 'NIST-CSF-2.0';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $jsonPath = $this->projectDir . '/fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json';
        if (!is_readable($jsonPath)) {
            $io?->error("Inventory not found: {$jsonPath}");
            return Command::FAILURE;
        }
        $framework = $this->resolveFramework();

        $inventory = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($inventory)) {
            $io?->error("Inventory JSON malformed.");
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach ($inventory as $reqId => $title) {
            // Function code is the prefix (GV, ID, PR, DE, RS, RC)
            $function = explode('.', (string) $reqId, 2)[0] ?? null;
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
            $req->setTitle(mb_substr((string) $title, 0, 250));
            $req->setDescription(sprintf("NIST CSF 2.0 Subcategory %s. Quelle: NIST CSWP.29 (final 2024-02-26, public domain).\n\n%s", $reqId, $title));
            $req->setCategory($function);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io?->success(sprintf('NIST CSF 2.0 catalogue: %d created, %d updated.', $created, $updated));
        return Command::SUCCESS;
    }

    /**
     * Resolve (or create) the NIST-CSF-2.0 framework row so the loader works
     * standalone. Canonical code per migration Version20260506213529.
     */
    private function resolveFramework(): ComplianceFramework
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'NIST-CSF-2.0']);
        if ($framework instanceof ComplianceFramework) {
            return $framework;
        }

        $framework = new ComplianceFramework();
        $framework->setCode('NIST-CSF-2.0')
            ->setName('NIST Cybersecurity Framework 2.0')
            ->setDescription('NIST Cybersecurity Framework 2.0 (NIST CSWP.29, final 2024-02-26) — full catalogue of 106 active subcategories.')
            ->setVersion('2.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('NIST (National Institute of Standards and Technology)')
            ->setMandatory(false)
            ->setScopeDescription('Voluntary framework to help organizations manage cybersecurity risks.')
            ->setActive(true);
        $this->em->persist($framework);

        return $framework;
    }
}
