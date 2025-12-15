<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-nist-csf-requirements',
    description: 'Load NIST Cybersecurity Framework 2.0 with ISO 27001 control mappings'
)]
class LoadNistCsfRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(#[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping them')]
    bool $update = false, ?SymfonyStyle $symfonyStyle = null): int
    {
        $updateMode = $update;

        $symfonyStyle->title('Loading NIST CSF 2.0 Requirements');
        $symfonyStyle->text(sprintf('Mode: %s', $updateMode ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Create or get NIST CSF framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'NIST-CSF']);

        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('NIST-CSF')
                ->setName('NIST Cybersecurity Framework 2.0')
                ->setDescription('Framework for improving critical infrastructure cybersecurity')
                ->setVersion('2.0')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('NIST (National Institute of Standards and Technology)')
                ->setMandatory(false)
                ->setScopeDescription('Voluntary framework to help organizations manage cybersecurity risks')
                ->setActive(true);

            $this->entityManager->persist($framework);
            $symfonyStyle->text('✓ Created framework');
        } else {
            $symfonyStyle->text('✓ Framework exists');
        }

        $requirements = $this->getNistCsfRequirements();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqData['id']
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($updateMode) {
                    $existing->setTitle($reqData['title'])
                        ->setDescription($reqData['description'])
                        ->setCategory($reqData['category'])
                        ->setPriority($reqData['priority'])
                        ->setDataSourceMapping($reqData['data_source_mapping']);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $stats['created']++;
            }

            // Batch flush
            if (($stats['created'] + $stats['updated']) % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success('NIST CSF 2.0 requirements loaded!');
        $symfonyStyle->table(
            ['Action', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Total', count($requirements)],
            ]
        );

        return Command::SUCCESS;
    }

    private function getNistCsfRequirements(): array
    {
        return [
            // GOVERN (GV)
            [
                'id' => 'GV.OC-01',
                'title' => 'Organizational cybersecurity policy',
                'description' => 'The organizational cybersecurity strategy is established and communicated.',
                'category' => 'Govern - Organizational Context',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1', '5.2', '5.4']],
            ],
            [
                'id' => 'GV.OC-02',
                'title' => 'Internal and external stakeholders',
                'description' => 'Internal and external stakeholders are understood, and their needs and expectations are considered.',
                'category' => 'Govern - Organizational Context',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.5', '5.6']],
            ],
            [
                'id' => 'GV.RM-01',
                'title' => 'Risk management objectives',
                'description' => 'Risk management objectives are established and agreed to by organizational stakeholders.',
                'category' => 'Govern - Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1', '5.2']],
            ],
            [
                'id' => 'GV.RM-02',
                'title' => 'Risk appetite and risk tolerance',
                'description' => 'Risk appetite and risk tolerance statements are established and maintained.',
                'category' => 'Govern - Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1']],
            ],
            [
                'id' => 'GV.SC-01',
                'title' => 'Cybersecurity supply chain risk',
                'description' => 'A cybersecurity supply chain risk management program is established, implemented, and maintained.',
                'category' => 'Govern - Supply Chain',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.19', '5.20', '5.21']],
            ],
            [
                'id' => 'GV.RR-01',
                'title' => 'Roles and responsibilities',
                'description' => 'Cybersecurity roles, responsibilities, and authorities are established and communicated.',
                'category' => 'Govern - Roles & Responsibilities',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.2', '5.3', '5.4']],
            ],
            [
                'id' => 'GV.PO-01',
                'title' => 'Policy establishment',
                'description' => 'Organizational cybersecurity policy is established and communicated.',
                'category' => 'Govern - Policy',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1']],
            ],
            [
                'id' => 'GV.OV-01',
                'title' => 'Cybersecurity oversight',
                'description' => 'Oversight functions, including the board of directors or equivalent, have cybersecurity awareness.',
                'category' => 'Govern - Oversight',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.4']],
            ],

            // IDENTIFY (ID)
            [
                'id' => 'ID.AM-01',
                'title' => 'Physical devices and systems inventory',
                'description' => 'Physical devices and systems within the organization are inventoried.',
                'category' => 'Identify - Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9'], 'asset_types' => ['hardware']],
            ],
            [
                'id' => 'ID.AM-02',
                'title' => 'Software platforms and applications inventory',
                'description' => 'Software platforms and applications within the organization are inventoried.',
                'category' => 'Identify - Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9'], 'asset_types' => ['software']],
            ],
            [
                'id' => 'ID.AM-03',
                'title' => 'Data and information flows',
                'description' => 'Organizational communication and data flows are mapped.',
                'category' => 'Identify - Asset Management',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.9', '5.14']],
            ],
            [
                'id' => 'ID.AM-04',
                'title' => 'External information systems',
                'description' => 'External information systems are catalogued.',
                'category' => 'Identify - Asset Management',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.19', '5.23']],
            ],
            [
                'id' => 'ID.AM-05',
                'title' => 'Asset prioritization',
                'description' => 'Resources are prioritized based on their classification, criticality, and business value.',
                'category' => 'Identify - Asset Management',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.9', '5.12']],
            ],
            [
                'id' => 'ID.RA-01',
                'title' => 'Asset vulnerabilities',
                'description' => 'Asset vulnerabilities are identified and documented.',
                'category' => 'Identify - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],
            [
                'id' => 'ID.RA-02',
                'title' => 'Cyber threat intelligence',
                'description' => 'Cyber threat intelligence is received from information sharing forums and sources.',
                'category' => 'Identify - Risk Assessment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.7']],
            ],
            [
                'id' => 'ID.RA-03',
                'title' => 'Internal and external threats',
                'description' => 'Threats, both internal and external, are identified and documented.',
                'category' => 'Identify - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.7']],
            ],
            [
                'id' => 'ID.RA-04',
                'title' => 'Impact of potential events',
                'description' => 'Potential business impacts and likelihoods are identified.',
                'category' => 'Identify - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.29']],
            ],

            // PROTECT (PR)
            [
                'id' => 'PR.AA-01',
                'title' => 'Identity management',
                'description' => 'Identities and credentials are issued, managed, verified, revoked, and audited.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.16', '5.17']],
            ],
            [
                'id' => 'PR.AA-02',
                'title' => 'Physical access management',
                'description' => 'Physical access to assets is managed and protected.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['7.1', '7.2']],
            ],
            [
                'id' => 'PR.AA-03',
                'title' => 'Remote access management',
                'description' => 'Remote access is managed.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['6.7']],
            ],
            [
                'id' => 'PR.AA-04',
                'title' => 'Access permissions',
                'description' => 'Access permissions and authorizations are managed, incorporating the principles of least privilege and separation of duties.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.15', '5.18', '8.2', '8.3']],
            ],
            [
                'id' => 'PR.AA-05',
                'title' => 'Network integrity',
                'description' => 'Network integrity is protected.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.21', '8.22']],
            ],
            [
                'id' => 'PR.AA-06',
                'title' => 'Multi-factor authentication',
                'description' => 'Multi-factor authentication is used.',
                'category' => 'Protect - Identity Management & Access Control',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.5']],
            ],
            [
                'id' => 'PR.AT-01',
                'title' => 'Security awareness training',
                'description' => 'All users are informed and trained.',
                'category' => 'Protect - Awareness & Training',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['6.3']],
            ],
            [
                'id' => 'PR.AT-02',
                'title' => 'Privileged users training',
                'description' => 'Privileged users understand their roles and responsibilities.',
                'category' => 'Protect - Awareness & Training',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['6.3']],
            ],
            [
                'id' => 'PR.DS-01',
                'title' => 'Data-at-rest protection',
                'description' => 'Data-at-rest is protected.',
                'category' => 'Protect - Data Security',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.24']],
            ],
            [
                'id' => 'PR.DS-02',
                'title' => 'Data-in-transit protection',
                'description' => 'Data-in-transit is protected.',
                'category' => 'Protect - Data Security',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.24']],
            ],
            [
                'id' => 'PR.DS-03',
                'title' => 'Asset management',
                'description' => 'Assets are formally managed throughout removal, transfers, and disposition.',
                'category' => 'Protect - Data Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.11', '7.14']],
            ],
            [
                'id' => 'PR.DS-04',
                'title' => 'Adequate capacity',
                'description' => 'Adequate capacity to ensure availability is maintained.',
                'category' => 'Protect - Data Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.6', '8.14']],
            ],
            [
                'id' => 'PR.DS-05',
                'title' => 'Data leak prevention',
                'description' => 'Protections against data leaks are implemented.',
                'category' => 'Protect - Data Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.12']],
            ],
            [
                'id' => 'PR.DS-06',
                'title' => 'Integrity checking',
                'description' => 'Integrity checking mechanisms are used to verify software, firmware, and information integrity.',
                'category' => 'Protect - Data Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.29']],
            ],
            [
                'id' => 'PR.DS-07',
                'title' => 'Development and testing environments',
                'description' => 'The development and testing environment(s) are separate from the production environment.',
                'category' => 'Protect - Data Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.31']],
            ],
            [
                'id' => 'PR.DS-08',
                'title' => 'Hardware integrity',
                'description' => 'Integrity checking mechanisms are used to verify hardware integrity.',
                'category' => 'Protect - Data Security',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['7.13']],
            ],
            [
                'id' => 'PR.PS-01',
                'title' => 'Configuration management',
                'description' => 'Configuration management processes are in place.',
                'category' => 'Protect - Platform Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.9', '8.32']],
            ],
            [
                'id' => 'PR.PS-02',
                'title' => 'Secure baseline configurations',
                'description' => 'Secure baseline configurations are developed and maintained.',
                'category' => 'Protect - Platform Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],
            [
                'id' => 'PR.PS-03',
                'title' => 'Backups',
                'description' => 'Information and records are backed up.',
                'category' => 'Protect - Platform Security',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.13']],
            ],
            [
                'id' => 'PR.PS-04',
                'title' => 'Vulnerability remediation',
                'description' => 'Vulnerability remediation processes are in place.',
                'category' => 'Protect - Platform Security',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],
            [
                'id' => 'PR.PS-05',
                'title' => 'Software installation restrictions',
                'description' => 'Installation of software is restricted and managed.',
                'category' => 'Protect - Platform Security',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.19']],
            ],
            [
                'id' => 'PR.PS-06',
                'title' => 'Malware protection',
                'description' => 'Protections against malicious code are implemented and maintained.',
                'category' => 'Protect - Platform Security',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.7']],
            ],

            // DETECT (DE)
            [
                'id' => 'DE.AE-01',
                'title' => 'Network baseline',
                'description' => 'A baseline of network operations and expected data flows is established and managed.',
                'category' => 'Detect - Anomalies & Events',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.16']],
            ],
            [
                'id' => 'DE.AE-02',
                'title' => 'Event analysis',
                'description' => 'Detected events are analyzed to understand attack targets and methods.',
                'category' => 'Detect - Anomalies & Events',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.25', '8.16']],
            ],
            [
                'id' => 'DE.AE-03',
                'title' => 'Event correlation',
                'description' => 'Event data are collected and correlated from multiple sources and sensors.',
                'category' => 'Detect - Anomalies & Events',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            [
                'id' => 'DE.CM-01',
                'title' => 'Network monitoring',
                'description' => 'Networks are monitored to detect potential cybersecurity events.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.16', '8.20']],
            ],
            [
                'id' => 'DE.CM-02',
                'title' => 'Physical environment monitoring',
                'description' => 'The physical environment is monitored to detect potential cybersecurity events.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['7.4']],
            ],
            [
                'id' => 'DE.CM-03',
                'title' => 'Personnel activity monitoring',
                'description' => 'Personnel activity is monitored to detect potential cybersecurity events.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.15']],
            ],
            [
                'id' => 'DE.CM-04',
                'title' => 'Malicious code detection',
                'description' => 'Malicious code is detected.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.7']],
            ],
            [
                'id' => 'DE.CM-05',
                'title' => 'Unauthorized access detection',
                'description' => 'Unauthorized access is detected.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            [
                'id' => 'DE.CM-06',
                'title' => 'External service provider monitoring',
                'description' => 'External service provider activity is monitored to detect potential cybersecurity events.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.22']],
            ],
            [
                'id' => 'DE.CM-07',
                'title' => 'Unauthorized changes detection',
                'description' => 'Monitoring for unauthorized personnel, connections, devices, and software is performed.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.16']],
            ],
            [
                'id' => 'DE.CM-08',
                'title' => 'Vulnerability scans',
                'description' => 'Vulnerability scans are performed.',
                'category' => 'Detect - Continuous Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],

            // RESPOND (RS)
            [
                'id' => 'RS.MA-01',
                'title' => 'Incident response plan',
                'description' => 'The incident response plan is executed during or after an incident.',
                'category' => 'Respond - Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.26']],
            ],
            [
                'id' => 'RS.MA-02',
                'title' => 'Incident reporting',
                'description' => 'Incidents are reported consistent with established criteria.',
                'category' => 'Respond - Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['6.8', '5.25']],
            ],
            [
                'id' => 'RS.MA-03',
                'title' => 'Incident classification',
                'description' => 'Information is shared consistent with response plans.',
                'category' => 'Respond - Incident Management',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.25']],
            ],
            [
                'id' => 'RS.MA-04',
                'title' => 'Incident coordination',
                'description' => 'Coordination with stakeholders occurs consistent with response plans.',
                'category' => 'Respond - Incident Management',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.26']],
            ],
            [
                'id' => 'RS.MA-05',
                'title' => 'External support',
                'description' => 'External support from law enforcement agencies is sought when appropriate.',
                'category' => 'Respond - Incident Management',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.5']],
            ],
            [
                'id' => 'RS.AN-01',
                'title' => 'Impact analysis',
                'description' => 'Notifications from detection systems are investigated.',
                'category' => 'Respond - Incident Analysis',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.25', '5.26']],
            ],
            [
                'id' => 'RS.AN-02',
                'title' => 'Incident impact assessment',
                'description' => 'The impact of the incident is understood.',
                'category' => 'Respond - Incident Analysis',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.25']],
            ],
            [
                'id' => 'RS.AN-03',
                'title' => 'Forensics',
                'description' => 'Forensics are performed.',
                'category' => 'Respond - Incident Analysis',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.28']],
            ],
            [
                'id' => 'RS.MI-01',
                'title' => 'Incident containment',
                'description' => 'Incidents are contained.',
                'category' => 'Respond - Incident Mitigation',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.26']],
            ],
            [
                'id' => 'RS.MI-02',
                'title' => 'Incident mitigation',
                'description' => 'Incidents are mitigated.',
                'category' => 'Respond - Incident Mitigation',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.26']],
            ],
            [
                'id' => 'RS.IM-01',
                'title' => 'Lessons learned',
                'description' => 'Response plans incorporate lessons learned.',
                'category' => 'Respond - Incident Recovery',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.27']],
            ],
            [
                'id' => 'RS.IM-02',
                'title' => 'Response plan updates',
                'description' => 'Response strategies are updated.',
                'category' => 'Respond - Incident Recovery',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.27']],
            ],

            // RECOVER (RC)
            [
                'id' => 'RC.RP-01',
                'title' => 'Recovery plan execution',
                'description' => 'Recovery plan is executed during or after a cybersecurity incident.',
                'category' => 'Recover - Recovery Planning',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.29', '5.30']],
            ],
            [
                'id' => 'RC.CO-01',
                'title' => 'Public relations management',
                'description' => 'Public relations are managed.',
                'category' => 'Recover - Communications',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.26']],
            ],
            [
                'id' => 'RC.CO-02',
                'title' => 'Reputation management',
                'description' => 'Reputation is repaired after an incident.',
                'category' => 'Recover - Communications',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.26', '5.27']],
            ],
            [
                'id' => 'RC.CO-03',
                'title' => 'Recovery communication',
                'description' => 'Recovery activities are communicated to internal and external stakeholders.',
                'category' => 'Recover - Communications',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.26']],
            ],
        ];
    }
}
