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
 * ISO/IEC 27017:2015 cloud-services controls.
 *
 * The standard adds 7 cloud-specific controls (CLD.*) to ISO 27002 and provides
 * cloud-specific implementation guidance for the existing 27002 controls. We
 * load both: the 7 new CLD controls AND the 37 ISO 27002 controls referenced
 * with cloud-specific guidance hooks (so cross-framework mappings can resolve
 * either side).
 */
#[AsCommand(
    name: 'app:load-iso27017-full',
    description: 'Load ISO/IEC 27017:2015 cloud-security controls (7 CLD.* extensions + ISO 27002 references with cloud guidance) as ComplianceRequirement rows.'
)]
final class LoadIso27017FullCommand extends Command
{
    /** @var array<string, string> */
    private const CLD_CONTROLS = [
        'CLD.6.3.1'  => 'Shared roles and responsibilities within a cloud computing environment',
        'CLD.8.1.5'  => 'Removal of cloud service customer assets',
        'CLD.9.5.1'  => 'Segregation in virtual computing environments',
        'CLD.9.5.2'  => 'Virtual machine hardening',
        'CLD.12.1.5' => 'Administrator\'s operational security',
        'CLD.12.4.5' => 'Monitoring of cloud services',
        'CLD.13.1.4' => 'Alignment of security management for virtual and physical networks',
    ];

    /**
     * Selected ISO 27002:2013 references where 27017 layers cloud-specific
     * implementation guidance. Identifiers follow the original 27002 structure
     * (e.g. 5.1.1) and are kept distinct from the 2022 Annex A renumbering.
     *
     * @var array<string, string>
     */
    private const REFERENCED_27002 = [
        '5.1.1'   => 'Policies for information security (cloud-specific guidance)',
        '6.1.1'   => 'Information security roles and responsibilities (cloud)',
        '6.1.3'   => 'Contact with authorities (cloud, multi-jurisdiction)',
        '7.2.2'   => 'Information security awareness, education and training (cloud)',
        '8.1.1'   => 'Inventory of assets (virtual + cloud)',
        '8.2.2'   => 'Labelling of information (cloud-customer data)',
        '9.1.2'   => 'Access to networks and network services (cloud)',
        '9.2.1'   => 'User registration and de-registration (cloud)',
        '9.4.1'   => 'Information access restriction (cloud)',
        '10.1.1'  => 'Cryptographic controls policy (cloud-customer keys)',
        '10.1.2'  => 'Key management (multi-tenant cloud)',
        '11.2.7'  => 'Secure disposal or re-use of equipment (cloud)',
        '12.1.2'  => 'Change management (cloud-platform changes)',
        '12.3.1'  => 'Information backup (cloud)',
        '12.4.1'  => 'Event logging (cloud-tenant separation)',
        '12.4.3'  => 'Administrator and operator logs (cloud)',
        '12.4.4'  => 'Clock synchronisation (multi-region cloud)',
        '12.6.1'  => 'Management of technical vulnerabilities (cloud-platform CVEs)',
        '13.1.3'  => 'Segregation in networks (multi-tenant)',
        '14.1.1'  => 'Information security requirements analysis (cloud)',
        '15.1.1'  => 'Information security policy for supplier relationships (cloud)',
        '16.1.2'  => 'Reporting information security events (cloud-customer reporting)',
        '17.1.1'  => 'Planning information security continuity (cloud-DR)',
        '18.1.1'  => 'Identification of applicable legislation (cloud, cross-border)',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO27017']);
        if ($framework === null) {
            $io->error('Framework ISO27017 not in DB. Run alignment migration.');
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;

        $combined = self::CLD_CONTROLS + self::REFERENCED_27002;
        foreach ($combined as $reqId => $title) {
            $isCld = str_starts_with($reqId, 'CLD.');
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType($isCld ? 'core' : 'detailed');
                $req->setPriority($isCld ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle($title);
            $req->setDescription(sprintf(
                'ISO/IEC 27017:2015 / %s — %s. %s',
                $reqId,
                $title,
                $isCld
                    ? 'Cloud-specific control added by 27017 on top of ISO 27002.'
                    : 'ISO 27002:2013 control with 27017 cloud-specific implementation guidance.'
            ));
            $req->setCategory($isCld ? 'CLD-extension' : 'ISO-27002-with-cloud-guidance');
            $this->em->persist($req);
        }
        $this->em->flush();

        $io->success(sprintf('ISO/IEC 27017:2015: %d created, %d updated. Total: %d (7 CLD + %d 27002 refs).',
            $created, $updated, count($combined), count(self::REFERENCED_27002)));
        return Command::SUCCESS;
    }
}
