<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Load MRIS — Mythos-resistente Informationssicherheit v1.5 framework.
 *
 * Reads `fixtures/frameworks/mris-v1.5.yaml` and seeds the framework + the
 * 13 Mythos-Härtungs-Controls (MHC-01..13). Idempotent — re-runs update
 * existing rows without duplicates.
 *
 * Source attribution (CC-BY-4.0):
 *   Peddi, Richard (2026): MRIS — Mythos-resistente Informationssicherheit, v1.5.
 */
#[AsCommand(
    name: 'app:load-mris-requirements',
    description: 'Load MRIS v1.5 framework + 13 MHC requirements from fixtures/frameworks/mris-v1.5.yaml',
)]
final class LoadMrisRequirementsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $path = $this->projectDir . '/fixtures/frameworks/mris-v1.5.yaml';
        if (!is_file($path)) {
            $io->error(sprintf('MRIS fixture not found: %s', $path));
            return Command::FAILURE;
        }

        $data = Yaml::parseFile($path);
        $fw = $data['framework'] ?? null;
        $requirements = $data['requirements'] ?? [];

        if (!is_array($fw) || empty($requirements)) {
            $io->error('Invalid YAML structure — framework or requirements key missing.');
            return Command::FAILURE;
        }

        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => $fw['code']]);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework
            ->setCode($fw['code'])
            ->setName($fw['name'])
            ->setVersion((string) ($fw['version'] ?? '1.5'))
            ->setDescription((string) ($fw['description'] ?? ''))
            ->setApplicableIndustry((string) ($fw['applicable_industry'] ?? 'all_sectors'))
            ->setRegulatoryBody((string) ($fw['regulatory_body'] ?? 'Independent (Open Standard)'))
            ->setMandatory(false)
            ->setScopeDescription((string) ($fw['scope_description'] ?? ''))
            ->setActive(true);

        if ($isNew) {
            $this->entityManager->persist($framework);
            $io->text(sprintf('Created framework: %s', $framework->getCode()));
        } else {
            $io->text(sprintf('Updated framework: %s', $framework->getCode()));
        }

        $created = 0;
        $updated = 0;
        foreach ($requirements as $req) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy(['framework' => $framework, 'requirementId' => $req['requirement_id']]);
            $isNewReq = !$existing instanceof ComplianceRequirement;
            $entity = $existing ?? new ComplianceRequirement();
            $entity
                ->setFramework($framework)
                ->setRequirementId($req['requirement_id'])
                ->setTitle((string) ($req['title'] ?? ''))
                ->setDescription((string) ($req['description'] ?? ''))
                ->setCategory((string) ($req['category'] ?? 'mythos_haertung'))
                ->setPriority((string) ($req['priority'] ?? 'medium'));

            if ($isNewReq) {
                $this->entityManager->persist($entity);
                $created++;
            } else {
                $updated++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'MRIS framework loaded: %d requirements created, %d updated.',
            $created,
            $updated,
        ));
        $io->note('Source: Peddi, R. (2026). MRIS v1.5. License: CC BY 4.0.');

        return Command::SUCCESS;
    }
}
