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
 * Loads the full GDPR (Regulation EU 2016/679) Article catalogue (99 Articles
 * across Chapters I-XI). Recitals are not loaded — they are interpretive,
 * not normative; the Articles are what compliance maps to.
 */
#[AsCommand(
    name: 'app:load-gdpr-full',
    description: 'Load all 99 GDPR Articles (Regulation EU 2016/679) as ComplianceRequirement rows.'
)]
final class LoadGdprFullCommand extends Command
{
    /** @var array<string, string> */
    private const ARTICLES = [
        // Chapter I — General provisions
        'Art.1'  => 'Subject-matter and objectives',
        'Art.2'  => 'Material scope',
        'Art.3'  => 'Territorial scope',
        'Art.4'  => 'Definitions',
        // Chapter II — Principles
        'Art.5'  => 'Principles relating to processing of personal data',
        'Art.6'  => 'Lawfulness of processing',
        'Art.7'  => 'Conditions for consent',
        'Art.8'  => 'Conditions applicable to child\'s consent',
        'Art.9'  => 'Processing of special categories of personal data',
        'Art.10' => 'Processing of criminal-conviction data',
        'Art.11' => 'Processing not requiring identification',
        // Chapter III — Rights of the data subject
        'Art.12' => 'Transparent information, communication and modalities for the exercise of rights',
        'Art.13' => 'Information to be provided where personal data are collected from the data subject',
        'Art.14' => 'Information to be provided where personal data have not been obtained from the data subject',
        'Art.15' => 'Right of access by the data subject',
        'Art.16' => 'Right to rectification',
        'Art.17' => 'Right to erasure (right to be forgotten)',
        'Art.18' => 'Right to restriction of processing',
        'Art.19' => 'Notification obligation regarding rectification, erasure or restriction',
        'Art.20' => 'Right to data portability',
        'Art.21' => 'Right to object',
        'Art.22' => 'Automated individual decision-making, including profiling',
        'Art.23' => 'Restrictions',
        // Chapter IV — Controller and processor
        'Art.24' => 'Responsibility of the controller',
        'Art.25' => 'Data protection by design and by default',
        'Art.26' => 'Joint controllers',
        'Art.27' => 'Representatives of controllers / processors not established in the Union',
        'Art.28' => 'Processor',
        'Art.29' => 'Processing under the authority of the controller or processor',
        'Art.30' => 'Records of processing activities',
        'Art.31' => 'Cooperation with the supervisory authority',
        'Art.32' => 'Security of processing',
        'Art.33' => 'Notification of a personal data breach to the supervisory authority',
        'Art.34' => 'Communication of a personal data breach to the data subject',
        'Art.35' => 'Data protection impact assessment',
        'Art.36' => 'Prior consultation',
        'Art.37' => 'Designation of the data protection officer',
        'Art.38' => 'Position of the data protection officer',
        'Art.39' => 'Tasks of the data protection officer',
        'Art.40' => 'Codes of conduct',
        'Art.41' => 'Monitoring of approved codes of conduct',
        'Art.42' => 'Certification',
        'Art.43' => 'Certification bodies',
        // Chapter V — Transfers to third countries
        'Art.44' => 'General principle for transfers',
        'Art.45' => 'Transfers on the basis of an adequacy decision',
        'Art.46' => 'Transfers subject to appropriate safeguards',
        'Art.47' => 'Binding corporate rules',
        'Art.48' => 'Transfers or disclosures not authorised by Union law',
        'Art.49' => 'Derogations for specific situations',
        'Art.50' => 'International cooperation for the protection of personal data',
        // Chapter VI — Independent supervisory authorities
        'Art.51' => 'Supervisory authority',
        'Art.52' => 'Independence',
        'Art.53' => 'General conditions for the members of the supervisory authority',
        'Art.54' => 'Rules on the establishment of the supervisory authority',
        'Art.55' => 'Competence',
        'Art.56' => 'Competence of the lead supervisory authority',
        'Art.57' => 'Tasks',
        'Art.58' => 'Powers',
        'Art.59' => 'Activity reports',
        // Chapter VII — Cooperation and consistency
        'Art.60' => 'Cooperation between the lead supervisory authority and the other supervisory authorities concerned',
        'Art.61' => 'Mutual assistance',
        'Art.62' => 'Joint operations of supervisory authorities',
        'Art.63' => 'Consistency mechanism',
        'Art.64' => 'Opinion of the Board',
        'Art.65' => 'Dispute resolution by the Board',
        'Art.66' => 'Urgency procedure',
        'Art.67' => 'Exchange of information',
        'Art.68' => 'European Data Protection Board',
        'Art.69' => 'Independence',
        'Art.70' => 'Tasks of the Board',
        'Art.71' => 'Reports',
        'Art.72' => 'Procedure',
        'Art.73' => 'Chair',
        'Art.74' => 'Tasks of the Chair',
        'Art.75' => 'Secretariat',
        'Art.76' => 'Confidentiality',
        // Chapter VIII — Remedies, liability and penalties
        'Art.77' => 'Right to lodge a complaint with a supervisory authority',
        'Art.78' => 'Right to an effective judicial remedy against a supervisory authority',
        'Art.79' => 'Right to an effective judicial remedy against a controller or processor',
        'Art.80' => 'Representation of data subjects',
        'Art.81' => 'Suspension of proceedings',
        'Art.82' => 'Right to compensation and liability',
        'Art.83' => 'General conditions for imposing administrative fines',
        'Art.84' => 'Penalties',
        // Chapter IX — Specific processing situations
        'Art.85' => 'Processing and freedom of expression and information',
        'Art.86' => 'Processing and public access to official documents',
        'Art.87' => 'Processing of the national identification number',
        'Art.88' => 'Processing in the context of employment',
        'Art.89' => 'Safeguards and derogations relating to processing for archiving / research / statistics',
        'Art.90' => 'Obligations of secrecy',
        'Art.91' => 'Existing data protection rules of churches and religious associations',
        // Chapter X — Delegated acts and implementing acts
        'Art.92' => 'Exercise of the delegation',
        'Art.93' => 'Committee procedure',
        // Chapter XI — Final provisions
        'Art.94' => 'Repeal of Directive 95/46/EC',
        'Art.95' => 'Relationship with Directive 2002/58/EC',
        'Art.96' => 'Relationship with previously concluded Agreements',
        'Art.97' => 'Commission reports',
        'Art.98' => 'Review of other Union legal acts on data protection',
        'Art.99' => 'Entry into force and application',
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
        $framework = $this->frameworkRepository->findOneBy(['code' => 'GDPR']);
        if ($framework === null) {
            $io->error('Framework GDPR not in DB.');
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::ARTICLES as $reqId => $title) {
            $artNum = (int) substr($reqId, 4);
            $chapter = match (true) {
                $artNum <= 4   => 'I — General provisions',
                $artNum <= 11  => 'II — Principles',
                $artNum <= 23  => 'III — Rights of the data subject',
                $artNum <= 43  => 'IV — Controller and processor',
                $artNum <= 50  => 'V — Transfers to third countries',
                $artNum <= 59  => 'VI — Independent supervisory authorities',
                $artNum <= 76  => 'VII — Cooperation and consistency',
                $artNum <= 84  => 'VIII — Remedies, liability and penalties',
                $artNum <= 91  => 'IX — Specific processing situations',
                $artNum <= 93  => 'X — Delegated and implementing acts',
                default        => 'XI — Final provisions',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority($artNum >= 24 && $artNum <= 39 ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle($title);
            $req->setDescription(sprintf('GDPR (Regulation EU 2016/679) / %s — %s. Quelle: Verordnung (EU) 2016/679 (DSGVO), Amtliches Werk der EU.', $reqId, $title));
            $req->setCategory($chapter);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('GDPR: %d created, %d updated. Total: %d Articles.', $created, $updated, count(self::ARTICLES)));
        return Command::SUCCESS;
    }
}
