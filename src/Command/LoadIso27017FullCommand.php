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

/**
 * ISO/IEC 27017:2015 cloud-services controls.
 *
 * The standard adds 7 cloud-specific controls (CLD.*) to ISO 27002 and provides
 * cloud-specific implementation guidance for the existing 27002:2013 controls. We
 * load both: the 7 new CLD controls AND the 88 ISO 27002:2013 controls referenced
 * with cloud-specific guidance hooks (the full set the vetted decomposition
 * documents) so the cross-framework mappings can resolve either side.
 */
#[AsCommand(
    name: 'app:load-iso27017-full',
    description: 'Load ISO/IEC 27017:2015 cloud-security controls (7 CLD.* extensions + ISO 27002 references with cloud guidance) as ComplianceRequirement rows.'
)]
final class LoadIso27017FullCommand extends Command implements FrameworkLoaderInterface
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
     * ISO 27002:2013 controls for which ISO/IEC 27017:2015 layers cloud-specific
     * implementation guidance, as listed in the project's vetted ISO 27017 -> ISO
     * 27001 decomposition (fixtures/library/decompositions/decomp_iso27017_iso27001.json).
     * Identifiers follow the original ISO 27002:2013 Annex A structure (e.g. 5.1.1)
     * and are kept distinct from the 2022 Annex A renumbering. Titles are the
     * authentic ISO 27002:2013 control names. The cross-framework crosswalks
     * (iso27017_to_bsi-c5-2026, bsi-c5-2026_to_iso27017) reference these ids, so
     * the loaded catalogue MUST cover the full decomposed set or those mapping
     * pairs silent-skip.
     *
     * @var array<string, string>
     */
    private const REFERENCED_27002 = [
        '5.1.1'    => 'Policies for information security',
        '5.1.2'    => 'Review of the policies for information security',
        '6.1.1'    => 'Information security roles and responsibilities',
        '6.1.2'    => 'Segregation of duties',
        '6.1.3'    => 'Contact with authorities',
        '6.1.4'    => 'Contact with special interest groups',
        '6.1.5'    => 'Information security in project management',
        '6.2.1'    => 'Mobile device policy',
        '6.2.2'    => 'Teleworking',
        '7.2.1'    => 'Management responsibilities',
        '7.2.2'    => 'Information security awareness, education and training',
        '7.3.1'    => 'Termination and change of employment — security',
        '8.1.1'    => 'Inventory of assets',
        '8.1.2'    => 'Ownership of assets',
        '8.1.3'    => 'Acceptable use of assets',
        '8.1.4'    => 'Return of assets',
        '8.2.1'    => 'Classification of information',
        '8.2.2'    => 'Labelling of information',
        '8.2.3'    => 'Handling of assets',
        '8.3.1'    => 'Management of removable media',
        '9.1.1'    => 'Access control policy',
        '9.1.2'    => 'Access to networks and network services',
        '9.2.1'    => 'User registration and de-registration',
        '9.2.2'    => 'User access provisioning',
        '9.2.3'    => 'Management of privileged access rights',
        '9.2.4'    => 'Management of secret authentication information of users',
        '9.2.5'    => 'Review of user access rights',
        '9.2.6'    => 'Removal or adjustment of access rights',
        '9.3.1'    => 'Use of secret authentication information',
        '9.4.1'    => 'Information access restriction',
        '9.4.2'    => 'Secure log-on procedures',
        '9.4.3'    => 'Password management system',
        '9.4.4'    => 'Use of privileged utility programs',
        '9.4.5'    => 'Access control to program source code',
        '10.1.1'   => 'Policy on the use of cryptographic controls',
        '10.1.2'   => 'Key management',
        '11.1.4'   => 'Protecting against external and environmental threats',
        '11.2.6'   => 'Security of equipment and assets off-premises',
        '11.2.7'   => 'Secure disposal or re-use of equipment',
        '12.1.1'   => 'Documented operating procedures',
        '12.1.2'   => 'Change management',
        '12.1.3'   => 'Capacity management',
        '12.2.1'   => 'Controls against malware',
        '12.3.1'   => 'Information backup',
        '12.4.1'   => 'Event logging',
        '12.4.2'   => 'Protection of log information',
        '12.4.3'   => 'Administrator and operator logs',
        '12.4.4'   => 'Clock synchronisation',
        '12.5.1'   => 'Installation of software on operational systems',
        '12.6.1'   => 'Management of technical vulnerabilities',
        '12.6.2'   => 'Restrictions on software installation',
        '12.7.1'   => 'Information systems audit controls',
        '13.1.1'   => 'Network controls',
        '13.1.2'   => 'Security of network services',
        '13.1.3'   => 'Segregation in networks',
        '13.2.1'   => 'Information transfer policies and procedures',
        '13.2.2'   => 'Agreements on information transfer',
        '14.1.1'   => 'Information security requirements analysis and specification',
        '14.1.2'   => 'Securing application services on public networks',
        '14.1.3'   => 'Protecting application services transactions',
        '14.2.1'   => 'Secure development policy',
        '14.2.5'   => 'Secure system engineering principles',
        '14.2.6'   => 'Secure development environment',
        '14.2.8'   => 'System security testing',
        '14.2.9'   => 'System acceptance testing',
        '15.1.1'   => 'Information security policy for supplier relationships',
        '15.1.2'   => 'Addressing security within supplier agreements',
        '15.1.3'   => 'ICT supply chain',
        '15.2.1'   => 'Monitoring and review of supplier services',
        '15.2.2'   => 'Managing changes to supplier services',
        '16.1.1'   => 'Responsibilities and procedures — incident management',
        '16.1.2'   => 'Reporting information security events',
        '16.1.4'   => 'Assessment of and decision on information security events',
        '16.1.5'   => 'Response to information security incidents',
        '16.1.6'   => 'Learning from information security incidents',
        '16.1.7'   => 'Collection of evidence',
        '17.1.1'   => 'Planning information security continuity',
        '17.1.2'   => 'Implementing information security continuity',
        '17.1.3'   => 'Verify, review and evaluate information security continuity',
        '17.2.1'   => 'Availability of information processing facilities',
        '18.1.1'   => 'Identification of applicable legislation and contractual requirements',
        '18.1.2'   => 'Intellectual property rights',
        '18.1.3'   => 'Protection of records',
        '18.1.4'   => 'Privacy and protection of personally identifiable information',
        '18.1.5'   => 'Regulation of cryptographic controls',
        '18.2.1'   => 'Independent review of information security',
        '18.2.2'   => 'Compliance with security policies and standards',
        '18.2.3'   => 'Technical compliance review',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'ISO27017';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO27017']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
            $framework->setCode('ISO27017')
                ->setName('ISO/IEC 27017:2015 — Cloud Security')
                ->setDescription('Cloud-spezifische Sicherheitscontrols (7 CLD-Controls) + cloud-bezogene Umsetzungsleitlinien zu ISO 27002.')
                ->setVersion('2015')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('ISO/IEC')
                ->setMandatory(false)
                ->setScopeDescription('Cloud service providers and cloud customers implementing ISO 27002 with cloud guidance.')
                ->setActive(true);
            $this->em->persist($framework);
            $this->em->flush();
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

        $io?->success(sprintf('ISO/IEC 27017:2015: %d created, %d updated. Total: %d (7 CLD + %d 27002 refs).',
            $created, $updated, count($combined), count(self::REFERENCED_27002)));
        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }
}
