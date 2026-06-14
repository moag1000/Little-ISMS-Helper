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

/**
 * Loads the full ENISA EUCS (European Cybersecurity Certification Scheme for
 * Cloud Services) control catalogue — 120 controls across 20 categories.
 *
 * Only control IDs + short titles are shipped (no copyrighted ENISA prose).
 * Source: ENISA EUCS candidate scheme public consultation drafts 2020-2024.
 * Licensing: control IDs and titles are referenced under public-consultation
 * terms; long requirement prose is intentionally omitted.
 *
 * requirementId = bare control ID (e.g. OIS-01, AM-01, BC-01 …).
 * Category = full A.NN section name from the ENISA PDF.
 */
#[AsCommand(
    name: 'app:load-eucs-full',
    description: 'Load full ENISA EUCS control catalogue (120 controls, 20 categories) as ComplianceRequirement rows.',
)]
final class LoadEucsFullCommand extends Command implements FrameworkLoaderInterface
{
    /**
     * Category prefix → full name (A.NN section headers from the ENISA EUCS PDF).
     *
     * A.1  Organisation of Information Security → OIS
     * A.2  Information Security Policies        → ISP
     * A.3  Risk Management                      → RM
     * A.4  Human Resources                      → HR
     * A.5  Asset Management                     → AM
     * A.6  Physical Security                    → PS
     * A.7  Operational Security                 → OPS
     * A.8  Identity, Authentication, and Access Control Management → IAM
     * A.9  Cryptography and Key Management      → CKM
     * A.10 Communication Security               → CS
     * A.11 Portability and Interoperability     → PI
     * A.12 Change and Configuration Management  → CCM
     * A.13 Development of Information Systems   → DEV
     * A.14 Procurement Management               → PM
     * A.15 Incident Management                  → IM
     * A.16 Business Continuity                  → BC
     * A.17 Compliance                           → CO
     * A.18 User Documentation                   → DOC
     * A.19 Dealing with Investigation Requests from Government → INQ
     * A.20 Product Safety and Security (PSS)    → PSS
     *
     * @var array<string,string>
     */
    private const CATEGORY_NAMES = [
        'OIS' => 'A.1 — Organisation of Information Security',
        'ISP' => 'A.2 — Information Security Policies',
        'RM'  => 'A.3 — Risk Management',
        'HR'  => 'A.4 — Human Resources',
        'AM'  => 'A.5 — Asset Management',
        'PS'  => 'A.6 — Physical Security',
        'OPS' => 'A.7 — Operational Security',
        'IAM' => 'A.8 — Identity, Authentication, and Access Control Management',
        'CKM' => 'A.9 — Cryptography and Key Management',
        'CS'  => 'A.10 — Communication Security',
        'PI'  => 'A.11 — Portability and Interoperability',
        'CCM' => 'A.12 — Change and Configuration Management',
        'DEV' => 'A.13 — Development of Information Systems',
        'PM'  => 'A.14 — Procurement Management',
        'IM'  => 'A.15 — Incident Management',
        'BC'  => 'A.16 — Business Continuity',
        'CO'  => 'A.17 — Compliance',
        'DOC' => 'A.18 — User Documentation',
        'INQ' => 'A.19 — Dealing with Investigation Requests from Government',
        'PSS' => 'A.20 — Product Safety and Security (PSS)',
    ];

