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
    name: 'app:load-cis-controls-requirements',
    description: 'Load CIS Controls v8 with ISO 27001 control mappings'
)]
class LoadCisControlsRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get CIS Controls framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'CIS-CONTROLS']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('CIS-CONTROLS')
                ->setName('CIS Controls v8')
                ->setDescription('Center for Internet Security Critical Security Controls')
                ->setVersion('8')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('CIS (Center for Internet Security)')
                ->setMandatory(false)
                ->setScopeDescription('Best practices for cybersecurity defense with prioritized set of actions')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getCisControlsRequirements();

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

        $io->success(sprintf('Successfully loaded %d CIS Controls v8 requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getCisControlsRequirements(): array
    {
        return [
            // Control 1: Inventory and Control of Enterprise Assets
            [
                'id' => 'CIS-1',
                'title' => 'Inventory and Control of Enterprise Assets',
                'description' => 'Actively manage (inventory, track, and correct) all enterprise assets (end-user devices, including portable and mobile; network devices; non-computing/IoT devices; and servers) connected to the infrastructure physically, virtually, remotely, and those within cloud environments, to accurately know the totality of assets that need to be monitored and protected within the enterprise.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9'], 'asset_types' => ['hardware', 'network']],
            ],
            [
                'id' => 'CIS-1.1',
                'title' => 'Establish and Maintain Asset Inventory',
                'description' => 'Establish and maintain an accurate, detailed, and up-to-date inventory of all enterprise assets with the potential to store or process data.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9']],
            ],

            // Control 2: Inventory and Control of Software Assets
            [
                'id' => 'CIS-2',
                'title' => 'Inventory and Control of Software Assets',
                'description' => 'Actively manage (inventory, track, and correct) all software (operating systems and applications) on the network so that only authorized software is installed and can execute, and that unauthorized and unmanaged software is found and prevented from installation or execution.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9', '8.19'], 'asset_types' => ['software']],
            ],
            [
                'id' => 'CIS-2.1',
                'title' => 'Establish and Maintain Software Inventory',
                'description' => 'Establish and maintain a detailed inventory of all licensed software installed on enterprise assets.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.9'], 'asset_types' => ['software']],
            ],

            // Control 3: Data Protection
            [
                'id' => 'CIS-3',
                'title' => 'Data Protection',
                'description' => 'Develop processes and technical controls to identify, classify, securely handle, retain, and dispose of data.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.12', '5.13', '5.33', '8.10', '8.11']],
            ],
            [
                'id' => 'CIS-3.1',
                'title' => 'Establish and Maintain Data Management Process',
                'description' => 'Establish and maintain a data management process that includes data sensitivity classification.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.12', '5.13']],
            ],
            [
                'id' => 'CIS-3.6',
                'title' => 'Encrypt Data on End-User Devices',
                'description' => 'Encrypt data on end-user devices containing sensitive data.',
                'category' => 'Basic CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.24']],
            ],

            // Control 4: Secure Configuration of Enterprise Assets and Software
            [
                'id' => 'CIS-4',
                'title' => 'Secure Configuration of Enterprise Assets and Software',
                'description' => 'Establish and maintain the secure configuration of enterprise assets (end-user devices, including portable and mobile; network devices; non-computing/IoT devices; and servers) and software (operating systems and applications).',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],
            [
                'id' => 'CIS-4.1',
                'title' => 'Establish and Maintain Secure Configuration Process',
                'description' => 'Establish and maintain a secure configuration process for enterprise assets and software.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],

            // Control 5: Account Management
            [
                'id' => 'CIS-5',
                'title' => 'Account Management',
                'description' => 'Use processes and tools to assign and manage authorization to credentials for user accounts, including administrator accounts, as well as service accounts, to enterprise assets and software.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.16', '5.17', '5.18']],
            ],
            [
                'id' => 'CIS-5.1',
                'title' => 'Establish and Maintain Inventory of Accounts',
                'description' => 'Establish and maintain an inventory of all accounts managed in the enterprise.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.16']],
            ],
            [
                'id' => 'CIS-5.2',
                'title' => 'Use Unique Passwords',
                'description' => 'Use unique passwords for all enterprise assets.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.17', '8.5']],
            ],
            [
                'id' => 'CIS-5.3',
                'title' => 'Disable Dormant Accounts',
                'description' => 'Delete or disable any dormant accounts after a period of 45 days of inactivity.',
                'category' => 'Basic CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.18']],
            ],
            [
                'id' => 'CIS-5.4',
                'title' => 'Restrict Administrator Privileges',
                'description' => 'Restrict administrator privileges to dedicated administrator accounts on enterprise assets.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.2']],
            ],

            // Control 6: Access Control Management
            [
                'id' => 'CIS-6',
                'title' => 'Access Control Management',
                'description' => 'Use processes and tools to create, assign, manage, and revoke access credentials and privileges for user, administrator, and service accounts for enterprise assets and software.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.15', '5.18', '8.2', '8.3']],
            ],
            [
                'id' => 'CIS-6.1',
                'title' => 'Establish Access Granting Process',
                'description' => 'Establish and follow a process for granting access to enterprise assets.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.15', '5.18']],
            ],
            [
                'id' => 'CIS-6.2',
                'title' => 'Establish Access Revoking Process',
                'description' => 'Establish and follow a process for revoking access to enterprise assets.',
                'category' => 'Basic CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.18']],
            ],

            // Control 7: Continuous Vulnerability Management
            [
                'id' => 'CIS-7',
                'title' => 'Continuous Vulnerability Management',
                'description' => 'Develop a plan to continuously assess and track vulnerabilities on all enterprise assets within the enterprise\'s infrastructure, in order to remediate, and minimize, the window of opportunity for attackers.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],
            [
                'id' => 'CIS-7.1',
                'title' => 'Establish Vulnerability Management Process',
                'description' => 'Establish and maintain a vulnerability management process.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],
            [
                'id' => 'CIS-7.4',
                'title' => 'Perform Automated Vulnerability Scans',
                'description' => 'Perform automated vulnerability scans of internal enterprise assets on a quarterly, or more frequent, basis.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.8']],
            ],

            // Control 8: Audit Log Management
            [
                'id' => 'CIS-8',
                'title' => 'Audit Log Management',
                'description' => 'Collect, alert, review, and retain audit logs of events that could help detect, understand, or recover from an attack.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.15']],
            ],
            [
                'id' => 'CIS-8.2',
                'title' => 'Collect Audit Logs',
                'description' => 'Collect audit logs from enterprise assets.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.15']],
            ],
            [
                'id' => 'CIS-8.3',
                'title' => 'Ensure Adequate Audit Log Storage',
                'description' => 'Ensure that logging destinations maintain adequate storage to comply with the enterprise\'s audit log management process.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.15']],
            ],

            // Control 9: Email and Web Browser Protections
            [
                'id' => 'CIS-9',
                'title' => 'Email and Web Browser Protections',
                'description' => 'Improve protections and detections of threats from email and web vectors, as these are opportunities for attackers to manipulate human behavior through direct engagement.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.7', '8.23']],
            ],
            [
                'id' => 'CIS-9.2',
                'title' => 'Block Malicious Email Attachments',
                'description' => 'Block unnecessary file types attempting to enter the enterprise\'s email gateway.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.7']],
            ],

            // Control 10: Malware Defenses
            [
                'id' => 'CIS-10',
                'title' => 'Malware Defenses',
                'description' => 'Prevent or control the installation, spread, and execution of malicious applications, code, or scripts on enterprise assets.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.7']],
            ],
            [
                'id' => 'CIS-10.1',
                'title' => 'Deploy Anti-Malware Software',
                'description' => 'Deploy and maintain anti-malware software on all enterprise assets.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.7']],
            ],

            // Control 11: Data Recovery
            [
                'id' => 'CIS-11',
                'title' => 'Data Recovery',
                'description' => 'Establish and maintain data recovery practices sufficient to restore in-scope enterprise assets to a pre-incident and trusted state.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.13', '8.14']],
            ],
            [
                'id' => 'CIS-11.1',
                'title' => 'Establish Data Recovery Process',
                'description' => 'Establish and maintain a data recovery process.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.13', '5.30']],
            ],
            [
                'id' => 'CIS-11.2',
                'title' => 'Perform Automated Backups',
                'description' => 'Perform automated backups of in-scope enterprise assets.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.13']],
            ],

            // Control 12: Network Infrastructure Management
            [
                'id' => 'CIS-12',
                'title' => 'Network Infrastructure Management',
                'description' => 'Establish, implement, and actively manage (track, report, correct) network devices, in order to prevent attackers from exploiting vulnerable network services and access points.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.21', '8.22']],
            ],
            [
                'id' => 'CIS-12.4',
                'title' => 'Deny Communication Over Unauthorized Ports',
                'description' => 'Deny communication over unauthorized TCP or UDP ports or application traffic.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.22']],
            ],

            // Control 13: Network Monitoring and Defense
            [
                'id' => 'CIS-13',
                'title' => 'Network Monitoring and Defense',
                'description' => 'Operate processes and tooling to establish and maintain comprehensive network monitoring and defense against security threats across the enterprise\'s network infrastructure and user base.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.16', '8.20']],
            ],
            [
                'id' => 'CIS-13.1',
                'title' => 'Centralize Security Event Alerting',
                'description' => 'Centralize security event alerting across enterprise assets for log correlation and analysis.',
                'category' => 'Foundational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],

            // Control 14: Security Awareness and Skills Training
            [
                'id' => 'CIS-14',
                'title' => 'Security Awareness and Skills Training',
                'description' => 'Establish and maintain a security awareness program to influence behavior among the workforce to be security conscious and properly skilled to reduce cybersecurity risks to the enterprise.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['6.3']],
            ],
            [
                'id' => 'CIS-14.1',
                'title' => 'Establish Security Awareness Program',
                'description' => 'Establish and maintain a security awareness program.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['6.3']],
            ],

            // Control 15: Service Provider Management
            [
                'id' => 'CIS-15',
                'title' => 'Service Provider Management',
                'description' => 'Develop a process to evaluate service providers who hold sensitive data, or are responsible for an enterprise\'s critical IT platforms or processes, to ensure these providers are protecting those platforms and data appropriately.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.19', '5.20', '5.21', '5.22']],
            ],
            [
                'id' => 'CIS-15.1',
                'title' => 'Establish Service Provider Inventory',
                'description' => 'Establish and maintain an inventory of service providers.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.19']],
            ],

            // Control 16: Application Software Security
            [
                'id' => 'CIS-16',
                'title' => 'Application Software Security',
                'description' => 'Manage the security life cycle of in-house developed, hosted, or acquired software to prevent, detect, and remediate security weaknesses before they can impact the enterprise.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.25', '8.26', '8.27', '8.28', '8.29']],
            ],
            [
                'id' => 'CIS-16.1',
                'title' => 'Establish Secure Application Development',
                'description' => 'Establish and maintain a secure application development process.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.25', '8.26', '8.27']],
            ],
            [
                'id' => 'CIS-16.6',
                'title' => 'Secure Application Deployment',
                'description' => 'Establish and maintain a process for application deployment.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.31', '8.32']],
            ],

            // Control 17: Incident Response Management
            [
                'id' => 'CIS-17',
                'title' => 'Incident Response Management',
                'description' => 'Establish a program to develop and maintain an incident response capability to discover and respond to attacks in progress.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.25', '5.26', '5.27', '5.28']],
            ],
            [
                'id' => 'CIS-17.1',
                'title' => 'Establish Incident Response Plan',
                'description' => 'Establish and maintain an incident response plan.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.26']],
            ],

            // Control 18: Penetration Testing
            [
                'id' => 'CIS-18',
                'title' => 'Penetration Testing',
                'description' => 'Test the effectiveness and resiliency of enterprise assets through identifying and exploiting weaknesses in controls (people, processes, and technology), and simulating the objectives and actions of an attacker.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.35', '8.29']],
            ],
            [
                'id' => 'CIS-18.1',
                'title' => 'Establish Penetration Testing Program',
                'description' => 'Establish and maintain a penetration testing program.',
                'category' => 'Organizational CIS Controls',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.35', '8.29']],
            ],
        ];
    }
}
