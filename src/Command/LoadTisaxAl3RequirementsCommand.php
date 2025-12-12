<?php

namespace App\Command;

use DateTimeImmutable;
use Exception;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TISAX VDA ISA 6.x Extended Requirements Command
 *
 * Based on VDA ISA Catalog 6.0.3 (effective April 2024)
 *
 * TISAX has 12 Labels across 3 Modules:
 *
 * 1. INFORMATION SECURITY MODULE (6 Labels)
 *    Confidentiality:
 *    - Confidential (AL2): High protection needs
 *    - Strictly Confidential (AL3): Very high protection needs
 *    Availability:
 *    - High Availability (AL2): High availability needs
 *    - Very High Availability (AL3): Critical availability needs
 *
 * 2. PROTOTYPE PROTECTION MODULE (4 Labels - ALL require AL3)
 *    - Proto Parts: Components classified as requiring protection
 *    - Proto Vehicles: Vehicles classified as requiring protection
 *    - Test Vehicles: Vehicles for test drives on public roads
 *    - Events & Shootings: Prototype protection during media events
 *
 * 3. DATA PROTECTION MODULE (2 Labels)
 *    - Data (AL2): Personal data processing as processor (GDPR Art. 28)
 *    - Special Data (AL3): Special categories of personal data
 *
 * This command adds module-specific requirements to the existing TISAX framework.
 *
 * @see https://portal.enx.com/en-us/TISAX/downloads/
 * @see https://www.cis-cert.com/en/news/tisax-deep-dive-the-12-test-objectives-labels/
 */
