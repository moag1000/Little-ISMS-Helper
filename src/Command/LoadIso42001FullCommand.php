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
 * ISO/IEC 42001:2023 AI Management System full Annex A control set (38 controls)
 * plus the management-system clauses 4-10. Loads identifier + short title for
 * cross-framework mapping resolution.
 */
#[AsCommand(
    name: 'app:load-iso42001-full',
    description: 'Load ISO/IEC 42001:2023 AIMS Annex A (38 controls) + clauses 4-10 as ComplianceRequirement rows.'
)]
final class LoadIso42001FullCommand extends Command
{
    /** @var array<string, string> */
    private const ANNEX_A = [
        'A.2.2'   => 'AI policy',
        'A.2.3'   => 'Alignment with other organisational policies',
        'A.2.4'   => 'Review of the AI policy',
        'A.3.2'   => 'AI roles and responsibilities',
        'A.3.3'   => 'Reporting of concerns',
        'A.4.2'   => 'Resource documentation',
        'A.4.3'   => 'Data resources',
        'A.4.4'   => 'Tooling resources',
        'A.4.5'   => 'System and computing resources',
        'A.4.6'   => 'Human resources',
        'A.5.2'   => 'AI system impact assessment process',
        'A.5.3'   => 'Documentation of AI system impact assessments',
        'A.5.4'   => 'Assessing AI system impact on individuals or groups',
        'A.5.5'   => 'Assessing societal impacts of AI systems',
        'A.6.1.2' => 'Objectives for responsible development of AI systems',
        'A.6.1.3' => 'Processes for responsible development of AI systems',
        'A.6.1.4' => 'Documentation of responsible development of AI systems',
        'A.6.2.2' => 'AI system requirements and specification',
        'A.6.2.3' => 'Documentation of AI system design and development',
        'A.6.2.4' => 'AI system verification and validation',
        'A.6.2.5' => 'AI system deployment',
        'A.6.2.6' => 'AI system operation and monitoring',
        'A.6.2.7' => 'AI system technical documentation',
        'A.6.2.8' => 'AI system event logs',
        'A.7.2'   => 'Data for development and enhancement of AI system',
        'A.7.3'   => 'Acquisition of data',
        'A.7.4'   => 'Quality of data for AI systems',
        'A.7.5'   => 'Data provenance',
        'A.7.6'   => 'Data preparation',
        'A.8.2'   => 'System documentation and information for users',
        'A.8.3'   => 'External reporting',
        'A.8.4'   => 'Communication of incidents',
        'A.8.5'   => 'Information for interested parties of AI systems',
        'A.9.2'   => 'Processes for responsible use of AI systems',
        'A.9.3'   => 'Objectives for responsible use of AI system',
        'A.9.4'   => 'Intended use of the AI system',
        'A.10.2'  => 'Allocation of responsibilities (third parties)',
        'A.10.3'  => 'Suppliers',
        'A.10.4'  => 'Customers',
    ];

    /** @var array<string, string> Management-system clauses */
    private const CLAUSES = [
        '4.1'  => 'Understanding the organisation and its context',
        '4.2'  => 'Understanding the needs and expectations of interested parties',
        '4.3'  => 'Determining the scope of the AI management system',
        '4.4'  => 'AI management system',
        '5.1'  => 'Leadership and commitment',
        '5.2'  => 'AI policy',
        '5.3'  => 'Roles, responsibilities and authorities',
        '6.1'  => 'Actions to address risks and opportunities (AI risk + impact)',
        '6.2'  => 'AI objectives and planning to achieve them',
        '6.3'  => 'Planning of changes',
        '7.1'  => 'Resources',
        '7.2'  => 'Competence',
        '7.3'  => 'Awareness',
        '7.4'  => 'Communication',
        '7.5'  => 'Documented information',
        '8.1'  => 'Operational planning and control',
        '8.2'  => 'AI risk assessment',
        '8.3'  => 'AI risk treatment',
        '8.4'  => 'AI system impact assessment',
        '9.1'  => 'Monitoring, measurement, analysis and evaluation',
        '9.2'  => 'Internal audit',
        '9.3'  => 'Management review',
        '10.1' => 'Continual improvement',
        '10.2' => 'Nonconformity and corrective action',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO42001']);
        if ($framework === null) {
            $io->error('Framework ISO42001 not in DB.');
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::ANNEX_A + self::CLAUSES as $reqId => $title) {
            $isAnnex = str_starts_with($reqId, 'A.');
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
            $req->setDescription(sprintf('ISO/IEC 42001:2023 / %s — %s. %s', $reqId, $title, $isAnnex ? 'Annex A control objective.' : 'Management-system clause.'));
            $req->setCategory($isAnnex ? 'Annex-A' : 'Management-System');
            $this->em->persist($req);
        }
        $this->em->flush();
        $total = count(self::ANNEX_A) + count(self::CLAUSES);
        $io->success(sprintf('ISO/IEC 42001:2023: %d created, %d updated. Total: %d (%d Annex A + %d clauses).', $created, $updated, $total, count(self::ANNEX_A), count(self::CLAUSES)));
        return Command::SUCCESS;
    }
}