    /**
     * Full EUCS control catalogue — bare IDs + short titles.
     * Source: ENISA EUCS candidate scheme public consultation drafts 2020-2024.
     * Only IDs + titles shipped; no copyrighted requirement prose included.
     *
     * @var array<string,string>
     */
    private const CONTROLS = [
        // A.5 — Asset Management
        'AM-01' => 'Asset Inventory',
        'AM-02' => 'Acceptable Use and Safe Handling of Assets Policy',
        'AM-03' => 'Commissioning and Decommissioning of Hardware',
        'AM-04' => 'Acceptable Use, Safe Handling and Return of Assets',
        'AM-05' => 'Asset Classification and Labelling',

        // A.16 — Business Continuity
        'BC-01' => 'Business Continuity Policies and Top Management Responsibility',
        'BC-02' => 'Business Impact Analysis Procedures',
        'BC-03' => 'Business Continuity and Contingency Planning',
        'BC-04' => 'Business Continuity Tests and Exercises',

        // A.12 — Change and Configuration Management
        'CCM-01' => 'Policies for Changes to Information Systems',
        'CCM-02' => 'Risk Assessment, Categorisation and Prioritisation of Changes',
        'CCM-03' => 'Testing Changes',
        'CCM-04' => 'Approvals for Provision in the Production Environment',
        'CCM-05' => 'Performing and Logging Changes',
        'CCM-06' => 'Version Control',

        // A.9 — Cryptography and Key Management
        'CKM-01' => 'Policies for the Use of Encryption Mechanisms and Key Management',
        'CKM-02' => 'Encryption of Data in Transit',
        'CKM-03' => 'Encryption of Data at Rest',
        'CKM-04' => 'Secure Key Management',

        // A.17 — Compliance
        'CO-01' => 'Identification of Applicable Compliance Requirements',
        'CO-02' => 'Policy for Planning and Conducting Audits',
        'CO-03' => 'Internal Audits of the Internal Control System',
        'CO-04' => 'Information on Internal Control System Assessment',

        // A.10 — Communication Security
        'CS-01' => 'Technical Safeguards',
        'CS-02' => 'Security Requirements to Connect within the CSP\'s Network',
        'CS-03' => 'Monitoring of Connections within the CSP\'s Network',
        'CS-04' => 'Cross-Network Access',
        'CS-05' => 'Networks for Administration',
        'CS-06' => 'Traffic Segregation in Shared Network Environments',
        'CS-07' => 'Network Topology Documentation',
        'CS-08' => 'Software Defined Networking',
        'CS-09' => 'Data Transmission Policies',

        // A.13 — Development of Information Systems
        'DEV-01' => 'Policies for the Development and Procurement of Information Systems',
        'DEV-02' => 'Development Supply Chain Security',
        'DEV-03' => 'Secure Development Environment',
        'DEV-04' => 'Separation of Environments',
        'DEV-05' => 'Development of Security Features',
        'DEV-06' => 'Identification of Vulnerabilities of the Cloud Service',
        'DEV-07' => 'Outsourcing of the Development',

        // A.18 — User Documentation
        'DOC-01' => 'Guidelines and Recommendations for Cloud Customers',
        'DOC-02' => 'Online Register of Known Vulnerabilities',
        'DOC-03' => 'Locations of Data Processing and Storage',
        'DOC-04' => 'Justification of the Targeted Assurance Level',
        'DOC-05' => 'Guidelines and Recommendations for Composition',
        'DOC-06' => 'Contribution to the Fulfilment of Requirements for Composition',

        // A.4 — Human Resources
        'HR-01' => 'Human Resource Policies',
        'HR-02' => 'Verification of Qualification and Trustworthiness',
        'HR-03' => 'Employee Terms and Conditions',
        'HR-04' => 'Security Awareness and Training',
        'HR-05' => 'Termination or Change in Employment',
        'HR-06' => 'Confidentiality Agreements',

        // A.8 — Identity, Authentication, and Access Control Management
        'IAM-01' => 'Policies for Access Control to Information',
        'IAM-02' => 'Management of User Accounts',
        'IAM-03' => 'Locking, Unlocking and Revocation of User Accounts',
        'IAM-04' => 'Management of Access Rights',
        'IAM-05' => 'Regular Review of Access Rights',
        'IAM-06' => 'Privileged Access Rights',
        'IAM-07' => 'Authentication Mechanisms',
        'IAM-08' => 'Protection and Strength of Credentials',
        'IAM-09' => 'General Access Restrictions',

        // A.15 — Incident Management
        'IM-01' => 'Policy for Security Incident Management',
        'IM-02' => 'Processing of Security Incidents',
        'IM-03' => 'Documentation and Reporting of Security Incidents',
        'IM-04' => 'User\'s Duty to Report Security Incidents',
        'IM-05' => 'Involvement of Cloud Customers in the Event of Incidents',
        'IM-06' => 'Evaluation and Learning Process',
        'IM-07' => 'Incident Evidence Preservation',

        // A.19 — Dealing with Investigation Requests from Government
        'INQ-01' => 'Legal Assessment of Investigative Inquiries',
        'INQ-02' => 'Informing Cloud Customers about Investigation Requests',
        'INQ-03' => 'Conditions for Access to or Disclosure of Data in Investigations',

        // A.2 — Information Security Policies
        'ISP-01' => 'Global Information Security Policy',
        'ISP-02' => 'Security Policies and Procedures',
        'ISP-03' => 'Exceptions',

        // A.1 — Organisation of Information Security
        'OIS-01' => 'Information Security Management System',
        'OIS-02' => 'Segregation of Duties',
        'OIS-03' => 'Contact with Authorities and Interest Groups',
        'OIS-04' => 'Information Security in Project Management',

        // A.7 — Operational Security
        'OPS-01' => 'Capacity Management — Planning',
        'OPS-02' => 'Capacity Management — Monitoring',
        'OPS-03' => 'Capacity Management — Controlling of Resources',
        'OPS-04' => 'Protection against Malware — Policies',
        'OPS-05' => 'Protection against Malware — Implementation',
        'OPS-06' => 'Data Backup and Recovery — Policies',
        'OPS-07' => 'Data Backup and Recovery — Monitoring',
        'OPS-08' => 'Data Backup and Recovery — Regular Testing',
        'OPS-09' => 'Data Backup and Recovery — Storage',
        'OPS-10' => 'Logging and Monitoring — Policies',
        'OPS-11' => 'Logging and Monitoring — Derived Data Management',
        'OPS-12' => 'Logging and Monitoring — Identification of Events',
        'OPS-13' => 'Logging and Monitoring — Access, Storage and Deletion',
        'OPS-14' => 'Logging and Monitoring — Attribution',
        'OPS-15' => 'Logging and Monitoring — Configuration',
        'OPS-16' => 'Logging and Monitoring — Availability',
        'OPS-17' => 'Managing Vulnerabilities, Malfunctions and Errors — Policies',
        'OPS-18' => 'Managing Vulnerabilities, Malfunctions and Errors — Online Register',
        'OPS-19' => 'Managing Vulnerabilities, Malfunctions and Errors — Patch Management',
        'OPS-20' => 'Managing Vulnerabilities, Malfunctions and Errors — Remediation',
        'OPS-21' => 'Managing Vulnerabilities, Malfunctions and Errors — System Hardening',
        'OPS-22' => 'Separation of Datasets in the Cloud Infrastructure',

        // A.11 — Portability and Interoperability
        'PI-01' => 'Documentation and Security of Input and Output Interfaces',
        'PI-02' => 'Contractual Agreements for the Provision of Data',
        'PI-03' => 'Secure Deletion of Data',

        // A.14 — Procurement Management
        'PM-01' => 'Policies and Procedures for Controlling and Monitoring Third Parties',
        'PM-02' => 'Risk Assessment of Suppliers',
        'PM-03' => 'Directory of Suppliers',
        'PM-04' => 'Monitoring of Compliance with Requirements',
        'PM-05' => 'Exit Strategy',

        // A.6 — Physical Security
        'PS-01' => 'Physical Security Perimeters',
        'PS-02' => 'Physical Site Access Control',
        'PS-03' => 'Working in Non-Public Areas',
        'PS-04' => 'Equipment Protection',
        'PS-05' => 'Protection against External and Environmental Threats',

        // A.20 — Product Safety and Security (PSS)
        'PSS-01' => 'Error Handling and Logging Mechanisms',
        'PSS-02' => 'Session Management',
        'PSS-03' => 'Software Defined Networking',
        'PSS-04' => 'Images for Virtual Machines and Containers',
        'PSS-05' => 'Locations of Data Processing and Storage',

        // A.3 — Risk Management
        'RM-01' => 'Risk Management Policy',
        'RM-02' => 'Risk Assessment Implementation',
        'RM-03' => 'Risk Treatment Implementation',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'EUCS';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        // Create or find the EUCS framework (idempotent)
        $framework = $this->frameworkRepository->findOneBy(['code' => 'EUCS']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }

        $framework->setCode('EUCS')
            ->setName('EUCS — European Cybersecurity Certification Scheme for Cloud Services (ENISA)')
            ->setDescription(
                'ENISA European Cybersecurity Certification Scheme for Cloud Services (EUCS) — ' .
                'candidate scheme public consultation draft. 120 controls across 20 categories ' .
                '(A.1 Organisation of Information Security … A.20 Product Safety and Security). ' .
                'Only control IDs + titles shipped; copyrighted ENISA requirement prose excluded.'
            )
            ->setVersion('Candidate scheme (2020 draft)')
            ->setApplicableIndustry('cloud_services')
            ->setRegulatoryBody('ENISA')
            ->setMandatory(false)
            ->setScopeDescription('Cloud service providers (CSPs) seeking EUCS certification at Basic, Substantial, or High assurance level.')
            ->setActive(true);

        if ($isNew) {
            $this->em->persist($framework);
            $this->em->flush();
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0;
        $skipped = 0;

        foreach (self::CONTROLS as $controlId => $title) {
            // Derive category prefix (everything before the first '-')
            $prefix = (string) explode('-', $controlId)[0];
            $category = self::CATEGORY_NAMES[$prefix] ?? $prefix;

            $existing = $reqRepo->findOneBy([
                'framework'     => $framework,
                'requirementId' => $controlId,
            ]);

            if ($existing !== null) {
                if ($update) {
                    $existing->setTitle(mb_substr($title, 0, 250))
                        ->setCategory($category)
                        ->setPriority('high');
                }
                $skipped++;
                continue;
            }

            $req = new ComplianceRequirement();
            $req->setFramework($framework)
                ->setRequirementId($controlId)
                ->setTitle(mb_substr($title, 0, 250))
                ->setDescription(sprintf(
                    'EUCS control %s — %s. ' .
                    'Source: ENISA European Cybersecurity Certification Scheme for Cloud Services, ' .
                    'candidate scheme (public consultation draft 2020-2024). ' .
                    'Category: %s.',
                    $controlId,
                    $title,
                    $category
                ))
                ->setCategory($category)
                ->setPriority('high')
                ->setRequirementType('core');

            $this->em->persist($req);
            $created++;
        }

        $this->em->flush();

        $io?->success(sprintf(
            'EUCS: %d created, %d skipped (already existed). Total controls: %d across %d categories.',
            $created,
            $skipped,
            count(self::CONTROLS),
            count(self::CATEGORY_NAMES)
        ));

        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');

        return $this->loadRequirements($update, $io);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', null, InputOption::VALUE_NONE, 'Update existing requirement rows in place');
    }
}
