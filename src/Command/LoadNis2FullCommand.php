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
 * Loads the full NIS2 Directive (Directive EU 2022/2555) Article catalogue
 * plus the per-letter sub-points of Article 21(2) (a–j) that the cross-
 * framework mappings reference. Source: NIS2 Directive consolidated text +
 * ENISA Implementation Guidance NIS2 Technical and Methodological
 * Requirements (2024-09).
 */
#[AsCommand(
    name: 'app:load-nis2-full',
    description: 'Load NIS2 Directive (Directive EU 2022/2555) Articles 1-46 + Art. 21(2)(a-j) sub-points + Art. 23 reporting timeline as ComplianceRequirement rows.'
)]
final class LoadNis2FullCommand extends Command
{
    /** @var array<string, string> */
    private const REQUIREMENTS = [
        // Chapter I — General provisions
        'Art.1'  => 'Subject matter and scope',
        'Art.2'  => 'Scope (essential and important entities)',
        'Art.3'  => 'Definitions of essential and important entities',
        'Art.4'  => 'Sector-specific Union legal acts',
        'Art.5'  => 'Minimum harmonisation',
        // Chapter II — Coordinated cybersecurity frameworks
        'Art.6'  => 'National cybersecurity strategy',
        'Art.7'  => 'Competent authorities + national single points of contact',
        'Art.8'  => 'Computer security incident response teams (CSIRTs)',
        'Art.9'  => 'Requirements, technical capabilities and tasks of CSIRTs',
        'Art.10' => 'Cooperation at national level',
        'Art.11' => 'Cooperation Group',
        'Art.12' => 'CSIRTs network',
        'Art.13' => 'European cyber crises liaison organisation network (EU-CyCLONe)',
        'Art.14' => 'Peer reviews of national cybersecurity capabilities',
        'Art.15' => 'European cybersecurity certification schemes',
        'Art.16' => 'Cybersecurity standardisation',
        'Art.17' => 'Voluntary information-sharing arrangements',
        'Art.18' => 'Notification of major incidents and significant cyber threats by relevant entities',
        'Art.19' => 'Coordinated security risk assessments of critical supply chains',
        // Chapter IV — Cybersecurity risk-management measures and reporting obligations
        'Art.20'   => 'Governance — management body accountability',
        'Art.20.1' => 'Management bodies approve and oversee implementation of risk-management measures',
        'Art.20.2' => 'Management bodies follow training (extended to all personnel offer)',
        'Art.21'   => 'Cybersecurity risk-management measures',
        'Art.21.1' => 'Take appropriate and proportionate technical, operational and organisational measures',
        'Art.21.2.a' => 'Policies on risk analysis and information system security',
        'Art.21.2.b' => 'Incident handling',
        'Art.21.2.c' => 'Business continuity, including backup management and disaster recovery, and crisis management',
        'Art.21.2.d' => 'Supply chain security',
        'Art.21.2.e' => 'Security in network and information systems acquisition, development and maintenance',
        'Art.21.2.f' => 'Policies and procedures to assess the effectiveness of cybersecurity risk-management measures',
        'Art.21.2.g' => 'Basic cyber hygiene practices and cybersecurity training',
        'Art.21.2.h' => 'Policies and procedures regarding the use of cryptography and, where appropriate, encryption',
        'Art.21.2.i' => 'Human resources security, access control policies and asset management',
        'Art.21.2.j' => 'Use of multi-factor authentication or continuous authentication, secured voice/video/text and emergency communication systems',
        'Art.21.3'   => 'Take into account state of the art and EU cybersecurity certification',
        'Art.21.4'   => 'Without delay, take measures when minor non-compliance found',
        'Art.21.5'   => 'Implementing acts laying down technical and methodological requirements',
        'Art.22'     => 'Union-level coordinated security risk assessments of critical supply chains',
        'Art.23'     => 'Reporting obligations',
        'Art.23.1'   => 'Notify any incident having significant impact (significance criteria)',
        'Art.23.2'   => 'Early warning within 24 hours of becoming aware (suspected unlawful or malicious act / cross-border)',
        'Art.23.3'   => 'Incident notification within 72 hours (assessment, indicators of compromise, type of threat)',
        'Art.23.4'   => 'Final report within 1 month (root cause, mitigation, cross-border impact)',
        'Art.23.5'   => 'Progress report every month if incident still ongoing',
        'Art.23.6'   => 'Communications between entity and CSIRT/competent authority',
        // Chapter V — Jurisdiction and registration
        'Art.24' => 'Use of European cybersecurity certification schemes',
        'Art.25' => 'Standardisation',
        'Art.26' => 'Jurisdiction and territoriality',
        'Art.27' => 'Registry of entities',
        'Art.28' => 'Database of domain name registration data',
        // Chapter VI — Information sharing
        'Art.29' => 'Cybersecurity information-sharing arrangements',
        'Art.30' => 'Voluntary notification of relevant information',
        // Chapter VII — Supervision and enforcement
        'Art.31' => 'General supervision (essential entities)',
        'Art.32' => 'Supervisory and enforcement measures in relation to essential entities',
        'Art.33' => 'Supervisory and enforcement measures in relation to important entities',
        'Art.34' => 'General conditions for imposing administrative fines',
        'Art.35' => 'Infringements entailing personal data breach',
        'Art.36' => 'Penalties',
        'Art.37' => 'Mutual assistance',
        // Chapter VIII — Delegated and implementing acts
        'Art.38' => 'Exercise of the delegation',
        'Art.39' => 'Committee procedure',
        // Chapter IX — Final provisions
        'Art.40' => 'Review',
        'Art.41' => 'Transposition',
        'Art.42' => 'Amendment of Regulation (EU) No 910/2014',
        'Art.43' => 'Amendment of Directive (EU) 2018/1972',
        'Art.44' => 'Repeal',
        'Art.45' => 'Entry into force',
        'Art.46' => 'Addressees',
        // Annexes
        'Annex-I'  => 'Sectors of high criticality (Energy, Transport, Banking, FMI, Health, Water, Digital infrastructure, ICT service mgmt, Public administration, Space)',
        'Annex-II' => 'Other critical sectors (Postal/courier, Waste mgmt, Manufacture, Production/processing/distribution of food, Manufacture of medical devices, etc.)',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'NIS2']);
        if ($framework === null) {
            $io->error('Framework NIS2 not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::REQUIREMENTS as $reqId => $title) {
            $category = match (true) {
                str_starts_with($reqId, 'Art.21.2') => 'Art.21(2) Risk-Mgmt-Measures',
                str_starts_with($reqId, 'Art.23')   => 'Art.23 Reporting',
                str_starts_with($reqId, 'Art.20')   => 'Art.20 Governance',
                str_starts_with($reqId, 'Annex')    => 'Annex (Sectors)',
                default                              => 'Articles',
            };
            $priority = match (true) {
                str_starts_with($reqId, 'Art.20'), str_starts_with($reqId, 'Art.21'), str_starts_with($reqId, 'Art.23') => 'high',
                default => 'medium',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority($priority);
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('NIS2 Directive (EU 2022/2555) / %s — %s. Quelle: Richtlinie (EU) 2022/2555 + ENISA Implementation Guidance + BSI NIS2-UmsuCG.', $reqId, $title));
            $req->setCategory($category);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('NIS2 Directive: %d created, %d updated. Total: %d.', $created, $updated, count(self::REQUIREMENTS)));
        return Command::SUCCESS;
    }
}
