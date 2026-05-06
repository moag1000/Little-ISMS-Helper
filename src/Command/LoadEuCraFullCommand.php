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
 * Loads the full EU Cyber Resilience Act (Regulation EU 2024/2847) catalogue:
 * Annex I Part I (essential cybersecurity requirements 1.1 + 1.2 + 1.3.a–l),
 * Annex I Part II (vulnerability handling 1–8), Annex II/V/VII headings,
 * and the operative Articles (11, 13, 14, 22, 24, 31, 54).
 */
#[AsCommand(
    name: 'app:load-eu-cra-full',
    description: 'Load EU Cyber Resilience Act (Regulation 2024/2847) Annex I Part I + Part II + key Articles as ComplianceRequirement rows.'
)]
final class LoadEuCraFullCommand extends Command
{
    /** @var array<string, string> */
    private const REQUIREMENTS = [
        // Annex I, Part I — Essential cybersecurity requirements
        'CRA-Annex-I-1.1'   => 'Properties: products designed, developed, produced to ensure appropriate level of cybersecurity',
        'CRA-Annex-I-1.2'   => 'Delivered without known exploitable vulnerabilities',
        'CRA-Annex-I-1.3.a' => 'Delivered with secure-by-default configuration',
        'CRA-Annex-I-1.3.b' => 'Ensure protection from unauthorised access (authentication, identity, access management)',
        'CRA-Annex-I-1.3.c' => 'Protect confidentiality of stored, transmitted, processed data (encryption)',
        'CRA-Annex-I-1.3.d' => 'Protect integrity of stored, transmitted, processed data, commands, programs, configurations',
        'CRA-Annex-I-1.3.e' => 'Process only data necessary for intended use (data minimisation)',
        'CRA-Annex-I-1.3.f' => 'Protect availability of essential and basic functions (incl. resilience to DoS)',
        'CRA-Annex-I-1.3.g' => 'Minimise own negative impact on availability of services provided by other devices/networks',
        'CRA-Annex-I-1.3.h' => 'Limit attack surfaces (incl. external interfaces)',
        'CRA-Annex-I-1.3.i' => 'Reduce impact of incidents using exploitation-mitigation techniques',
        'CRA-Annex-I-1.3.j' => 'Provide security-related information by recording and monitoring relevant internal activity',
        'CRA-Annex-I-1.3.k' => 'Address vulnerabilities through security updates (incl. automatic updates with opt-out)',
        'CRA-Annex-I-1.3.l' => 'Ensure secure ability to remove personal/other data, settings, content',

        // Annex I, Part II — Vulnerability handling requirements
        'CRA-Annex-II-1' => 'Identify and document vulnerabilities and components contained in the product (SBOM in commonly used machine-readable format)',
        'CRA-Annex-II-2' => 'Address and remediate vulnerabilities without delay (free security updates)',
        'CRA-Annex-II-3' => 'Apply effective and regular tests and reviews of the security of the product',
        'CRA-Annex-II-4' => 'Once a security update is available, share information about fixed vulnerabilities (incl. severity, impact, mitigation)',
        'CRA-Annex-II-5' => 'Put in place and enforce policy on coordinated vulnerability disclosure',
        'CRA-Annex-II-6' => 'Take measures to facilitate sharing of information about potential vulnerabilities (single point of contact)',
        'CRA-Annex-II-7' => 'Provide for mechanisms to securely distribute updates (verified authenticity, integrity)',
        'CRA-Annex-II-8' => 'Ensure that, where security patches/updates are available to address identified issues, they are disseminated without delay and free of charge',

        // Operative Articles
        'Art.11'   => 'Reporting obligations of manufacturers (CSIRTs/ENISA, 24h early warning + 72h notification + final report)',
        'Art.13.1' => 'Manufacturer obligations — placing only compliant products on the market',
        'Art.13.2' => 'Manufacturer obligations — risk assessment and consideration during design and development',
        'Art.13.3' => 'Manufacturer obligations — product compliance throughout support period',
        'Art.13.5' => 'Manufacturer obligations — vulnerability handling for the support period (min. 5 years)',
        'Art.13.7' => 'Manufacturer obligations — point of contact for vulnerability disclosure',
        'Art.13.8' => 'Manufacturer obligations — secure-update distribution mechanisms',
        'Art.13.16' => 'Manufacturer obligations — information for users (instructions for use, security updates, end of support)',
        'Art.14.1' => 'Reporting actively exploited vulnerabilities — 24h early warning to CSIRT',
        'Art.14.2' => 'Reporting actively exploited vulnerabilities — 72h notification with assessment',
        'Art.14.3' => 'Reporting actively exploited vulnerabilities — final report within 14 days',
        'Art.14.4' => 'Reporting severe incidents — 24h / 72h / 1-month chain',
        'Art.14.6' => 'Reporting — information to product users',
        'Art.18'   => 'Importer obligations — verify CE marking + EU Declaration of Conformity',
        'Art.19'   => 'Distributor obligations — verify CE marking + accompanying documents',
        'Art.22'   => 'Conformity assessment — modules A, B+C, H',
        'Art.23'   => 'EU declaration of conformity — content + retention',
        'Art.24'   => 'CE marking — placement, visibility, indelibility',
        'Art.31'   => 'Notified bodies — designation, monitoring, withdrawal',
        'Art.32'   => 'Identification numbers and lists of notified bodies',
        'Art.33'   => 'Operational obligations of notified bodies',
        'Art.50'   => 'Penalties — administrative fines (max 15M EUR or 2.5% global turnover)',
        'Art.54'   => 'Entry into force — application from 11 December 2027',

        // Annex IV (Critical products with digital elements)
        'Annex-III' => 'Important products with digital elements (Class I + II)',
        'Annex-IV'  => 'Critical products with digital elements',
        'Annex-V'   => 'Information and instructions to the user',
        'Annex-VII' => 'Technical documentation',
        'Annex-VIII' => 'Conformity assessment procedures (Module A self-assessment, Module B+C type examination, Module H full quality assurance)',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'EU-CRA']);
        if ($framework === null) {
            $io->error('Framework EU-CRA not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::REQUIREMENTS as $reqId => $title) {
            $category = match (true) {
                str_starts_with($reqId, 'CRA-Annex-I-1.1'), str_starts_with($reqId, 'CRA-Annex-I-1.2'), str_starts_with($reqId, 'CRA-Annex-I-1.3') => 'Annex-I-Part-I-Security',
                str_starts_with($reqId, 'CRA-Annex-II') => 'Annex-I-Part-II-Vulnerability-Handling',
                str_starts_with($reqId, 'Art.') => 'Operative-Articles',
                str_starts_with($reqId, 'Annex-') => 'Other-Annexes',
                default => 'Other',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority(str_starts_with($reqId, 'Art.14') ? 'critical' : 'high');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('EU CRA (Regulation 2024/2847) / %s — %s. Quelle: Verordnung (EU) 2024/2847 ueber horizontale Cybersicherheitsanforderungen fuer Produkte mit digitalen Elementen.', $reqId, $title));
            $req->setCategory($category);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('EU-CRA: %d created, %d updated. Total: %d.', $created, $updated, count(self::REQUIREMENTS)));
        return Command::SUCCESS;
    }
}
