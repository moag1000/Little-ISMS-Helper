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
 * Loads the full DORA Regulation (EU 2022/2554) Article catalogue (Art. 1-64)
 * with the key sub-paragraphs the cross-framework mappings reference.
 * Identifier convention: 'Art.N' for top-level, 'Art.N.M' for sub-paragraphs.
 */
#[AsCommand(
    name: 'app:load-dora-full',
    description: 'Load DORA (Regulation EU 2022/2554) Articles 1-64 + key sub-paragraphs as ComplianceRequirement rows.'
)]
final class LoadDoraFullCommand extends Command
{
    /** @var array<string, string> */
    private const REQUIREMENTS = [
        // Chapter I — General provisions
        'Art.1'  => 'Subject matter',
        'Art.2'  => 'Scope (financial entities)',
        'Art.3'  => 'Definitions',
        'Art.4'  => 'Proportionality principle',
        // Chapter II — ICT risk management
        'Art.5'   => 'Governance and organisation (Section I — ICT risk management framework)',
        'Art.5.4' => 'Management body — ICT-specific training and adequate ICT knowledge',
        'Art.6'   => 'ICT risk management framework',
        'Art.6.5' => 'Internal audit of the ICT risk management framework (independent audit function)',
        'Art.7'   => 'ICT systems, protocols and tools',
        'Art.8'   => 'Identification (ICT risks, assets, dependencies)',
        'Art.9'   => 'Protection and prevention',
        'Art.10'  => 'Detection',
        'Art.11'  => 'Response and recovery',
        'Art.12'  => 'Backup policies and procedures, restoration and recovery procedures and methods',
        'Art.13'  => 'Learning and evolving',
        'Art.13.6' => 'Cybersecurity awareness programmes and digital operational resilience training',
        'Art.14'  => 'Communication (internal + external)',
        'Art.15'  => 'Further harmonisation of ICT risk management tools, methods, processes and policies',
        'Art.16'  => 'Simplified ICT risk management framework (smaller financial entities)',
        // Chapter III — ICT-related incident management, classification and reporting
        'Art.17'  => 'ICT-related incident management process',
        'Art.18'  => 'Classification of ICT-related incidents and cyber threats',
        'Art.19'  => 'Reporting of major ICT-related incidents and voluntary notification of significant cyber threats',
        'Art.19.4' => 'Initial / intermediate / final reports — 4h initial / 72h intermediate / 1-month final',
        'Art.20'  => 'Harmonisation of reporting content and templates',
        'Art.21'  => 'Centralisation of reporting (single EU hub)',
        'Art.22'  => 'Supervisory feedback',
        'Art.23'  => 'Operational or security payment-related incidents concerning credit / payment / e-money / account info institutions',
        // Chapter IV — Digital operational resilience testing
        'Art.24'  => 'General requirements for the performance of digital operational resilience testing',
        'Art.25'  => 'Testing of ICT tools and systems',
        'Art.26'  => 'Advanced testing of ICT tools, systems and processes — Threat-Led Penetration Testing (TLPT)',
        'Art.27'  => 'Requirements for testers carrying out TLPT',
        // Chapter V — Managing of ICT third-party risk
        'Art.28'  => 'General principles (sound monitoring of ICT third-party risk)',
        'Art.29'  => 'Preliminary assessment of ICT concentration risk and further sub-contracting arrangements',
        'Art.30'  => 'Key contractual provisions',
        'Art.31'  => 'Designation of critical ICT third-party service providers',
        'Art.32'  => 'Structure of the Oversight Framework',
        'Art.33'  => 'Tasks of the Lead Overseer',
        'Art.34'  => 'Operational coordination between Lead Overseers',
        'Art.35'  => 'Powers of the Lead Overseer',
        'Art.36'  => 'Exercise of the powers of the Lead Overseer outside the Union',
        'Art.37'  => 'Request for information',
        'Art.38'  => 'General investigations',
        'Art.39'  => 'Inspections',
        'Art.40'  => 'Ongoing oversight',
        'Art.41'  => 'Harmonisation of conditions enabling the conduct of the oversight activities',
        'Art.42'  => 'Follow-up by competent authorities',
        'Art.43'  => 'Oversight fees',
        'Art.44'  => 'International cooperation',
        // Chapter VI — Information-sharing arrangements
        'Art.45'  => 'Information-sharing arrangements on cyber threat information and intelligence',
        // Chapter VII — Competent authorities
        'Art.46'  => 'Competent authorities',
        'Art.47'  => 'Cooperation with structures and authorities established by Directive (EU) 2022/2555 (NIS2)',
        'Art.48'  => 'Cooperation between authorities',
        'Art.49'  => 'Financial cross-sector exercises, communication and cooperation',
        'Art.50'  => 'Administrative penalties and remedial measures',
        'Art.51'  => 'Exercise of the power to impose administrative penalties and remedial measures',
        'Art.52'  => 'Criminal penalties',
        'Art.53'  => 'Notification duties',
        'Art.54'  => 'Publication of administrative penalties',
        'Art.55'  => 'Professional secrecy',
        'Art.56'  => 'Data protection',
        // Chapter VIII — Delegated acts
        'Art.57'  => 'Exercise of the delegation',
        // Chapter IX — Transitional and final provisions
        'Art.58'  => 'Review clause',
        'Art.59'  => 'Amendment to Regulation (EC) No 1060/2009',
        'Art.60'  => 'Amendment to Regulation (EU) No 648/2012',
        'Art.61'  => 'Amendment to Regulation (EU) No 600/2014',
        'Art.62'  => 'Amendment to Regulation (EU) No 909/2014',
        'Art.63'  => 'Amendment to Regulation (EU) 2016/1011',
        'Art.64'  => 'Entry into force and application',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'DORA']);
        if ($framework === null) {
            $io->error('Framework DORA not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::REQUIREMENTS as $reqId => $title) {
            $reqId = (string) $reqId;
            $artNum = (int) (preg_match('/Art\.(\d+)/', $reqId, $m) ? $m[1] : 0);
            $chapter = match (true) {
                $artNum <= 4   => 'I — General provisions',
                $artNum <= 16  => 'II — ICT risk management',
                $artNum <= 23  => 'III — ICT incident management',
                $artNum <= 27  => 'IV — Digital operational resilience testing',
                $artNum <= 44  => 'V — ICT third-party risk',
                $artNum === 45 => 'VI — Information-sharing arrangements',
                $artNum <= 56  => 'VII — Competent authorities',
                $artNum === 57 => 'VIII — Delegated acts',
                default        => 'IX — Final provisions',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority($artNum >= 5 && $artNum <= 23 ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('DORA (Regulation EU 2022/2554) / %s — %s. Quelle: Verordnung (EU) 2022/2554 (Digital Operational Resilience Act).', $reqId, $title));
            $req->setCategory($chapter);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('DORA full: %d created, %d updated. Total canonical: %d (+ legacy IDs from old loader still in DB).', $created, $updated, count(self::REQUIREMENTS)));
        return Command::SUCCESS;
    }
}
