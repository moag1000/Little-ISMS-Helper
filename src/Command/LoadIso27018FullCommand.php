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
 * ISO/IEC 27018:2019 PII protection in public clouds.
 *
 * Two layers:
 * - 27002 reference clauses with PII-specific implementation guidance
 * - Annex A: Public Cloud PII Processor extended control set (A.1 - A.11)
 */
#[AsCommand(
    name: 'app:load-iso27018-full',
    description: 'Load ISO/IEC 27018:2019 PII-in-cloud controls (Annex A extended set + 27002 references with PII guidance) as ComplianceRequirement rows.'
)]
final class LoadIso27018FullCommand extends Command
{
    /**
     * Annex A — Public Cloud PII Processor extended control set.
     *
     * @var array<string, string>
     */
    private const ANNEX_A = [
        'A.1.1'  => 'Obligation to co-operate regarding PII principals\' rights',
        'A.2.1'  => 'Public cloud PII processor\'s purpose',
        'A.2.2'  => 'Public cloud PII processor\'s commercial use',
        'A.3.1'  => 'Contract measures',
        'A.4.1'  => 'Records of PII disclosures to law enforcement',
        'A.5.1'  => 'Disclosures to law enforcement',
        'A.5.2'  => 'PII data return, transfer and disposal',
        'A.6.1'  => 'Confidentiality or non-disclosure agreements (PII context)',
        'A.6.2'  => 'PII processing restrictions',
        'A.7.1'  => 'Geographical location of PII',
        'A.7.2'  => 'Intended destination of PII',
        'A.8.1'  => 'Notification of a data breach involving PII',
        'A.9.1'  => 'Disclosure of sub-contracted PII processing',
        'A.9.2'  => 'Notification of changes to sub-contracted PII processing',
        'A.10.1' => 'Specifying use of cryptographic controls (PII transmission)',
        'A.10.2' => 'Restriction of the creation of hardcopy material',
        'A.10.3' => 'Control and logging of data restoration',
        'A.10.4' => 'Protecting data on storage media leaving the premises',
        'A.10.5' => 'Use of un-encrypted portable storage media and devices',
        'A.10.6' => 'Encryption of PII transmitted over public data-transmission networks',
        'A.10.7' => 'Secure disposal of hardcopy materials',
        'A.10.8' => 'Unique use of user IDs (PII access logs)',
        'A.10.9' => 'Records of authorized users',
        'A.10.10' => 'User ID management',
        'A.10.11' => 'Contract measures (PII collection)',
        'A.10.12' => 'Sub-contracted PII processing',
        'A.10.13' => 'Access to data on pre-used data storage space',
        'A.11.1' => 'Geographic location of PII processing',
        'A.11.2' => 'PII protection legislation compliance',
    ];

    /**
     * 27002:2013 references where 27018 layers PII-specific guidance.
     *
     * @var array<string, string>
     */
    private const REFERENCED_27002 = [
        '5.1.1'  => 'Policies for information security (PII-specific objectives)',
        '6.1.1'  => 'Information security roles (DPO / PII responsible)',
        '7.2.2'  => 'Awareness, education and training (PII handling)',
        '8.2.2'  => 'Labelling of information (PII categorisation)',
        '9.2.1'  => 'User registration and de-registration (PII access)',
        '10.1.1' => 'Cryptographic controls policy (PII protection)',
        '11.2.7' => 'Secure disposal or re-use of equipment (PII residue)',
        '12.4.1' => 'Event logging (PII access auditing)',
        '13.1.1' => 'Network controls (PII transmission)',
        '13.2.1' => 'Information transfer policies and procedures (PII)',
        '15.1.1' => 'Information security policy for supplier relationships (PII processors)',
        '16.1.1' => 'Responsibilities and procedures (PII breach response)',
        '18.1.4' => 'Privacy and protection of personally identifiable information',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO27018']);
        if ($framework === null) {
            $io->error('Framework ISO27018 not in DB. Run alignment migration.');
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;

        $combined = self::ANNEX_A + self::REFERENCED_27002;
        foreach ($combined as $reqId => $title) {
            $isAnnexA = str_starts_with($reqId, 'A.');
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType($isAnnexA ? 'core' : 'detailed');
                $req->setPriority($isAnnexA ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle($title);
            $req->setDescription(sprintf(
                'ISO/IEC 27018:2019 / %s — %s. %s',
                $reqId,
                $title,
                $isAnnexA
                    ? 'Public Cloud PII Processor extended control (Annex A).'
                    : 'ISO 27002:2013 control with 27018 PII-specific guidance.'
            ));
            $req->setCategory($isAnnexA ? 'PII-extension' : 'ISO-27002-with-PII-guidance');
            $this->em->persist($req);
        }
        $this->em->flush();

        $io->success(sprintf('ISO/IEC 27018:2019: %d created, %d updated. Total: %d (%d Annex A + %d 27002 refs).',
            $created, $updated, count($combined), count(self::ANNEX_A), count(self::REFERENCED_27002)));
        return Command::SUCCESS;
    }
}
