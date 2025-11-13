<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-c5-requirements',
    description: 'Load BSI C5:2020 (Cloud Computing Compliance Criteria Catalogue) requirements with ISMS data mappings'
)]
class LoadC5RequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get C5 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI-C5']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('BSI-C5')
                ->setName('BSI C5:2020 Cloud Computing Compliance Criteria Catalogue')
                ->setDescription('German Federal Office for Information Security (BSI) criteria catalogue for secure cloud computing')
                ->setVersion('2020')
                ->setApplicableIndustry('cloud_services')
                ->setRegulatoryBody('BSI - Bundesamt für Sicherheit in der Informationstechnik')
                ->setMandatory(false)
                ->setScopeDescription('Cloud service providers and cloud customers in Germany, mandatory for healthcare sector since July 2024 (DigiG)')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getC5Requirements();

        foreach ($requirements as $reqData) {
            $requirement = new ComplianceRequirement();
            $requirement->setFramework($framework)
                ->setRequirementId($reqData['id'])
                ->setTitle($reqData['title'])
                ->setDescription($reqData['description'])
                ->setCategory($reqData['category'])
                ->setPriority($reqData['priority'])
                ->setDataSourceMapping($reqData['data_source_mapping']);

            $this->entityManager->persist($requirement);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Successfully loaded %d BSI C5:2020 requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getC5Requirements(): array
    {
        return [
            // ORP - Organisation and Personnel
            [
                'id' => 'C5-ORP-1',
                'title' => 'Information Security Policies',
                'description' => 'The cloud service provider shall establish, document, and communicate information security policies.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'C5-ORP-2',
                'title' => 'Roles and Responsibilities',
                'description' => 'Security roles and responsibilities shall be defined and assigned throughout the organization.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2', '5.3'],
                ],
            ],
            [
                'id' => 'C5-ORP-3',
                'title' => 'Segregation of Duties',
                'description' => 'Conflicting duties and areas of responsibility shall be segregated to reduce opportunities for unauthorized modification or misuse.',
                'category' => 'Organisation and Personnel',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3', '8.2'],
                ],
            ],
            [
                'id' => 'C5-ORP-4',
                'title' => 'Management Responsibilities',
                'description' => 'Management shall provide clear direction and support for security initiatives.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                ],
            ],
            [
                'id' => 'C5-ORP-5',
                'title' => 'Contact with Authorities',
                'description' => 'Appropriate contacts with relevant authorities shall be maintained.',
                'category' => 'Organisation and Personnel',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.6'],
                ],
            ],
            [
                'id' => 'C5-ORP-6',
                'title' => 'Contact with Special Interest Groups',
                'description' => 'Appropriate contacts with special interest groups and security forums shall be maintained.',
                'category' => 'Organisation and Personnel',
                'priority' => 'low',
                'data_source_mapping' => [
                    'iso_controls' => ['5.6'],
                ],
            ],
            [
                'id' => 'C5-ORP-7',
                'title' => 'Independent Review of Information Security',
                'description' => 'The organization approach to managing information security shall be reviewed independently at planned intervals.',
                'category' => 'Organisation and Personnel',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-ORP-8',
                'title' => 'Project Management Security',
                'description' => 'Information security shall be integrated into project management methodologies.',
                'category' => 'Organisation and Personnel',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.8'],
                ],
            ],
            [
                'id' => 'C5-ORP-9',
                'title' => 'Information Security Continuity',
                'description' => 'Information security continuity shall be embedded in the organization business continuity management systems.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                ],
            ],

            // OIS - Organization of Information Security
            [
                'id' => 'C5-OIS-1',
                'title' => 'Internal Organization',
                'description' => 'An internal organizational framework for information security shall be established.',
                'category' => 'Organization of Information Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                ],
            ],
            [
                'id' => 'C5-OIS-2',
                'title' => 'Mobile Devices and Teleworking',
                'description' => 'A policy for the use of mobile devices and teleworking shall be adopted and implemented.',
                'category' => 'Organization of Information Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7', '8.13'],
                ],
            ],
            [
                'id' => 'C5-OIS-3',
                'title' => 'Information Security in Project Management',
                'description' => 'Information security shall be addressed in project management regardless of project type.',
                'category' => 'Organization of Information Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.8'],
                ],
            ],

            // CCC - Compliance and Legal Conformity
            [
                'id' => 'C5-CCC-1',
                'title' => 'Identification of Applicable Legislation',
                'description' => 'All relevant legislative, statutory, regulatory, and contractual requirements shall be identified and documented.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                ],
            ],
            [
                'id' => 'C5-CCC-2',
                'title' => 'Intellectual Property Rights',
                'description' => 'Appropriate procedures shall be implemented to ensure compliance with intellectual property rights.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.32'],
                ],
            ],
            [
                'id' => 'C5-CCC-3',
                'title' => 'Protection of Records',
                'description' => 'Records shall be protected from loss, destruction, falsification, unauthorized access, and unauthorized release.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.33'],
                ],
            ],
            [
                'id' => 'C5-CCC-4',
                'title' => 'Data Protection and Privacy',
                'description' => 'Data protection and privacy shall be ensured as per applicable requirements and regulations.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'C5-CCC-5',
                'title' => 'Cryptographic Controls Compliance',
                'description' => 'Cryptographic controls shall be used in compliance with relevant agreements, legislation, and regulations.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-CCC-6',
                'title' => 'Independent Review',
                'description' => 'Information security management shall be reviewed independently at planned intervals.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-CCC-7',
                'title' => 'Compliance with Security Policies',
                'description' => 'Managers shall regularly review compliance with security policies and standards.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                ],
            ],
            [
                'id' => 'C5-CCC-8',
                'title' => 'Technical Compliance Review',
                'description' => 'Information systems shall be regularly reviewed for compliance with security policies and standards.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36', '8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-CCC-9',
                'title' => 'Regulatory Reporting',
                'description' => 'The cloud service provider shall support customers in meeting regulatory reporting obligations.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31', '5.36'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-CCC-10',
                'title' => 'Evidence Collection for Compliance',
                'description' => 'Mechanisms shall be established to collect and retain evidence for compliance audits.',
                'category' => 'Compliance and Legal Conformity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'audit_evidence' => true,
                ],
            ],

            // IAM - Identity and Access Management
            [
                'id' => 'C5-IAM-1',
                'title' => 'User Registration and Deregistration',
                'description' => 'A formal user registration and de-registration process shall be implemented.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16'],
                ],
            ],
            [
                'id' => 'C5-IAM-2',
                'title' => 'User Access Provisioning',
                'description' => 'User access provisioning shall be controlled through a formal process.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'C5-IAM-3',
                'title' => 'Management of Privileged Access Rights',
                'description' => 'The allocation and use of privileged access rights shall be restricted and controlled.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2'],
                ],
            ],
            [
                'id' => 'C5-IAM-4',
                'title' => 'Management of Secret Authentication Information',
                'description' => 'The allocation of secret authentication information shall be controlled through a formal management process.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'C5-IAM-5',
                'title' => 'Review of User Access Rights',
                'description' => 'Asset owners shall review users access rights at regular intervals.',
                'category' => 'Identity and Access Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-IAM-6',
                'title' => 'Removal of Access Rights',
                'description' => 'Access rights of all employees and external users shall be removed upon termination.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16'],
                ],
            ],
            [
                'id' => 'C5-IAM-7',
                'title' => 'User Responsibilities',
                'description' => 'Users shall be required to follow security practices in the use of secret authentication information.',
                'category' => 'Identity and Access Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17'],
                ],
            ],
            [
                'id' => 'C5-IAM-8',
                'title' => 'Access Control Policy',
                'description' => 'An access control policy shall be established, documented, and reviewed.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15'],
                ],
            ],
            [
                'id' => 'C5-IAM-9',
                'title' => 'Secure Log-on Procedures',
                'description' => 'Access to systems and applications shall be controlled by a secure log-on procedure.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.5'],
                ],
            ],
            [
                'id' => 'C5-IAM-10',
                'title' => 'Password Management System',
                'description' => 'Password management systems shall be interactive and ensure quality passwords.',
                'category' => 'Identity and Access Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                ],
            ],
            [
                'id' => 'C5-IAM-11',
                'title' => 'Multi-Factor Authentication',
                'description' => 'Multi-factor authentication shall be implemented for privileged and remote access.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18', '8.5'],
                ],
            ],
            [
                'id' => 'C5-IAM-12',
                'title' => 'Access Control Policy',
                'description' => 'An access control policy shall be established, documented and reviewed based on business and security requirements.',
                'category' => 'Identity and Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15'],
                ],
            ],

            // HRS - Human Resources Security
            [
                'id' => 'C5-HRS-1',
                'title' => 'Screening',
                'description' => 'Background verification checks on all candidates for employment shall be carried out.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'C5-HRS-2',
                'title' => 'Terms and Conditions of Employment',
                'description' => 'Contractual agreements shall state employees and contractors responsibilities for information security.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'C5-HRS-3',
                'title' => 'Information Security Awareness and Training',
                'description' => 'All employees and contractors shall receive appropriate security awareness education and training.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'C5-HRS-4',
                'title' => 'Disciplinary Process',
                'description' => 'A formal disciplinary process shall be in place for employees who commit security breaches.',
                'category' => 'Human Resources Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['6.4'],
                ],
            ],
            [
                'id' => 'C5-HRS-5',
                'title' => 'Termination Responsibilities',
                'description' => 'Information security responsibilities and duties that remain valid after termination shall be defined and communicated.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.5'],
                ],
            ],
            [
                'id' => 'C5-HRS-6',
                'title' => 'Confidentiality Agreements',
                'description' => 'Confidentiality or non-disclosure agreements shall be signed by all relevant parties.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.6'],
                ],
            ],
            [
                'id' => 'C5-HRS-7',
                'title' => 'Remote Working Security',
                'description' => 'Security measures shall be implemented when personnel work remotely to protect information accessed, processed or stored.',
                'category' => 'Human Resources Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],

            // PHY - Physical Security
            [
                'id' => 'C5-PHY-1',
                'title' => 'Physical Security Perimeter',
                'description' => 'Security perimeters shall be defined and used to protect areas with information processing facilities.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                ],
            ],
            [
                'id' => 'C5-PHY-2',
                'title' => 'Physical Entry Controls',
                'description' => 'Secure areas shall be protected by appropriate entry controls.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'C5-PHY-3',
                'title' => 'Securing Offices and Facilities',
                'description' => 'Physical security for offices, rooms, and facilities shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.3'],
                ],
            ],
            [
                'id' => 'C5-PHY-4',
                'title' => 'Protection Against Physical Threats',
                'description' => 'Physical protection against natural disasters, malicious attack, or accidents shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],
            [
                'id' => 'C5-PHY-5',
                'title' => 'Working in Secure Areas',
                'description' => 'Procedures for working in secure areas shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.3'],
                ],
            ],
            [
                'id' => 'C5-PHY-6',
                'title' => 'Equipment Siting and Protection',
                'description' => 'Equipment shall be sited and protected to reduce risks from environmental threats.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],
            [
                'id' => 'C5-PHY-7',
                'title' => 'Supporting Utilities',
                'description' => 'Equipment shall be protected from power failures and other disruptions.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9'],
                ],
            ],
            [
                'id' => 'C5-PHY-8',
                'title' => 'Cabling Security',
                'description' => 'Power and telecommunications cabling shall be protected from interception, interference, or damage.',
                'category' => 'Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.10'],
                ],
            ],
            [
                'id' => 'C5-PHY-9',
                'title' => 'Equipment Maintenance',
                'description' => 'Equipment shall be correctly maintained to ensure availability, integrity, and confidentiality.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.11'],
                ],
            ],
            [
                'id' => 'C5-PHY-10',
                'title' => 'Secure Disposal of Equipment',
                'description' => 'Equipment, information or software shall be securely disposed of when no longer required.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.14', '8.10'],
                ],
            ],

            // ENC - Encryption and Key Management
            [
                'id' => 'C5-ENC-1',
                'title' => 'Policy on Cryptographic Controls',
                'description' => 'A policy on the use of cryptographic controls shall be developed and implemented.',
                'category' => 'Encryption and Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-ENC-2',
                'title' => 'Encryption of Data at Rest',
                'description' => 'Cryptographic controls shall be used to protect the confidentiality of stored information.',
                'category' => 'Encryption and Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-ENC-3',
                'title' => 'Encryption of Data in Transit',
                'description' => 'Information involved in communications shall be protected by appropriate cryptographic controls.',
                'category' => 'Encryption and Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-ENC-4',
                'title' => 'Encryption of Data in Use',
                'description' => 'Cryptographic controls shall be considered for protecting data during processing where technically feasible.',
                'category' => 'Encryption and Key Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-ENC-5',
                'title' => 'Digital Signatures',
                'description' => 'Digital signatures shall be used to protect the authenticity and integrity of information where appropriate.',
                'category' => 'Encryption and Key Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // SKM - Security Key Management
            [
                'id' => 'C5-SKM-1',
                'title' => 'Key Management Policy',
                'description' => 'A policy on the use, protection and lifetime of cryptographic keys shall be developed and implemented.',
                'category' => 'Security Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-2',
                'title' => 'Key Generation',
                'description' => 'Cryptographic keys shall be generated in a secure manner using approved algorithms and key lengths.',
                'category' => 'Security Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-3',
                'title' => 'Key Storage and Protection',
                'description' => 'Cryptographic keys shall be protected against modification, loss, and destruction.',
                'category' => 'Security Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-4',
                'title' => 'Key Distribution',
                'description' => 'Cryptographic keys shall be distributed securely to intended recipients.',
                'category' => 'Security Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-5',
                'title' => 'Key Rotation',
                'description' => 'Cryptographic keys shall be changed periodically and when compromised.',
                'category' => 'Security Key Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-6',
                'title' => 'Key Destruction',
                'description' => 'Cryptographic keys shall be securely destroyed when no longer needed.',
                'category' => 'Security Key Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'C5-SKM-7',
                'title' => 'Key Backup and Recovery',
                'description' => 'Cryptographic keys shall be backed up and recovery procedures shall be established to prevent data loss.',
                'category' => 'Security Key Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13', '8.24'],
                ],
            ],

            // IDS - Identity and Subscriber Management
            [
                'id' => 'C5-IDS-1',
                'title' => 'Customer Identity Management',
                'description' => 'The cloud service provider shall ensure appropriate identity management for customers.',
                'category' => 'Identity and Subscriber Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.18'],
                ],
            ],
            [
                'id' => 'C5-IDS-2',
                'title' => 'Customer Authentication',
                'description' => 'The cloud service provider shall ensure strong authentication mechanisms for customers.',
                'category' => 'Identity and Subscriber Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'C5-IDS-3',
                'title' => 'Federation and Credential Management',
                'description' => 'The cloud service provider shall support identity federation where appropriate.',
                'category' => 'Identity and Subscriber Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'C5-IDS-4',
                'title' => 'Customer Access Auditing',
                'description' => 'Customer access events shall be logged and made available for audit purposes.',
                'category' => 'Identity and Subscriber Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'audit_evidence' => true,
                ],
            ],

            // OPS - Operations Security
            [
                'id' => 'C5-OPS-1',
                'title' => 'Documented Operating Procedures',
                'description' => 'Operating procedures shall be documented and made available to users.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'C5-OPS-2',
                'title' => 'Change Management',
                'description' => 'Changes to systems, applications, and services shall be controlled.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'C5-OPS-3',
                'title' => 'Capacity Management',
                'description' => 'The use of resources shall be monitored and projections of future capacity requirements made.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6'],
                ],
            ],
            [
                'id' => 'C5-OPS-4',
                'title' => 'Separation of Environments',
                'description' => 'Development, testing, and operational environments shall be separated.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.31'],
                ],
            ],
            [
                'id' => 'C5-OPS-5',
                'title' => 'Protection from Malware',
                'description' => 'Detection, prevention and recovery controls shall be implemented to protect against malware.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'C5-OPS-6',
                'title' => 'Information Backup',
                'description' => 'Backup copies of information and software shall be maintained and regularly tested.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                ],
            ],
            [
                'id' => 'C5-OPS-7',
                'title' => 'Event Logging',
                'description' => 'Event logs recording user activities and security events shall be produced and retained.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'C5-OPS-8',
                'title' => 'Protection of Log Information',
                'description' => 'Logging facilities and log information shall be protected against tampering and unauthorized access.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'C5-OPS-9',
                'title' => 'Clock Synchronization',
                'description' => 'The clocks of all information processing systems shall be synchronized to an accurate time source.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'C5-OPS-10',
                'title' => 'Installation of Software on Operational Systems',
                'description' => 'Procedures shall control the installation of software on operational systems.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19'],
                ],
            ],
            [
                'id' => 'C5-OPS-11',
                'title' => 'Technical Vulnerability Management',
                'description' => 'Information about technical vulnerabilities shall be obtained and managed.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],

            // DSI - Data Security and Isolation
            [
                'id' => 'C5-DSI-1',
                'title' => 'Multi-Tenancy and Segregation',
                'description' => 'Customer data shall be logically segregated in multi-tenant environments.',
                'category' => 'Data Security and Isolation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'asset_types' => ['data', 'cloud'],
                ],
            ],
            [
                'id' => 'C5-DSI-2',
                'title' => 'Data Classification',
                'description' => 'Information shall be classified in terms of legal requirements and criticality.',
                'category' => 'Data Security and Isolation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-DSI-3',
                'title' => 'Data Labeling',
                'description' => 'An appropriate set of procedures for information labeling shall be developed.',
                'category' => 'Data Security and Isolation',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.13'],
                ],
            ],
            [
                'id' => 'C5-DSI-4',
                'title' => 'Data Retention and Deletion',
                'description' => 'Customer data shall be retained and deleted according to contractual agreements and legal requirements.',
                'category' => 'Data Security and Isolation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'C5-DSI-5',
                'title' => 'Secure Data Sanitization',
                'description' => 'Storage media shall be verified to ensure data is irrecoverably deleted before disposal or reuse.',
                'category' => 'Data Security and Isolation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'C5-DSI-6',
                'title' => 'Data Portability',
                'description' => 'The cloud service provider shall enable customers to extract their data in a structured format.',
                'category' => 'Data Security and Isolation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-DSI-7',
                'title' => 'Data Sovereignty',
                'description' => 'Data sovereignty requirements shall be clearly defined and contractually agreed with customers.',
                'category' => 'Data Security and Isolation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],

            // SEA - Security Incident Management
            [
                'id' => 'C5-SEA-1',
                'title' => 'Incident Management Responsibilities',
                'description' => 'Management responsibilities and procedures shall be established for incident management.',
                'category' => 'Security Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'C5-SEA-2',
                'title' => 'Reporting Security Events',
                'description' => 'Security events shall be reported through appropriate channels as quickly as possible.',
                'category' => 'Security Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'C5-SEA-3',
                'title' => 'Reporting Security Weaknesses',
                'description' => 'Users shall be required to note and report any observed or suspected security weaknesses.',
                'category' => 'Security Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.25'],
                ],
            ],
            [
                'id' => 'C5-SEA-4',
                'title' => 'Assessment and Decision on Security Events',
                'description' => 'Security events shall be assessed and classified as security incidents.',
                'category' => 'Security Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'C5-SEA-5',
                'title' => 'Response to Security Incidents',
                'description' => 'Security incidents shall be responded to in accordance with documented procedures.',
                'category' => 'Security Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'C5-SEA-6',
                'title' => 'Learning from Security Incidents',
                'description' => 'Knowledge gained from security incidents shall be used to strengthen security.',
                'category' => 'Security Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.27'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'C5-SEA-7',
                'title' => 'Collection of Evidence',
                'description' => 'Procedures for the identification, collection, and preservation of evidence shall be defined.',
                'category' => 'Security Incident Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                ],
            ],
            [
                'id' => 'C5-SEA-8',
                'title' => 'Customer Incident Notification',
                'description' => 'Customers shall be promptly notified of security incidents that affect their data or services.',
                'category' => 'Security Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // BCM - Business Continuity Management
            [
                'id' => 'C5-BCM-1',
                'title' => 'Business Continuity Policy',
                'description' => 'A business continuity policy shall be established and maintained.',
                'category' => 'Business Continuity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'C5-BCM-2',
                'title' => 'Business Impact Analysis',
                'description' => 'Events that can cause interruptions shall be identified along with their impact.',
                'category' => 'Business Continuity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'C5-BCM-3',
                'title' => 'Business Continuity Plans',
                'description' => 'Plans shall be developed to maintain or restore operations in the required time scales.',
                'category' => 'Business Continuity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'C5-BCM-4',
                'title' => 'Testing Business Continuity Plans',
                'description' => 'Business continuity plans shall be tested and updated regularly.',
                'category' => 'Business Continuity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-BCM-5',
                'title' => 'ICT Redundancy',
                'description' => 'ICT facilities shall be implemented with sufficient redundancy to meet availability requirements.',
                'category' => 'Business Continuity Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6', '8.14'],
                ],
            ],
            [
                'id' => 'C5-BCM-6',
                'title' => 'Disaster Recovery Planning',
                'description' => 'Disaster recovery procedures shall be established and tested to ensure timely recovery.',
                'category' => 'Business Continuity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],

            // LOM - Logging and Monitoring
            [
                'id' => 'C5-LOM-1',
                'title' => 'Monitoring Strategy',
                'description' => 'A monitoring strategy shall be established to detect abnormal behavior and security incidents.',
                'category' => 'Logging and Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'C5-LOM-2',
                'title' => 'Log Review',
                'description' => 'Administrator and operator logs shall be regularly reviewed.',
                'category' => 'Logging and Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-LOM-3',
                'title' => 'Alerting and Correlation',
                'description' => 'Security events shall be correlated and analyzed to detect incidents.',
                'category' => 'Logging and Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],
            [
                'id' => 'C5-LOM-4',
                'title' => 'Real-Time Monitoring',
                'description' => 'Critical systems and infrastructure shall be monitored in real-time for security events.',
                'category' => 'Logging and Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],

            // THR - Threat and Vulnerability Management
            [
                'id' => 'C5-THR-1',
                'title' => 'Threat Intelligence',
                'description' => 'The cloud service provider shall obtain and analyze threat intelligence.',
                'category' => 'Threat and Vulnerability Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.8'],
                ],
            ],
            [
                'id' => 'C5-THR-2',
                'title' => 'Vulnerability Scanning',
                'description' => 'Regular vulnerability scanning shall be performed on systems and applications.',
                'category' => 'Threat and Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-THR-3',
                'title' => 'Penetration Testing',
                'description' => 'Regular penetration testing shall be conducted to validate security controls.',
                'category' => 'Threat and Vulnerability Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'C5-THR-4',
                'title' => 'Patch Management',
                'description' => 'Security patches shall be applied in a timely manner after appropriate testing.',
                'category' => 'Threat and Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'C5-THR-5',
                'title' => 'Threat Modeling',
                'description' => 'Threat modeling shall be conducted for critical systems and applications.',
                'category' => 'Threat and Vulnerability Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.25'],
                ],
            ],

            // SUP - Supply Chain Security
            [
                'id' => 'C5-SUP-1',
                'title' => 'Supply Chain Risk Assessment',
                'description' => 'Risks related to supply chain and suppliers shall be identified and assessed.',
                'category' => 'Supply Chain Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'C5-SUP-2',
                'title' => 'Supplier Agreements',
                'description' => 'Security requirements shall be included in agreements with suppliers.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.21'],
                ],
            ],
            [
                'id' => 'C5-SUP-3',
                'title' => 'Supplier Security Monitoring',
                'description' => 'The security practices of suppliers shall be monitored regularly.',
                'category' => 'Supply Chain Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                ],
            ],
            [
                'id' => 'C5-SUP-4',
                'title' => 'Management of Changes to Supplier Services',
                'description' => 'Changes to supplier services shall be managed and controlled.',
                'category' => 'Supply Chain Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                ],
            ],
            [
                'id' => 'C5-SUP-5',
                'title' => 'Subcontractor Management',
                'description' => 'Use of subcontractors shall be controlled and monitored to ensure security requirements are met.',
                'category' => 'Supply Chain Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.21'],
                ],
            ],
            [
                'id' => 'C5-SUP-6',
                'title' => 'Supply Chain Transparency',
                'description' => 'The cloud service provider shall provide transparency about the supply chain to customers.',
                'category' => 'Supply Chain Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                ],
            ],

            // Additional C5-Specific Cloud Controls
            [
                'id' => 'C5-CLD-1',
                'title' => 'Cloud Service Customer Configuration',
                'description' => 'The cloud service provider shall offer secure default configurations and configuration guidance.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-CLD-2',
                'title' => 'Cloud Service Transparency',
                'description' => 'The cloud service provider shall provide transparency about security measures and compliance.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.31'],
                ],
            ],
            [
                'id' => 'C5-CLD-3',
                'title' => 'Data Location and Transfer',
                'description' => 'The cloud service provider shall disclose data storage and processing locations.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['data', 'cloud'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'C5-CLD-4',
                'title' => 'Cloud Service Exit Strategy',
                'description' => 'The cloud service provider shall support customers in transitioning to alternative providers.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-CLD-5',
                'title' => 'API Security',
                'description' => 'APIs for cloud service management and access shall be secured appropriately.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => 'C5-CLD-6',
                'title' => 'Shared Responsibility Model',
                'description' => 'The cloud service provider shall clearly document security responsibilities in the shared responsibility model.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-CLD-7',
                'title' => 'Cloud Service Level Agreements',
                'description' => 'SLAs shall clearly define availability, performance, and security commitments with measurable metrics.',
                'category' => 'Cloud-Specific Controls',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.21'],
                    'asset_types' => ['cloud'],
                ],
            ],
        ];
    }
}
