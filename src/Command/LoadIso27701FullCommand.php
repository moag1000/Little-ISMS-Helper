<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ISO/IEC 27701:2025 standalone Privacy Information Management System.
 *
 * Loads:
 * - Annex A: PII controller specific extended control set (~31 controls)
 * - Annex B: PII processor specific extended control set (~21 controls)
 * - Clauses 5-9 (PIMS-specific GDPR/PII guidance)
 */
#[AsCommand(
    name: 'app:load-iso27701-full',
    description: 'Load ISO/IEC 27701:2025 PIMS Annex A + B controls + clauses 5-9 as ComplianceRequirement rows.'
)]
final class LoadIso27701FullCommand extends Command
{
    /** @var array<string, string> Annex A — Controller-specific controls */
    private const ANNEX_A_CONTROLLER = [
        'A.7.2.1' => 'Identify and document purpose',
        'A.7.2.2' => 'Identify lawful basis',
        'A.7.2.3' => 'Determine when and how consent is to be obtained',
        'A.7.2.4' => 'Obtain and record consent',
        'A.7.2.5' => 'Privacy impact assessment',
        'A.7.2.6' => 'Contracts with PII processors',
        'A.7.2.7' => 'Joint PII controllers',
        'A.7.2.8' => 'Records related to processing PII',
        'A.7.3.1' => 'Determining and fulfilling obligations to PII principals',
        'A.7.3.2' => 'Determining information for PII principals',
        'A.7.3.3' => 'Providing information to PII principals',
        'A.7.3.4' => 'Providing mechanism to modify or withdraw consent',
        'A.7.3.5' => 'Providing mechanism to object to PII processing',
        'A.7.3.6' => 'Access, correction and/or erasure',
        'A.7.3.7' => 'PII controllers\' obligations to inform third parties',
        'A.7.3.8' => 'Providing copy of PII processed',
        'A.7.3.9' => 'Handling requests',
        'A.7.3.10' => 'Automated decision making',
        'A.7.4.1' => 'Identify basis for PII transfer between jurisdictions',
        'A.7.4.2' => 'Countries and international organisations to which PII can be transferred',
        'A.7.4.3' => 'Records of transfer of PII',
        'A.7.4.4' => 'Records of PII disclosure to third parties',
        'A.7.5.1' => 'Limit collection',
        'A.7.5.2' => 'Limit processing',
        'A.7.5.3' => 'Accuracy and quality',
        'A.7.5.4' => 'PII minimisation objectives',
        'A.7.5.5' => 'PII de-identification and deletion at end of processing',
        'A.7.5.6' => 'Temporary files',
        'A.7.5.7' => 'Retention',
        'A.7.5.8' => 'Disposal',
        'A.7.5.9' => 'PII transmission controls',
    ];

    /** @var array<string, string> Annex B — Processor-specific controls */
    private const ANNEX_B_PROCESSOR = [
        'B.8.2.1' => 'Customer agreement',
        'B.8.2.2' => 'Organisation\'s purposes',
        'B.8.2.3' => 'Marketing and advertising use',
        'B.8.2.4' => 'Infringing instruction',
        'B.8.2.5' => 'Customer obligations',
        'B.8.2.6' => 'Records related to processing PII',
        'B.8.3.1' => 'Obligations to PII principals',
        'B.8.4.1' => 'Temporary files',
        'B.8.4.2' => 'Return, transfer or disposal of PII',
        'B.8.4.3' => 'PII transmission controls',
        'B.8.5.1' => 'Basis for PII transfer between jurisdictions',
        'B.8.5.2' => 'Countries and international organisations to which PII can be transferred',
        'B.8.5.3' => 'Records of PII disclosure to third parties',
        'B.8.5.4' => 'Notification of PII disclosure requests',
        'B.8.5.5' => 'Legally binding PII disclosures',
        'B.8.5.6' => 'Disclosure of subcontractors used to process PII',
        'B.8.5.7' => 'Engagement of a subcontractor to process PII',
        'B.8.5.8' => 'Change of subcontractor to process PII',
    ];

    /** @var array<string, string> PIMS-specific clauses (selected) */
    private const CLAUSES = [
        '5.2.1' => 'Understanding the organization and its context (PIMS)',
        '5.2.2' => 'Understanding needs and expectations of interested parties (PIMS)',
        '5.2.3' => 'Determining the scope of the PIMS',
        '5.2.4' => 'PIMS information security and privacy management system',
        '5.3.1' => 'Leadership and commitment (PIMS)',
        '5.3.2' => 'Policy (PIMS)',
        '5.3.3' => 'Roles, responsibilities and authorities (PIMS)',
        '5.4.1' => 'Actions to address risks and opportunities (PIMS)',
        '5.4.2' => 'PIMS risk assessment',
        '5.4.3' => 'PIMS risk treatment',
        '6.1'   => 'Information security and privacy controls',
        '7.2.1' => 'Conditions for collection and processing',
        '8.2.1' => 'Conditions for collection and processing',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO27701']);
        if ($framework === null) {
            $io->error('Framework ISO27701 not in DB.');
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        $combined = self::ANNEX_A_CONTROLLER + self::ANNEX_B_PROCESSOR + self::CLAUSES;
        foreach ($combined as $reqId => $title) {
            $category = match (true) {
                str_starts_with($reqId, 'A.') => 'Annex-A-Controller',
                str_starts_with($reqId, 'B.') => 'Annex-B-Processor',
                default => 'PIMS-Clause',
            };
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
            $req->setTitle($title);
            $req->setDescription(sprintf('ISO/IEC 27701:2025 / %s — %s.', $reqId, $title));
            $req->setCategory($category);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('ISO/IEC 27701:2025: %d created, %d updated. Total: %d (%d Annex A controller + %d Annex B processor + %d clauses).',
            $created, $updated, count($combined), count(self::ANNEX_A_CONTROLLER), count(self::ANNEX_B_PROCESSOR), count(self::CLAUSES)));
        return Command::SUCCESS;
    }
}
