<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-tisax-requirements',
    description: 'Load TISAX (VDA ISA) requirements with ISO 27001 control mappings'
)]
class LoadTisaxRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Create or get TISAX framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'TISAX']);
        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('TISAX')
                ->setName('TISAX (Trusted Information Security Assessment Exchange)')
                ->setDescription('Information security assessment standard for the automotive industry based on VDA ISA')
                ->setVersion('6.0.2')
                ->setApplicableIndustry('automotive')
                ->setRegulatoryBody('VDA (Verband der Automobilindustrie)')
                ->setMandatory(false)
                ->setScopeDescription('Comprehensive information security assessment for automotive supply chain')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }
        $requirements = $this->getTisaxRequirements();
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
        $symfonyStyle->success(sprintf('Successfully loaded %d TISAX requirements', count($requirements)));
        return Command::SUCCESS;
    }

    private function getTisaxRequirements(): array
    {
        return [
            // 1. Information Security (INF)
            [
                'id' => 'INF-1.1',
                'title' => 'Information Security Policy',
                'description' => 'An information security policy shall be defined, documented, approved by management, published and communicated to employees and relevant external parties.',
                'category' => 'Information Security Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-1.2',
                'title' => 'Review of Information Security Policy',
                'description' => 'The information security policy shall be reviewed at planned intervals or if significant changes occur to ensure its continuing suitability, adequacy and effectiveness.',
                'category' => 'Information Security Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-2.1',
                'title' => 'Management Responsibility for Information Security',
                'description' => 'Management shall actively support security within the organization through clear direction, demonstrated commitment, explicit assignment and acknowledgment of information security responsibilities.',
                'category' => 'Information Security Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-3.1',
                'title' => 'Inventory of Assets',
                'description' => 'Assets associated with information and information processing facilities shall be identified and an inventory of these assets shall be drawn up and maintained.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services'],
                ],
            ],
            [
                'id' => 'INF-3.2',
                'title' => 'Ownership of Assets',
                'description' => 'Assets maintained in the inventory shall be owned.',
                'category' => 'Asset Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services'],
                ],
            ],
            [
                'id' => 'INF-3.3',
                'title' => 'Acceptable Use of Assets',
                'description' => 'Rules for the acceptable use of information and of assets associated with information and information processing facilities shall be identified, documented and implemented.',
                'category' => 'Asset Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.10'],
                    'asset_types' => ['hardware', 'software', 'data'],
                ],
            ],
            [
                'id' => 'INF-3.4',
                'title' => 'Return of Assets',
                'description' => 'All employees and external party users shall return all of the organizational assets in their possession upon termination of their employment, contract or agreement.',
                'category' => 'Asset Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.10'],
                ],
            ],
            [
                'id' => 'INF-4.1',
                'title' => 'Classification Guidelines',
                'description' => 'Information shall be classified in terms of legal requirements, value, criticality and sensitivity to unauthorised disclosure or modification.',
                'category' => 'Information Classification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'INF-4.2',
                'title' => 'Labelling of Information',
                'description' => 'An appropriate set of procedures for information labelling shall be developed and implemented in accordance with the information classification scheme adopted by the organization.',
                'category' => 'Information Classification',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.13'],
                ],
            ],

            // 2. Access Control (ACC)
            [
                'id' => 'ACC-1.1',
                'title' => 'Access Control Policy',
                'description' => 'An access control policy shall be established, documented and reviewed based on business and information security requirements.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15'],
                ],
            ],
            [
                'id' => 'ACC-2.1',
                'title' => 'User Registration and De-registration',
                'description' => 'A formal user registration and de-registration process shall be implemented to enable assignment of access rights.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'ACC-2.2',
                'title' => 'User Access Provisioning',
                'description' => 'A formal user access provisioning process shall be implemented to assign or revoke access rights for all user types to all systems and services.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'ACC-2.3',
                'title' => 'Management of Privileged Access Rights',
                'description' => 'The allocation and use of privileged access rights shall be restricted and controlled.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2'],
                ],
            ],
            [
                'id' => 'ACC-3.1',
                'title' => 'Use of Secret Authentication Information',
                'description' => 'Users shall be required to follow good security practices in the selection and use of secret authentication information.',
                'category' => 'User Responsibilities',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],

            // 3. Cryptography (CRY)
            [
                'id' => 'CRY-1.1',
                'title' => 'Policy on the Use of Cryptographic Controls',
                'description' => 'A policy on the use of cryptographic controls for protection of information shall be developed and implemented.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'CRY-1.2',
                'title' => 'Key Management',
                'description' => 'A policy on the use, protection and lifetime of cryptographic keys shall be developed and implemented through their whole lifecycle.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // 4. Physical and Environmental Security (PHY)
            [
                'id' => 'PHY-1.1',
                'title' => 'Physical Security Perimeter',
                'description' => 'Security perimeters shall be defined and used to protect areas that contain either sensitive or critical information and information processing facilities.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                ],
            ],
            [
                'id' => 'PHY-1.2',
                'title' => 'Physical Entry Controls',
                'description' => 'Secure areas shall be protected by appropriate entry controls to ensure that only authorized personnel are allowed access.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'PHY-2.1',
                'title' => 'Equipment Siting and Protection',
                'description' => 'Equipment shall be sited and protected to reduce the risks from environmental threats and hazards, and opportunities for unauthorized access.',
                'category' => 'Equipment Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],

            // 5. Operations Security (OPS)
            [
                'id' => 'OPS-1.1',
                'title' => 'Documented Operating Procedures',
                'description' => 'Operating procedures shall be documented and made available to all users who need them.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'OPS-2.1',
                'title' => 'Protection Against Malware',
                'description' => 'Detection, prevention and recovery controls to protect against malware shall be implemented, combined with appropriate user awareness.',
                'category' => 'Malware Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'OPS-3.1',
                'title' => 'Information Backup',
                'description' => 'Backup copies of information, software and system images shall be taken and tested regularly in accordance with an agreed backup policy.',
                'category' => 'Backup',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'OPS-4.1',
                'title' => 'Event Logging',
                'description' => 'Event logs recording user activities, exceptions, faults and information security events shall be produced, kept and regularly reviewed.',
                'category' => 'Logging and Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS-4.2',
                'title' => 'Protection of Log Information',
                'description' => 'Logging facilities and log information shall be protected against tampering and unauthorized access.',
                'category' => 'Logging and Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],

            // 6. Communications Security (COM)
            [
                'id' => 'COM-1.1',
                'title' => 'Network Controls',
                'description' => 'Networks shall be managed and controlled to protect information in systems and applications.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'COM-2.1',
                'title' => 'Information Transfer Policies and Procedures',
                'description' => 'Formal transfer policies, procedures and controls shall be in place to protect the transfer of information through the use of all types of communication facilities.',
                'category' => 'Information Transfer',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // 7. Business Continuity Management (BCM)
            [
                'id' => 'BCM-1.1',
                'title' => 'Planning Information Security Continuity',
                'description' => 'The organization shall determine its requirements for information security and the continuity of information security management in adverse situations.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BCM-1.2',
                'title' => 'Implementing Information Security Continuity',
                'description' => 'The organization shall establish, document, implement and maintain processes, procedures and controls to ensure the required level of continuity for information security during an adverse situation.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],

            // 8. Incident Management (INC)
            [
                'id' => 'INC-1.1',
                'title' => 'Responsibilities and Procedures',
                'description' => 'Management responsibilities and procedures shall be established to ensure a quick, effective and orderly response to information security incidents.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.2',
                'title' => 'Reporting Information Security Events',
                'description' => 'Information security events shall be reported through appropriate management channels as quickly as possible.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.3',
                'title' => 'Assessment of and Decision on Information Security Events',
                'description' => 'Information security events shall be assessed and it shall be decided if they are to be classified as information security incidents.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.4',
                'title' => 'Response to Information Security Incidents',
                'description' => 'Information security incidents shall be responded to in accordance with documented procedures.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.5',
                'title' => 'Learning from Information Security Incidents',
                'description' => 'Knowledge gained from analysing and resolving information security incidents shall be used to reduce the likelihood or impact of future incidents.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.27'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.6',
                'title' => 'Collection of Evidence',
                'description' => 'The organization shall establish and implement procedures for the identification, collection, acquisition and preservation of information, which can serve as evidence.',
                'category' => 'Incident Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                    'incident_management' => true,
                ],
            ],

            // 9. System Acquisition, Development and Maintenance (DEV)
            [
                'id' => 'DEV-1.1',
                'title' => 'Security Requirements Analysis and Specification',
                'description' => 'Information security related requirements shall be included in the requirements for new information systems or enhancements to existing information systems.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27'],
                ],
            ],
            [
                'id' => 'DEV-1.2',
                'title' => 'Secure System Architecture and Engineering Principles',
                'description' => 'Principles for engineering secure systems shall be established, documented, maintained and applied to any information system implementation efforts.',
                'category' => 'System Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.27'],
                ],
            ],
            [
                'id' => 'DEV-2.1',
                'title' => 'Secure Development Policy',
                'description' => 'Rules for the development of software and systems shall be established and applied to developments within the organization.',
                'category' => 'Secure Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.28'],
                ],
            ],
            [
                'id' => 'DEV-2.2',
                'title' => 'System Change Control Procedures',
                'description' => 'The implementation of changes shall be controlled by the use of formal change control procedures.',
                'category' => 'Change Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'DEV-2.3',
                'title' => 'Technical Review of Applications after Operating Platform Changes',
                'description' => 'When operating platforms are changed, business critical applications shall be reviewed and tested to ensure there is no adverse impact on organizational operations or security.',
                'category' => 'Change Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'DEV-3.1',
                'title' => 'Security Testing in Development and Acceptance',
                'description' => 'Security testing shall be carried out during development.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DEV-3.2',
                'title' => 'Protection of Test Data',
                'description' => 'Test data shall be selected carefully, protected and controlled.',
                'category' => 'Security Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.33'],
                ],
            ],

            // 10. Supplier Relationships (SUP)
            [
                'id' => 'SUP-1.1',
                'title' => 'Information Security Policy for Supplier Relationships',
                'description' => 'Information security requirements for mitigating the risks associated with supplier access to the organization assets shall be agreed with the supplier and documented.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'SUP-1.2',
                'title' => 'Addressing Security within Supplier Agreements',
                'description' => 'Relevant information security requirements shall be established and agreed with each supplier that may access, process, store, communicate, or provide IT infrastructure components for, the organization information.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.21'],
                ],
            ],
            [
                'id' => 'SUP-2.1',
                'title' => 'Monitoring and Review of Supplier Services',
                'description' => 'Organizations shall regularly monitor, review and audit supplier service delivery.',
                'category' => 'Supplier Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'SUP-2.2',
                'title' => 'Managing Changes to Supplier Services',
                'description' => 'Changes to the provision of services by suppliers, including maintaining and improving existing information security policies, procedures and controls, shall be managed.',
                'category' => 'Supplier Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                ],
            ],

            // 11. Human Resources Security (HRS)
            [
                'id' => 'HRS-1.1',
                'title' => 'Screening',
                'description' => 'Background verification checks on all candidates for employment shall be carried out in accordance with relevant laws, regulations and ethics and shall be proportional to the business requirements, the classification of the information to be accessed and the perceived risks.',
                'category' => 'Human Resources Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'HRS-1.2',
                'title' => 'Terms and Conditions of Employment',
                'description' => 'The contractual agreements with employees and contractors shall state their and the organization responsibilities for information security.',
                'category' => 'Human Resources Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'HRS-2.1',
                'title' => 'Management Responsibilities',
                'description' => 'Management shall require all employees and contractors to apply information security in accordance with the established policies and procedures of the organization.',
                'category' => 'During Employment',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'HRS-2.2',
                'title' => 'Information Security Awareness, Education and Training',
                'description' => 'All employees of the organization and, where relevant, contractors shall receive appropriate awareness education and training and regular updates in organizational policies and procedures.',
                'category' => 'During Employment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'HRS-2.3',
                'title' => 'Disciplinary Process',
                'description' => 'There shall be a formal and communicated disciplinary process in place to take action against employees who have committed an information security breach.',
                'category' => 'During Employment',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['6.4'],
                ],
            ],
            [
                'id' => 'HRS-3.1',
                'title' => 'Termination or Change of Employment Responsibilities',
                'description' => 'Information security responsibilities and duties that remain valid after termination or change of employment shall be defined, communicated to the employee or contractor and enforced.',
                'category' => 'Termination and Change',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.5'],
                ],
            ],

            // 12. Compliance (CMP)
            [
                'id' => 'CMP-1.1',
                'title' => 'Identification of Applicable Legislation and Contractual Requirements',
                'description' => 'All relevant legislative statutory, regulatory, contractual requirements and the organization approach to meet these requirements shall be explicitly identified, documented and kept up to date.',
                'category' => 'Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'CMP-1.2',
                'title' => 'Intellectual Property Rights',
                'description' => 'Appropriate procedures shall be implemented to ensure compliance with legislative, regulatory and contractual requirements related to intellectual property rights and use of proprietary software products.',
                'category' => 'Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.32'],
                ],
            ],
            [
                'id' => 'CMP-1.3',
                'title' => 'Protection of Records',
                'description' => 'Records shall be protected from loss, destruction, falsification, unauthorized access and unauthorized release, in accordance with legislative, regulatory, contractual and business requirements.',
                'category' => 'Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.33'],
                ],
            ],
            [
                'id' => 'CMP-1.4',
                'title' => 'Privacy and Protection of Personally Identifiable Information',
                'description' => 'Privacy and protection of personally identifiable information shall be ensured as required in relevant legislation and regulation where applicable.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'CMP-2.1',
                'title' => 'Independent Review of Information Security',
                'description' => 'The organization approach to managing information security and its implementation shall be reviewed independently at planned intervals.',
                'category' => 'Information Security Reviews',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'CMP-2.2',
                'title' => 'Compliance with Security Policies and Standards',
                'description' => 'Managers shall regularly review the compliance of information processing and procedures within their area of responsibility with the appropriate security policies, standards and any other security requirements.',
                'category' => 'Information Security Reviews',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'CMP-2.3',
                'title' => 'Technical Compliance Review',
                'description' => 'Information systems shall be regularly reviewed for compliance with the organization information security policies and standards.',
                'category' => 'Technical Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.37'],
                    'audit_evidence' => true,
                ],
            ],

            // 13. Prototype Protection (PROT) - Automotive-specific
            [
                'id' => 'PROT-1.1',
                'title' => 'Identification and Classification of Prototypes',
                'description' => 'Prototypes shall be identified and classified according to their protection needs.',
                'category' => 'Prototype Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                    'asset_types' => ['hardware', 'data'],
                ],
            ],
            [
                'id' => 'PROT-1.2',
                'title' => 'Physical Protection of Prototypes',
                'description' => 'Prototypes shall be physically protected in accordance with their classification and protection needs.',
                'category' => 'Prototype Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2'],
                ],
            ],
            [
                'id' => 'PROT-1.3',
                'title' => 'Prototype Handling and Transport',
                'description' => 'Procedures for secure handling and transport of prototypes shall be established and implemented.',
                'category' => 'Prototype Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.14'],
                ],
            ],
            [
                'id' => 'PROT-2.1',
                'title' => 'Protection of Prototype Data',
                'description' => 'Digital data related to prototypes shall be protected with appropriate technical and organizational measures.',
                'category' => 'Prototype Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '8.11', '8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'PROT-2.2',
                'title' => 'Camouflage and Concealment',
                'description' => 'Appropriate camouflage and concealment measures shall be applied to prototypes where required.',
                'category' => 'Prototype Protection',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                ],
            ],

            // 14. Additional Access Control Requirements
            [
                'id' => 'ACC-2.4',
                'title' => 'Review of User Access Rights',
                'description' => 'Asset owners shall review users access rights at regular intervals.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ACC-2.5',
                'title' => 'Removal of Access Rights',
                'description' => 'The access rights of all employees and external party users to information and information processing facilities shall be removed upon termination of their employment, contract or agreement, or adjusted upon change.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'ACC-3.2',
                'title' => 'Secure Log-on Procedures',
                'description' => 'Where required by the access control policy, access to systems and applications shall be controlled by a secure log-on procedure.',
                'category' => 'System and Application Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.5'],
                ],
            ],
            [
                'id' => 'ACC-3.3',
                'title' => 'Password Management System',
                'description' => 'Password management systems shall be interactive and shall ensure quality passwords.',
                'category' => 'System and Application Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                ],
            ],
            [
                'id' => 'ACC-3.4',
                'title' => 'Use of Privileged Utility Programs',
                'description' => 'The use of utility programs that might be capable of overriding system and application controls shall be restricted and tightly controlled.',
                'category' => 'System and Application Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.18'],
                ],
            ],
            [
                'id' => 'ACC-3.5',
                'title' => 'Access Control to Program Source Code',
                'description' => 'Access to program source code shall be restricted.',
                'category' => 'System and Application Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.4'],
                ],
            ],

            // 15. Additional Operations Security Requirements
            [
                'id' => 'OPS-1.2',
                'title' => 'Change Management',
                'description' => 'Changes to the organization, business processes, information processing facilities and systems that affect information security shall be controlled.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'OPS-1.3',
                'title' => 'Capacity Management',
                'description' => 'The use of resources shall be monitored, tuned and projections made of future capacity requirements to ensure the required system performance.',
                'category' => 'Capacity Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6'],
                ],
            ],
            [
                'id' => 'OPS-1.4',
                'title' => 'Separation of Development, Testing and Operational Environments',
                'description' => 'Development, testing, and operational environments shall be separated to reduce the risks of unauthorized access or changes to the operational environment.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.31'],
                ],
            ],
            [
                'id' => 'OPS-4.3',
                'title' => 'Administrator and Operator Logs',
                'description' => 'System administrator and system operator activities shall be logged and the logs protected and regularly reviewed.',
                'category' => 'Logging and Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS-4.4',
                'title' => 'Clock Synchronization',
                'description' => 'The clocks of all relevant information processing systems within an organization or security domain shall be synchronised to a single reference time source.',
                'category' => 'Logging and Monitoring',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS-5.1',
                'title' => 'Installation of Software on Operational Systems',
                'description' => 'Procedures shall be implemented to control the installation of software on operational systems.',
                'category' => 'Software Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19'],
                ],
            ],
            [
                'id' => 'OPS-6.1',
                'title' => 'Technical Vulnerability Management',
                'description' => 'Information about technical vulnerabilities of information systems being used shall be obtained in a timely fashion, the organization exposure to such vulnerabilities evaluated and appropriate measures taken.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'OPS-7.1',
                'title' => 'Information Systems Audit Controls',
                'description' => 'Audit requirements and activities involving verification of operational systems shall be carefully planned and agreed to minimize disruptions to business processes.',
                'category' => 'Audit Considerations',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'audit_evidence' => true,
                ],
            ],

            // 16. Additional Communication Security Requirements
            [
                'id' => 'COM-1.2',
                'title' => 'Security of Network Services',
                'description' => 'Security mechanisms, service levels, and management requirements of all network services shall be identified and included in network services agreements.',
                'category' => 'Network Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'COM-1.3',
                'title' => 'Segregation in Networks',
                'description' => 'Groups of information services, users and information systems shall be segregated on networks.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                ],
            ],
            [
                'id' => 'COM-2.2',
                'title' => 'Agreements on Information Transfer',
                'description' => 'Agreements shall address the secure transfer of business information between the organization and external parties.',
                'category' => 'Information Transfer',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],
            [
                'id' => 'COM-2.3',
                'title' => 'Electronic Messaging',
                'description' => 'Information involved in electronic messaging shall be appropriately protected.',
                'category' => 'Information Transfer',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.23'],
                ],
            ],
            [
                'id' => 'COM-2.4',
                'title' => 'Confidentiality or Non-disclosure Agreements',
                'description' => 'Requirements for confidentiality or non-disclosure agreements reflecting the organization needs for the protection of information shall be identified, regularly reviewed and documented.',
                'category' => 'Information Transfer',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // 17. Additional Physical Security Requirements
            [
                'id' => 'PHY-1.3',
                'title' => 'Securing Offices, Rooms and Facilities',
                'description' => 'Physical security for offices, rooms and facilities shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.3'],
                ],
            ],
            [
                'id' => 'PHY-1.4',
                'title' => 'Protecting Against External and Environmental Threats',
                'description' => 'Physical protection against natural disasters, malicious attack or accidents shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],
            [
                'id' => 'PHY-1.5',
                'title' => 'Working in Secure Areas',
                'description' => 'Procedures for working in secure areas shall be designed and applied.',
                'category' => 'Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.5'],
                ],
            ],
            [
                'id' => 'PHY-1.6',
                'title' => 'Delivery and Loading Areas',
                'description' => 'Access points such as delivery and loading areas and other points where unauthorized persons could enter the premises shall be controlled and, if possible, isolated from information processing facilities.',
                'category' => 'Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.6'],
                ],
            ],
            [
                'id' => 'PHY-2.2',
                'title' => 'Supporting Utilities',
                'description' => 'Equipment shall be protected from power failures and other disruptions caused by failures in supporting utilities.',
                'category' => 'Equipment Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9'],
                ],
            ],
            [
                'id' => 'PHY-2.3',
                'title' => 'Cabling Security',
                'description' => 'Power and telecommunications cabling carrying data or supporting information services shall be protected from interception, interference or damage.',
                'category' => 'Equipment Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.10'],
                ],
            ],
            [
                'id' => 'PHY-2.4',
                'title' => 'Equipment Maintenance',
                'description' => 'Equipment shall be correctly maintained to ensure its continued availability and integrity.',
                'category' => 'Equipment Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.11'],
                ],
            ],
            [
                'id' => 'PHY-2.5',
                'title' => 'Removal of Assets',
                'description' => 'Equipment, information or software shall not be taken off-site without prior authorization.',
                'category' => 'Equipment Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.12'],
                ],
            ],
            [
                'id' => 'PHY-2.6',
                'title' => 'Security of Equipment and Assets Off-premises',
                'description' => 'Security shall be applied to off-site assets taking into account the different risks of working outside the organization premises.',
                'category' => 'Equipment Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7', '8.9'],
                ],
            ],
            [
                'id' => 'PHY-2.7',
                'title' => 'Secure Disposal or Re-use of Equipment',
                'description' => 'All items of equipment containing storage media shall be verified to ensure that any sensitive data and licensed software has been removed or securely overwritten prior to disposal or re-use.',
                'category' => 'Equipment Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.14'],
                ],
            ],
            [
                'id' => 'PHY-2.8',
                'title' => 'Unattended User Equipment',
                'description' => 'Users shall ensure that unattended equipment has appropriate protection.',
                'category' => 'Equipment Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7', '8.9'],
                ],
            ],
            [
                'id' => 'PHY-2.9',
                'title' => 'Clear Desk and Clear Screen Policy',
                'description' => 'A clear desk policy for papers and removable storage media and a clear screen policy for information processing facilities shall be adopted.',
                'category' => 'Equipment Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7'],
                ],
            ],

            // 18. Additional Information Classification Requirements
            [
                'id' => 'INF-4.3',
                'title' => 'Handling of Assets',
                'description' => 'Procedures for handling assets shall be developed and implemented in accordance with the information classification scheme adopted by the organization.',
                'category' => 'Information Classification',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.13'],
                ],
            ],

            // 19. Mobile Devices and Teleworking
            [
                'id' => 'MOB-1.1',
                'title' => 'Mobile Device Policy',
                'description' => 'A policy and supporting security measures shall be adopted to manage the risks introduced by using mobile devices.',
                'category' => 'Mobile Devices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7', '8.9'],
                ],
            ],
            [
                'id' => 'MOB-1.2',
                'title' => 'Teleworking',
                'description' => 'A policy and supporting security measures shall be implemented to protect information accessed, processed or stored at teleworking sites.',
                'category' => 'Teleworking',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],

            // 20. Information Security Risk Assessment
            [
                'id' => 'INF-5.1',
                'title' => 'Information Security Risk Assessment',
                'description' => 'The organization shall define and apply an information security risk assessment process.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-5.2',
                'title' => 'Information Security Risk Treatment',
                'description' => 'The organization shall define and apply an information security risk treatment process.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'audit_evidence' => true,
                ],
            ],
        ];
    }
}
