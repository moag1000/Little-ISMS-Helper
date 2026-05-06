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
 * Loads PCI-DSS v4.0.1 requirements: 12 top-level requirements with their
 * key sub-requirement headings (~64 entries). Full v4.0.1 has more granular
 * sub-controls; this loader covers the top levels needed for cross-framework
 * mapping resolution.
 */
#[AsCommand(
    name: 'app:load-pci-dss-401-full',
    description: 'Load PCI-DSS v4.0.1 (12 main requirements + key sub-requirements) as ComplianceRequirement rows.'
)]
final class LoadPciDss401FullCommand extends Command
{
    /** @var array<string, string> */
    private const REQUIREMENTS = [
        // Req 1
        '1'   => 'Install and Maintain Network Security Controls',
        '1.1' => 'Processes and mechanisms for installing and maintaining network security controls',
        '1.2' => 'Network security controls (NSCs) are configured and maintained',
        '1.3' => 'Network access to and from the cardholder data environment is restricted',
        '1.4' => 'Network connections between trusted and untrusted networks are controlled',
        '1.5' => 'Risks to the CDE from computing devices that connect to both untrusted and trusted networks are mitigated',
        // Req 2
        '2'   => 'Apply Secure Configurations to All System Components',
        '2.1' => 'Processes and mechanisms for applying secure configurations',
        '2.2' => 'System components are configured and managed securely',
        '2.3' => 'Wireless environments are configured and managed securely',
        // Req 3
        '3'   => 'Protect Stored Account Data',
        '3.1' => 'Processes and mechanisms for protecting stored account data',
        '3.2' => 'Storage of account data is kept to a minimum',
        '3.3' => 'Sensitive authentication data (SAD) is not stored after authorization',
        '3.4' => 'Access to displays of full PAN and ability to copy PAN are restricted',
        '3.5' => 'PAN is rendered unreadable wherever stored',
        '3.6' => 'Cryptographic keys used to protect stored account data are secured',
        '3.7' => 'Where cryptography is used to protect stored account data, key management processes are defined',
        // Req 4
        '4'   => 'Protect Cardholder Data with Strong Cryptography During Transmission',
        '4.1' => 'Processes and mechanisms for protecting cardholder data with strong cryptography',
        '4.2' => 'PAN is protected with strong cryptography during transmission',
        // Req 5
        '5'   => 'Protect All Systems and Networks from Malicious Software',
        '5.1' => 'Processes and mechanisms for protecting all systems and networks from malicious software',
        '5.2' => 'Malicious software is prevented or detected and addressed',
        '5.3' => 'Anti-malware mechanisms and processes are active, maintained, monitored',
        '5.4' => 'Anti-phishing mechanisms protect users against phishing attacks',
        // Req 6
        '6'   => 'Develop and Maintain Secure Systems and Software',
        '6.1' => 'Processes and mechanisms for developing and maintaining secure systems',
        '6.2' => 'Bespoke and custom software are developed securely',
        '6.3' => 'Security vulnerabilities are identified and addressed',
        '6.4' => 'Public-facing web applications are protected against attacks',
        '6.5' => 'Changes to all system components are managed securely',
        // Req 7
        '7'   => 'Restrict Access to System Components and Cardholder Data by Business Need to Know',
        '7.1' => 'Processes and mechanisms for restricting access',
        '7.2' => 'Access is appropriately defined and assigned',
        '7.3' => 'Access to system components and data is managed via an access control system',
        // Req 8
        '8'   => 'Identify Users and Authenticate Access to System Components',
        '8.1' => 'Processes and mechanisms for identifying users and authenticating',
        '8.2' => 'User identification and related accounts are strictly managed',
        '8.3' => 'Strong authentication for users and administrators is established',
        '8.4' => 'Multi-factor authentication is implemented to secure access',
        '8.5' => 'MFA systems are configured to prevent misuse',
        '8.6' => 'Use of application and system accounts and associated authentication factors is strictly managed',
        // Req 9
        '9'   => 'Restrict Physical Access to Cardholder Data',
        '9.1' => 'Processes and mechanisms for restricting physical access',
        '9.2' => 'Physical access controls manage entry into facilities',
        '9.3' => 'Physical access for personnel and visitors is authorised and managed',
        '9.4' => 'Media with cardholder data is securely stored, accessed, distributed, destroyed',
        '9.5' => 'POI devices are protected from tampering and unauthorised substitution',
        // Req 10
        '10'   => 'Log and Monitor All Access to System Components and Cardholder Data',
        '10.1' => 'Processes and mechanisms for logging and monitoring',
        '10.2' => 'Audit logs are implemented to support detection',
        '10.3' => 'Audit logs are protected from destruction and unauthorised modifications',
        '10.4' => 'Audit logs are reviewed to identify anomalies and suspicious activity',
        '10.5' => 'Audit log history is retained',
        '10.6' => 'Time synchronisation mechanisms support consistent time',
        '10.7' => 'Failures of critical security control systems are detected, reported, responded to',
        // Req 11
        '11'   => 'Test Security of Systems and Networks Regularly',
        '11.1' => 'Processes and mechanisms for regularly testing security',
        '11.2' => 'Wireless access points are identified and addressed',
        '11.3' => 'External and internal vulnerabilities are regularly identified and managed',
        '11.4' => 'External and internal penetration testing is regularly performed',
        '11.5' => 'Network intrusions and unexpected file changes are detected',
        '11.6' => 'Unauthorised changes on payment pages are detected and responded to',
        // Req 12
        '12'   => 'Support Information Security with Organizational Policies and Programs',
        '12.1' => 'A comprehensive information security policy is established and maintained',
        '12.2' => 'Acceptable use policies for end-user technologies are defined and implemented',
        '12.3' => 'Risks to the cardholder data environment are formally identified, evaluated, managed',
        '12.4' => 'PCI DSS compliance is managed',
        '12.5' => 'PCI DSS scope is documented and validated',
        '12.6' => 'Security awareness training is implemented and maintained',
        '12.7' => 'Personnel are screened to reduce risks from insider threats',
        '12.8' => 'Risk to information assets associated with third-party service providers is managed',
        '12.9' => 'Third-party service providers support customers\' PCI DSS compliance',
        '12.10' => 'Suspected and confirmed security incidents are responded to immediately',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'PCI-DSS-4.0.1']);
        if ($framework === null) {
            $io->error('Framework PCI-DSS-4.0.1 not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::REQUIREMENTS as $reqId => $title) {
            $reqId = (string) $reqId;
            $isTopLevel = !str_contains($reqId, '.');
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType($isTopLevel ? 'core' : 'detailed');
                $req->setPriority($isTopLevel ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle($title);
            $req->setDescription(sprintf('PCI-DSS v4.0.1 / Req %s — %s. Quelle: PCI Security Standards Council, Payment Card Industry Data Security Standard v4.0.1 (June 2024).', $reqId, $title));
            $topLevel = explode('.', $reqId)[0];
            $req->setCategory(sprintf('Req-%s', $topLevel));
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('PCI-DSS v4.0.1: %d created, %d updated. Total: %d.', $created, $updated, count(self::REQUIREMENTS)));
        return Command::SUCCESS;
    }
}