#[AsCommand(
    name: 'app:load-tisax-al3-requirements',
    description: 'Load TISAX VDA ISA 6.x extended requirements (Confidentiality, Availability, Prototype, Data Protection)'
)]
class LoadTisaxAl3RequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Get existing TISAX framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'TISAX']);
        if (!$framework instanceof ComplianceFramework) {
            $symfonyStyle->error('TISAX framework not found. Please run app:load-tisax-requirements first.');
            return Command::FAILURE;
        }

        $symfonyStyle->info('Adding TISAX VDA ISA 6.x extended requirements...');
        $symfonyStyle->info('Modules: Confidentiality/Availability (AL2/AL3), Prototype Protection (AL3), Data Protection');

        try {
            $this->entityManager->beginTransaction();

            $requirements = array_merge(
                $this->getConfidentialityRequirements(),
                $this->getAvailabilityRequirements(),
                $this->getPrototypeProtectionRequirements(),
                $this->getDataProtectionRequirements()
            );

            $addedCount = 0;

            foreach ($requirements as $reqData) {
                // Check if requirement already exists
                $existing = $this->entityManager
                    ->getRepository(ComplianceRequirement::class)
                    ->findOneBy([
                        'complianceFramework' => $framework,
                        'requirementId' => $reqData['id']
                    ]);

                if ($existing instanceof ComplianceRequirement) {
                    continue;
                }

                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $addedCount++;
            }

            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($framework);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $symfonyStyle->success(sprintf('Successfully added %d TISAX extended requirements', $addedCount));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $symfonyStyle->error('Failed to load TISAX requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * CONFIDENTIALITY Labels Requirements (C-tagged in ISA 6)
     * - Confidential (AL2): High protection needs
     * - Strictly Confidential (AL3): Very high protection needs
     */
    private function getConfidentialityRequirements(): array
    {
        return [
            // Strictly Confidential (AL3) specific requirements
            [
                'id' => 'TISAX-CONF-SC-1.1',
                'title' => 'Strictly Confidential Data Classification',
                'description' => 'A classification system shall distinguish between Confidential and Strictly Confidential information. Strictly Confidential applies to information whose disclosure could cause severe damage to business interests.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '5.13'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.2',
                'title' => 'Enhanced Access Control for Strictly Confidential',
                'description' => 'Access to Strictly Confidential information shall require multi-factor authentication, documented need-to-know approval, and time-limited access with mandatory review.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.17', '5.18'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.3',
                'title' => 'Encryption for Strictly Confidential Data',
                'description' => 'Strictly Confidential data shall be encrypted at rest using AES-256 or equivalent, and in transit using TLS 1.3 or equivalent. Key management shall follow industry best practices.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.4',
                'title' => 'Data Loss Prevention for Strictly Confidential',
                'description' => 'DLP controls shall prevent unauthorized transmission of Strictly Confidential data via email, web uploads, removable media, or other channels.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.12'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.5',
                'title' => 'Enhanced Audit Logging for Strictly Confidential',
                'description' => 'All access to Strictly Confidential data shall be logged with immutable audit trails. Logs shall include user identity, timestamp, action performed, and data accessed.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.6',
                'title' => 'Secure Disposal of Strictly Confidential Media',
                'description' => 'Media containing Strictly Confidential data shall be destroyed using certified destruction methods (physical shredding, degaussing) with documented chain of custody.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-CONF-SC-1.7',
                'title' => 'Physical Security for Strictly Confidential Processing',
                'description' => 'Areas where Strictly Confidential data is processed shall have enhanced physical security including access control, CCTV, and clear desk policy enforcement.',
                'category' => 'Strictly Confidential (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.7'],
                    'tisax_label' => 'Strictly Confidential',
                    'tisax_level' => 'AL3',
                ],
            ],
        ];
    }

    /**
     * AVAILABILITY Labels Requirements (A-tagged in ISA 6)
     * - High Availability (AL2): Essential for supply chain
     * - Very High Availability (AL3): Critical for supply chain
     */
    private function getAvailabilityRequirements(): array
    {
        return [
            // Very High Availability (AL3) specific requirements
            [
                'id' => 'TISAX-AVAIL-VH-1.1',
                'title' => 'Business Impact Analysis for Critical Services',
                'description' => 'A comprehensive BIA shall identify critical business processes, their dependencies, and maximum tolerable downtime (MTD) for each critical service.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.2',
                'title' => 'Recovery Time Objective (RTO) Definition',
                'description' => 'RTOs shall be defined for all critical systems based on business impact. Very High Availability systems shall have RTOs of 4 hours or less.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.3',
                'title' => 'Recovery Point Objective (RPO) Definition',
                'description' => 'RPOs shall be defined for all critical data. Very High Availability systems shall have RPOs of 1 hour or less with continuous data protection where possible.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.4',
                'title' => 'Redundant Infrastructure',
                'description' => 'Critical systems shall have redundant components (servers, storage, network) with automatic failover capabilities. Single points of failure shall be eliminated.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.14'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.5',
                'title' => 'Disaster Recovery Site',
                'description' => 'A geographically separate disaster recovery site shall be maintained for critical systems with regular synchronization and tested failover procedures.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30', '8.14'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.6',
                'title' => 'IT Service Continuity Testing',
                'description' => 'IT service continuity plans shall be tested at least annually including full failover tests. Test results shall be documented and deficiencies remediated.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.7',
                'title' => 'Backup and Restore Procedures',
                'description' => 'Comprehensive backup procedures shall include 3-2-1 strategy (3 copies, 2 media types, 1 offsite), regular restore testing, and immutable backups for ransomware protection.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-AVAIL-VH-1.8',
                'title' => 'Crisis Management Procedures',
                'description' => 'Crisis management procedures shall define escalation paths, communication plans, and decision-making authority for major disruptions.',
                'category' => 'Very High Availability (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'tisax_label' => 'Very High Availability',
                    'tisax_level' => 'AL3',
                    'bcm_required' => true,
                ],
            ],
        ];
    }

    /**
     * PROTOTYPE PROTECTION Labels Requirements (ALL require AL3)
     * - Proto Parts: Components classified as requiring protection
     * - Proto Vehicles: Vehicles classified as requiring protection
     * - Test Vehicles: Test drives on public roads
     * - Events & Shootings: Media events
     */
    private function getPrototypeProtectionRequirements(): array
    {
        return [
            // General Prototype Protection (all labels)
            [
                'id' => 'TISAX-PROTO-GEN-1.1',
                'title' => 'Prototype Classification System',
                'description' => 'A prototype classification system shall categorize prototypes by protection level (Standard, High, Secret) based on development stage, novelty, and competitive sensitivity.',
                'category' => 'Prototype Protection General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '5.13'],
                    'tisax_label' => 'Proto Parts, Proto Vehicles, Test Vehicles, Events & Shootings',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['hardware', 'data'],
                ],
            ],
            [
                'id' => 'TISAX-PROTO-GEN-1.2',
                'title' => 'Prototype Inventory Management',
                'description' => 'A complete inventory of all prototypes shall be maintained including unique identifiers, current location, custody chain, and classification level.',
                'category' => 'Prototype Protection General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'tisax_label' => 'Proto Parts, Proto Vehicles, Test Vehicles',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['hardware'],
                ],
            ],
            [
                'id' => 'TISAX-PROTO-GEN-1.3',
                'title' => 'Recording Device Prohibition',
                'description' => 'Recording devices (cameras, smartphones, smartwatches) shall be prohibited in prototype areas. Technical controls and regular sweeps shall be implemented.',
                'category' => 'Prototype Protection General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7', '8.1'],
                    'tisax_label' => 'Proto Parts, Proto Vehicles, Test Vehicles, Events & Shootings',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-GEN-1.4',
                'title' => 'Prototype Data Digital Security',
                'description' => 'Digital prototype data (CAD files, specifications, test data) shall be encrypted, access-controlled, and protected by DLP systems with watermarking.',
                'category' => 'Prototype Protection General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.12', '8.24'],
                    'tisax_label' => 'Proto Parts, Proto Vehicles',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],

            // Proto Parts specific
            [
                'id' => 'TISAX-PROTO-PARTS-1.1',
                'title' => 'Secure Storage for Proto Parts',
                'description' => 'Proto Parts shall be stored in dedicated secure areas with controlled access (badge + PIN minimum), CCTV surveillance, and access logging.',
                'category' => 'Proto Parts (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.4'],
                    'tisax_label' => 'Proto Parts',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-PARTS-1.2',
                'title' => 'Proto Parts Handling Procedures',
                'description' => 'Documented procedures for handling Proto Parts shall include receipt, storage, movement, and disposal with chain of custody documentation.',
                'category' => 'Proto Parts (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                    'tisax_label' => 'Proto Parts',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-PARTS-1.3',
                'title' => 'Proto Parts Transport Security',
                'description' => 'Transport of Proto Parts shall use approved carriers with tamper-evident packaging, tracking, and documented handover procedures.',
                'category' => 'Proto Parts (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.14'],
                    'tisax_label' => 'Proto Parts',
                    'tisax_level' => 'AL3',
                ],
            ],

            // Proto Vehicles specific
            [
                'id' => 'TISAX-PROTO-VEH-1.1',
                'title' => 'Secure Garage for Proto Vehicles',
                'description' => 'Proto Vehicles shall be stored in dedicated secure garages with biometric + badge access, 24/7 CCTV, alarm systems, and security staff presence.',
                'category' => 'Proto Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.4'],
                    'tisax_label' => 'Proto Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-VEH-1.2',
                'title' => 'Vehicle Camouflage Requirements',
                'description' => 'Proto Vehicles outside secure areas shall have appropriate camouflage (wrapping, covers, body modifications) to prevent recognition of design features.',
                'category' => 'Proto Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                    'tisax_label' => 'Proto Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-VEH-1.3',
                'title' => 'Proto Vehicle Movement Control',
                'description' => 'Movement of Proto Vehicles shall be pre-approved, logged, and tracked. Vehicles shall not be left unattended outside secure areas.',
                'category' => 'Proto Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                    'tisax_label' => 'Proto Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],

            // Test Vehicles specific (public road testing)
            [
                'id' => 'TISAX-PROTO-TEST-1.1',
                'title' => 'Test Vehicle Route Planning',
                'description' => 'Test routes for Test Vehicles shall be pre-planned to avoid high-visibility areas (airports, trade fairs, competitor locations) and documented.',
                'category' => 'Test Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                    'tisax_label' => 'Test Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-TEST-1.2',
                'title' => 'Test Driver Security Training',
                'description' => 'Test drivers shall receive security training covering photographer evasion, incident response, and proper vehicle handling in public.',
                'category' => 'Test Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'tisax_label' => 'Test Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-TEST-1.3',
                'title' => 'Test Vehicle GPS and Communication',
                'description' => 'Test Vehicles shall be equipped with GPS tracking and communication devices. Drivers shall report position and any security incidents immediately.',
                'category' => 'Test Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                    'tisax_label' => 'Test Vehicles',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-TEST-1.4',
                'title' => 'Test Vehicle Incident Response',
                'description' => 'Procedures shall define response to test vehicle sightings, photographer encounters, accidents, and breakdowns to minimize exposure.',
                'category' => 'Test Vehicles (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'tisax_label' => 'Test Vehicles',
                    'tisax_level' => 'AL3',
                    'incident_management' => true,
                ],
            ],

            // Events & Shootings specific
            [
                'id' => 'TISAX-PROTO-EVENT-1.1',
                'title' => 'Event Venue Security Assessment',
                'description' => 'Event venues for prototype presentations shall undergo security assessment including access control, sight lines, and potential photography positions.',
                'category' => 'Events & Shootings (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2'],
                    'tisax_label' => 'Events & Shootings',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-EVENT-1.2',
                'title' => 'Media Personnel Vetting',
                'description' => 'Media personnel at prototype events shall be vetted, briefed on restrictions, and required to sign NDAs. Equipment shall be checked and controlled.',
                'category' => 'Events & Shootings (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                    'tisax_label' => 'Events & Shootings',
                    'tisax_level' => 'AL3',
                ],
            ],
            [
                'id' => 'TISAX-PROTO-EVENT-1.3',
                'title' => 'Photo and Video Material Control',
                'description' => 'All photos and videos taken at events shall be reviewed before release. Unauthorized images shall be deleted. Embargo periods shall be enforced.',
                'category' => 'Events & Shootings (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'tisax_label' => 'Events & Shootings',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],
        ];
    }

    /**
     * DATA PROTECTION Labels Requirements
     * - Data (AL2): Personal data processing as processor (GDPR Art. 28)
     * - Special Data (AL3): Special categories of personal data
     */
    private function getDataProtectionRequirements(): array
    {
        return [
            // Data label (AL2) requirements
            [
                'id' => 'TISAX-DATA-1.1',
                'title' => 'Data Processing Agreement (Art. 28 GDPR)',
                'description' => 'When processing personal data as processor, a compliant Data Processing Agreement shall be in place covering all requirements of GDPR Art. 28.',
                'category' => 'Data Protection (AL2)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Data',
                    'tisax_level' => 'AL2',
                    'gdpr_article' => 'Art. 28',
                ],
            ],
            [
                'id' => 'TISAX-DATA-1.2',
                'title' => 'Processing Activities Documentation',
                'description' => 'A record of processing activities (Art. 30 GDPR) shall document all personal data processing with purposes, categories, recipients, and retention periods.',
                'category' => 'Data Protection (AL2)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Data',
                    'tisax_level' => 'AL2',
                    'gdpr_article' => 'Art. 30',
                ],
            ],
            [
                'id' => 'TISAX-DATA-1.3',
                'title' => 'Data Subject Rights Support',
                'description' => 'Processes shall support data subject rights (access, rectification, erasure, portability) within GDPR timeframes.',
                'category' => 'Data Protection (AL2)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Data',
                    'tisax_level' => 'AL2',
                    'gdpr_article' => 'Art. 15-20',
                ],
            ],
            [
                'id' => 'TISAX-DATA-1.4',
                'title' => 'Data Breach Notification Process',
                'description' => 'A process for detecting, reporting, and managing personal data breaches shall ensure notification within 72 hours (Art. 33 GDPR).',
                'category' => 'Data Protection (AL2)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'tisax_label' => 'Data',
                    'tisax_level' => 'AL2',
                    'gdpr_article' => 'Art. 33',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'TISAX-DATA-1.5',
                'title' => 'Sub-processor Management',
                'description' => 'Use of sub-processors shall require prior authorization. Sub-processors shall be bound by equivalent data protection obligations.',
                'category' => 'Data Protection (AL2)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'tisax_label' => 'Data',
                    'tisax_level' => 'AL2',
                    'gdpr_article' => 'Art. 28(2)',
                ],
            ],

            // Special Data label (AL3) requirements
            [
                'id' => 'TISAX-SDATA-1.1',
                'title' => 'Special Categories Processing Justification',
                'description' => 'Processing of special categories (health, biometric, religious data) shall be justified under Art. 9 GDPR with documented legal basis.',
                'category' => 'Special Data Protection (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Special Data',
                    'tisax_level' => 'AL3',
                    'gdpr_article' => 'Art. 9',
                ],
            ],
            [
                'id' => 'TISAX-SDATA-1.2',
                'title' => 'Enhanced Security for Special Categories',
                'description' => 'Special category data shall have enhanced security measures including encryption, strict access control, and enhanced monitoring.',
                'category' => 'Special Data Protection (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24', '5.15'],
                    'tisax_label' => 'Special Data',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'TISAX-SDATA-1.3',
                'title' => 'Data Protection Impact Assessment',
                'description' => 'A DPIA shall be conducted for processing special category data where required by Art. 35 GDPR, with documented risk assessment and mitigations.',
                'category' => 'Special Data Protection (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Special Data',
                    'tisax_level' => 'AL3',
                    'gdpr_article' => 'Art. 35',
                ],
            ],
            [
                'id' => 'TISAX-SDATA-1.4',
                'title' => 'Pseudonymization and Anonymization',
                'description' => 'Where possible, special category data shall be pseudonymized or anonymized to reduce risk. Procedures shall ensure effectiveness of these techniques.',
                'category' => 'Special Data Protection (AL3)',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'tisax_label' => 'Special Data',
                    'tisax_level' => 'AL3',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'TISAX-SDATA-1.5',
                'title' => 'International Transfer Safeguards',
                'description' => 'Transfers of special category data to third countries shall comply with GDPR Chapter V using appropriate safeguards (SCC, adequacy decisions, BCR).',
                'category' => 'Special Data Protection (AL3)',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'tisax_label' => 'Special Data',
                    'tisax_level' => 'AL3',
                    'gdpr_article' => 'Art. 44-49',
                ],
            ],
        ];
    }
}
