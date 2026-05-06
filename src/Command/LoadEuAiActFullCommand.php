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
 * Loads the full EU AI Act (Regulation EU 2024/1689) — all 113 Articles
 * across 13 Chapters plus Annex I-XIII headings.
 */
#[AsCommand(
    name: 'app:load-eu-ai-act-full',
    description: 'Load EU AI Act (Regulation EU 2024/1689) all 113 Articles + 13 Annex headings as ComplianceRequirement rows.'
)]
final class LoadEuAiActFullCommand extends Command
{
    /** @var array<string, string> */
    private const ARTICLES = [
        // Chapter I — General provisions
        'Art.1'  => 'Subject matter',
        'Art.2'  => 'Scope',
        'Art.3'  => 'Definitions',
        'Art.4'  => 'AI literacy',
        // Chapter II — Prohibited artificial intelligence practices
        'Art.5'  => 'Prohibited AI practices',
        // Chapter III — High-risk AI systems
        'Art.6'  => 'Classification rules for high-risk AI systems',
        'Art.7'  => 'Amendments to Annex III',
        'Art.8'  => 'Compliance with the requirements',
        'Art.9'  => 'Risk management system',
        'Art.10' => 'Data and data governance',
        'Art.11' => 'Technical documentation',
        'Art.12' => 'Record-keeping',
        'Art.13' => 'Transparency and provision of information to deployers',
        'Art.14' => 'Human oversight',
        'Art.15' => 'Accuracy, robustness and cybersecurity',
        'Art.16' => 'Obligations of providers of high-risk AI systems',
        'Art.17' => 'Quality management system',
        'Art.18' => 'Documentation keeping',
        'Art.19' => 'Automatically generated logs',
        'Art.20' => 'Corrective actions and duty of information',
        'Art.21' => 'Cooperation with competent authorities',
        'Art.22' => 'Authorised representatives of providers',
        'Art.23' => 'Obligations of importers',
        'Art.24' => 'Obligations of distributors',
        'Art.25' => 'Responsibilities along the AI value chain',
        'Art.26' => 'Obligations of deployers of high-risk AI systems',
        'Art.27' => 'Fundamental rights impact assessment for high-risk AI systems',
        'Art.28' => 'Notifying authorities',
        'Art.29' => 'Application of a notified body for notification',
        'Art.30' => 'Notification procedure',
        'Art.31' => 'Requirements relating to notified bodies',
        'Art.32' => 'Presumption of conformity with requirements relating to notified bodies',
        'Art.33' => 'Subsidiaries of notified bodies and subcontracting',
        'Art.34' => 'Operational obligations of notified bodies',
        'Art.35' => 'Identification numbers and lists of notified bodies',
        'Art.36' => 'Changes to notifications',
        'Art.37' => 'Challenge to the competence of notified bodies',
        'Art.38' => 'Coordination of notified bodies',
        'Art.39' => 'Conformity assessment bodies of third countries',
        'Art.40' => 'Harmonised standards and standardisation deliverables',
        'Art.41' => 'Common specifications',
        'Art.42' => 'Presumption of conformity with certain requirements',
        'Art.43' => 'Conformity assessment',
        'Art.44' => 'Certificates',
        'Art.45' => 'Information obligations of notified bodies',
        'Art.46' => 'Derogation from conformity assessment procedure',
        'Art.47' => 'EU declaration of conformity',
        'Art.48' => 'CE marking',
        'Art.49' => 'Registration',
        // Chapter IV — Transparency obligations for providers and deployers of certain AI systems
        'Art.50' => 'Transparency obligations for providers and deployers of certain AI systems',
        // Chapter V — General-purpose AI models
        'Art.51' => 'Classification of general-purpose AI models as general-purpose AI models with systemic risk',
        'Art.52' => 'Procedure',
        'Art.53' => 'Obligations for providers of general-purpose AI models',
        'Art.54' => 'Authorised representatives of providers of general-purpose AI models',
        'Art.55' => 'Obligations for providers of general-purpose AI models with systemic risk',
        'Art.56' => 'Codes of practice',
        // Chapter VI — Measures in support of innovation
        'Art.57' => 'AI regulatory sandboxes',
        'Art.58' => 'Detailed arrangements for, and functioning of, AI regulatory sandboxes',
        'Art.59' => 'Further processing of personal data for developing certain AI systems in the public interest in the AI regulatory sandbox',
        'Art.60' => 'Testing of high-risk AI systems in real-world conditions outside AI regulatory sandboxes',
        'Art.61' => 'Informed consent to participate in testing in real-world conditions outside AI regulatory sandboxes',
        'Art.62' => 'Measures for providers and deployers, in particular SMEs, including start-ups',
        'Art.63' => 'Derogations for specific operators',
        // Chapter VII — Governance
        'Art.64' => 'AI Office',
        'Art.65' => 'Establishment and structure of the European Artificial Intelligence Board',
        'Art.66' => 'Tasks of the Board',
        'Art.67' => 'Advisory forum',
        'Art.68' => 'Scientific panel of independent experts',
        'Art.69' => 'Access to the pool of experts by the Member States',
        'Art.70' => 'Designation of national competent authorities and single points of contact',
        // Chapter VIII — EU database for high-risk AI systems
        'Art.71' => 'EU database for high-risk AI systems listed in Annex III',
        // Chapter IX — Post-market monitoring, information sharing and market surveillance
        'Art.72' => 'Post-market monitoring by providers and post-market monitoring plan for high-risk AI systems',
        'Art.73' => 'Reporting of serious incidents',
        'Art.74' => 'Market surveillance and control of AI systems in the Union market',
        'Art.75' => 'Mutual assistance, market surveillance and control of general-purpose AI systems',
        'Art.76' => 'Supervision of testing in real-world conditions by market surveillance authorities',
        'Art.77' => 'Powers of authorities protecting fundamental rights',
        'Art.78' => 'Confidentiality',
        'Art.79' => 'Procedure at national level for dealing with AI systems presenting a risk',
        'Art.80' => 'Procedure for dealing with AI systems classified by the provider as not high-risk in application of Annex III',
        'Art.81' => 'Union safeguard procedure',
        'Art.82' => 'Compliant AI systems which present a risk',
        'Art.83' => 'Formal non-compliance',
        'Art.84' => 'Union AI testing support structures',
        // Chapter X — Codes of conduct and guidelines
        'Art.85' => 'Right to lodge a complaint with a market surveillance authority',
        'Art.86' => 'Right to explanation of individual decision-making',
        'Art.87' => 'Reporting of breaches and protection of reporting persons',
        'Art.88' => 'Enforcement of the obligations of providers of general-purpose AI models',
        'Art.89' => 'Monitoring actions',
        'Art.90' => 'Alerts of systemic risks by the scientific panel',
        'Art.91' => 'Power to request documentation and information',
        'Art.92' => 'Power to conduct evaluations',
        'Art.93' => 'Power to request measures',
        'Art.94' => 'Procedural rights of economic operators of the general-purpose AI model',
        // Chapter XI — Delegation of power and committee procedure
        'Art.95' => 'Codes of conduct for voluntary application of specific requirements',
        'Art.96' => 'Guidelines from the Commission on the implementation of this Regulation',
        // Chapter XII — Penalties
        'Art.97' => 'Exercise of the delegation',
        'Art.98' => 'Committee procedure',
        // Chapter XIII — Final provisions
        'Art.99'  => 'Penalties',
        'Art.100' => 'Administrative fines on Union institutions, bodies, offices and agencies',
        'Art.101' => 'Fines for providers of general-purpose AI models',
        'Art.102' => 'Amendment to Regulation (EC) No 300/2008',
        'Art.103' => 'Amendment to Regulation (EU) No 167/2013',
        'Art.104' => 'Amendment to Regulation (EU) No 168/2013',
        'Art.105' => 'Amendment to Directive 2014/90/EU',
        'Art.106' => 'Amendment to Directive (EU) 2016/797',
        'Art.107' => 'Amendment to Regulation (EU) 2018/858',
        'Art.108' => 'Amendments to Regulations (EU) 2018/1139 and (EU) 2019/2144',
        'Art.109' => 'Amendment to Regulation (EU) 2019/2144',
        'Art.110' => 'Amendment to Directive (EU) 2020/1828',
        'Art.111' => 'AI systems already placed on the market or put into service and general-purpose AI models already placed on the market',
        'Art.112' => 'Evaluation and review',
        'Art.113' => 'Entry into force and application',
        // Annexes
        'Annex-I'    => 'List of Union harmonisation legislation',
        'Annex-II'   => 'List of criminal offences referred to in Article 5(1), first subparagraph, point (h)(iii)',
        'Annex-III'  => 'High-risk AI systems referred to in Article 6(2)',
        'Annex-IV'   => 'Technical documentation referred to in Article 11(1)',
        'Annex-V'    => 'EU declaration of conformity',
        'Annex-VI'   => 'Conformity assessment procedure based on internal control',
        'Annex-VII'  => 'Conformity based on assessment of quality management system and assessment of technical documentation',
        'Annex-VIII' => 'Information to be submitted upon registration of high-risk AI systems and providers in accordance with Article 49',
        'Annex-IX'   => 'Information to be submitted upon registration of high-risk AI systems listed in Annex III in relation to testing in real-world conditions',
        'Annex-X'    => 'Union legislative acts on large-scale IT systems in the area of Freedom, Security and Justice',
        'Annex-XI'   => 'Technical documentation referred to in Article 53(1), point (a) — Technical documentation for providers of general-purpose AI models',
        'Annex-XII'  => 'Transparency information referred to in Article 53(1), point (b) — Technical documentation for providers of general-purpose AI models',
        'Annex-XIII' => 'Criteria for the designation of general-purpose AI models with systemic risk referred to in Article 51',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'EU-AI-ACT']);
        if ($framework === null) {
            $io->error('Framework EU-AI-ACT not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::ARTICLES as $reqId => $title) {
            $reqId = (string) $reqId;
            $artNum = str_starts_with($reqId, 'Annex') ? null : (int) substr($reqId, 4);
            $chapter = match (true) {
                $reqId === 'Art.1' || $reqId === 'Art.2' || $reqId === 'Art.3' || $reqId === 'Art.4' => 'I — General provisions',
                $reqId === 'Art.5' => 'II — Prohibited AI practices',
                $artNum !== null && $artNum >= 6 && $artNum <= 49 => 'III — High-risk AI systems',
                $reqId === 'Art.50' => 'IV — Transparency obligations',
                $artNum !== null && $artNum >= 51 && $artNum <= 56 => 'V — General-purpose AI models',
                $artNum !== null && $artNum >= 57 && $artNum <= 63 => 'VI — Measures in support of innovation',
                $artNum !== null && $artNum >= 64 && $artNum <= 70 => 'VII — Governance',
                $reqId === 'Art.71' => 'VIII — EU database',
                $artNum !== null && $artNum >= 72 && $artNum <= 84 => 'IX — Post-market monitoring + market surveillance',
                $artNum !== null && $artNum >= 85 && $artNum <= 94 => 'X — Codes of conduct + remedies',
                $artNum !== null && $artNum >= 95 && $artNum <= 96 => 'XI — Codes of conduct + Commission guidelines',
                $artNum !== null && $artNum >= 97 && $artNum <= 98 => 'XII — Delegation + committee procedure',
                $artNum !== null && $artNum >= 99 && $artNum <= 113 => 'XIII — Penalties + final provisions',
                str_starts_with($reqId, 'Annex') => 'Annexes',
                default => 'Other',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority(in_array($reqId, ['Art.5','Art.6','Art.9','Art.10','Art.11','Art.13','Art.14','Art.15','Art.16','Art.26','Art.43','Art.50','Art.53','Art.55','Art.72','Art.73'], true) ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('EU AI Act (Regulation EU 2024/1689) / %s — %s. Quelle: Verordnung (EU) 2024/1689 zur Festlegung harmonisierter Vorschriften fuer kuenstliche Intelligenz.', $reqId, $title));
            $req->setCategory($chapter);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('EU AI Act: %d created, %d updated. Total: %d (113 Articles + 13 Annexes).', $created, $updated, count(self::ARTICLES)));
        return Command::SUCCESS;
    }
}
