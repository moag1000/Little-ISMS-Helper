<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Compliance\FrameworkLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the Swiss Federal Act on Data Protection (Bundesgesetz über den
 * Datenschutz, nDSG / revDSG, SR 235.1), in force since 2023-09-01.
 *
 * The revDSG was deliberately aligned with GDPR (EU adequacy goal), making
 * it strongly crosswalkable to GDPR and ISO 27701.
 *
 * Source: Fedlex SR 235.1 — official consolidated text (federal public law,
 * freely usable without copyright restriction).
 * URL: https://www.fedlex.admin.ch/eli/cc/2022/491/de
 *
 * The 74 Articles are loaded. The catalogue covers:
 * — Art.1-5   : Scope, definitions (1. Kapitel)
 * — Art.6-15  : Principles, data security, processor, records, representation (2. Kapitel)
 * — Art.16-18 : Cross-border transfers (3. Kapitel)
 * — Art.19-29 : Information duties, automated decisions, DPIA, breach notification,
 *               right of access, portability (4. Kapitel)
 * — Art.30-32 : Rights claims (5. Kapitel)
 * — Art.33-42 : Federal bodies (6. Kapitel)
 * — Art.43-59 : EDÖB (commissioner) (7. Kapitel)
 * — Art.60-66 : Penal provisions (8. Kapitel)
 * — Art.67-74 : Final provisions / transitional rules (9. Kapitel)
 */
#[AsCommand(
    name: 'app:load-revdsg-ch-full',
    description: 'Load all 74 Articles of the Swiss revDSG/nDSG (SR 235.1, in force 2023-09-01) as ComplianceRequirement rows.'
)]
final class LoadRevDsgChFullCommand extends Command implements FrameworkLoaderInterface
{
    /**
     * All 74 Articles of the Swiss nDSG (SR 235.1), official German headings
     * as published on Fedlex (2023-09-01 consolidated version).
     *
     * Source: fedlex.admin.ch/eli/cc/2022/491/de (verified 2026-06-14).
     *
     * @var array<string, string>
     */
    private const ARTICLES = [
        // 1. Kapitel: Allgemeine Bestimmungen
        'REVDSG-Art.1'  => 'Zweck',
        'REVDSG-Art.2'  => 'Persönlicher und sachlicher Geltungsbereich',
        'REVDSG-Art.3'  => 'Räumlicher Geltungsbereich',
        'REVDSG-Art.4'  => 'Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter',
        'REVDSG-Art.5'  => 'Begriffe',

        // 2. Kapitel: Voraussetzungen der Bearbeitung von Personendaten durch private Personen
        'REVDSG-Art.6'  => 'Grundsätze',
        'REVDSG-Art.7'  => 'Datenschutz durch Technik und datenschutzfreundliche Voreinstellungen',
        'REVDSG-Art.8'  => 'Datensicherheit',
        'REVDSG-Art.9'  => 'Bearbeitung durch Auftragsbearbeiter',
        'REVDSG-Art.10' => 'Datenschutzberaterin oder -berater',
        'REVDSG-Art.11' => 'Verhaltenskodizes',
        'REVDSG-Art.12' => 'Verzeichnis der Bearbeitungstätigkeiten',
        'REVDSG-Art.13' => 'Zertifizierung',
        'REVDSG-Art.14' => 'Vertretung',
        'REVDSG-Art.15' => 'Pflichten der Vertretung',

        // 3. Kapitel: Grenzüberschreitende Bekanntgabe von Personendaten
        'REVDSG-Art.16' => 'Grundsätze',
        'REVDSG-Art.17' => 'Ausnahmen',
        'REVDSG-Art.18' => 'Veröffentlichung von Personendaten in elektronischer Form',

        // 4. Kapitel: Pflichten des Verantwortlichen und des Auftragsbearbeiters
        'REVDSG-Art.19' => 'Informationspflicht bei der Beschaffung von Personendaten',
        'REVDSG-Art.20' => 'Ausnahmen von der Informationspflicht und Einschränkungen',
        'REVDSG-Art.21' => 'Informationspflicht bei einer automatisierten Einzelentscheidung',
        'REVDSG-Art.22' => 'Datenschutz-Folgenabschätzung',
        'REVDSG-Art.23' => 'Konsultation des EDÖB',
        'REVDSG-Art.24' => 'Meldung von Verletzungen der Datensicherheit',
        'REVDSG-Art.25' => 'Auskunftsrecht',
        'REVDSG-Art.26' => 'Einschränkungen des Auskunftsrechts',
        'REVDSG-Art.27' => 'Einschränkungen des Auskunftsrechts für Medien',
        'REVDSG-Art.28' => 'Recht auf Datenherausgabe oder -übertragung',
        'REVDSG-Art.29' => 'Einschränkungen des Rechts auf Datenherausgabe oder -übertragung',

        // 5. Kapitel: Rechtsansprüche
        'REVDSG-Art.30' => 'Persönlichkeitsverletzungen',
        'REVDSG-Art.31' => 'Rechtfertigungsgründe',
        'REVDSG-Art.32' => 'Rechtsansprüche',

        // 6. Kapitel: Datenbearbeitung durch Bundesorgane
        'REVDSG-Art.33' => 'Kontrolle und Verantwortung bei gemeinsamer Bearbeitung von Personendaten',
        'REVDSG-Art.34' => 'Rechtsgrundlagen',
        'REVDSG-Art.35' => 'Automatisierte Datenbearbeitung im Rahmen von Pilotversuchen',
        'REVDSG-Art.36' => 'Bekanntgabe von Personendaten',
        'REVDSG-Art.37' => 'Widerspruch gegen die Bekanntgabe von Personendaten',
        'REVDSG-Art.38' => 'Angebot von Unterlagen an das Bundesarchiv',
        'REVDSG-Art.39' => 'Datenbearbeitung für nicht personenbezogene Zwecke',
        'REVDSG-Art.40' => 'Privatrechtliche Tätigkeit von Bundesorganen',
        'REVDSG-Art.41' => 'Ansprüche und Verfahren',
        'REVDSG-Art.42' => 'Verfahren im Falle der Bekanntgabe von amtlichen Dokumenten, die Personendaten enthalten',

        // 7. Kapitel: Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter (EDÖB)
        'REVDSG-Art.43' => 'Wahl und Stellung',
        'REVDSG-Art.44' => 'Amtsdauer, Wiederwahl und Beendigung der Amtsdauer',
        'REVDSG-Art.45' => 'Budget',
        'REVDSG-Art.46' => 'Unvereinbarkeit',
        'REVDSG-Art.47' => 'Nebenbeschäftigung',
        'REVDSG-Art.48' => 'Selbstkontrolle des EDÖB',
        'REVDSG-Art.49' => 'Untersuchung',
        'REVDSG-Art.50' => 'Befugnisse',
        'REVDSG-Art.51' => 'Verwaltungsmassnahmen',
        'REVDSG-Art.52' => 'Verfahren',
        'REVDSG-Art.53' => 'Koordination',
        'REVDSG-Art.54' => 'Amtshilfe zwischen schweizerischen Behörden',
        'REVDSG-Art.55' => 'Amtshilfe gegenüber ausländischen Behörden',
        'REVDSG-Art.56' => 'Register',
        'REVDSG-Art.57' => 'Information',
        'REVDSG-Art.58' => 'Weitere Aufgaben',
        'REVDSG-Art.59' => 'Gebühren',

        // 8. Kapitel: Strafbestimmungen
        'REVDSG-Art.60' => 'Verletzung von Informations-, Auskunfts- und Mitwirkungspflichten',
        'REVDSG-Art.61' => 'Verletzung von Sorgfaltspflichten',
        'REVDSG-Art.62' => 'Verletzung der beruflichen Schweigepflicht',
        'REVDSG-Art.63' => 'Missachten von Verfügungen',
        'REVDSG-Art.64' => 'Widerhandlungen in Geschäftsbetrieben',
        'REVDSG-Art.65' => 'Zuständigkeit',
        'REVDSG-Art.66' => 'Verfolgungsverjährung',

        // 9. Kapitel: Schlussbestimmungen
        'REVDSG-Art.67' => 'Abschluss von Staatsverträgen',
        'REVDSG-Art.68' => 'Aufhebung und Änderung anderer Erlasse',
        'REVDSG-Art.69' => 'Übergangsbestimmungen betreffend laufende Bearbeitungen',
        'REVDSG-Art.70' => 'Übergangsbestimmung betreffend laufende Verfahren',
        'REVDSG-Art.71' => 'Übergangsbestimmung betreffend Daten juristischer Personen',
        'REVDSG-Art.72' => 'Übergangsbestimmung betreffend die Wahl und die Beendigung der Amtsdauer der oder des Beauftragten',
        'REVDSG-Art.73' => 'Koordination',
        'REVDSG-Art.74' => 'Referendum und Inkrafttreten',
    ];

    /**
     * Articles carrying primary compliance obligations (priority = high).
     * Basis: Kapitel 2 (private processing principles + security + processor),
     * Kapitel 3 (cross-border transfers), Kapitel 4 (information duties, DPIA,
     * breach notification, data-subject rights).
     *
     * @var array<int, int>
     */
    private const HIGH_PRIORITY_ART_NUMS = [
        6 => 6, 7 => 7, 8 => 8, 9 => 9, 12 => 12, 16 => 16,
        17 => 17, 19 => 19, 21 => 21, 22 => 22, 24 => 24, 25 => 25, 28 => 28,
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'REVDSG-CH';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'REVDSG-CH']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
            $framework->setCode('REVDSG-CH')
                ->setName('revDSG / nDSG — Revidiertes Datenschutzgesetz (SR 235.1)')
                ->setDescription(
                    'Schweizerisches Bundesgesetz über den Datenschutz (nDSG) in Kraft seit 01.09.2023. '
                    . 'Das revidierte DSG wurde bewusst an die EU-DSGVO angepasst (EU-Angemessenheitsziel). '
                    . 'Reguliert Bearbeitung von Personendaten durch private Personen und Bundesorgane. '
                    . 'Zuständige Behörde: EDÖB (Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter).'
                )
                ->setVersion('2023')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('Bundesrat / Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter (EDÖB)')
                ->setMandatory(false)
                ->setScopeDescription(
                    'Anwendbar auf private Personen und Bundesorgane, die in der Schweiz tätig sind '
                    . 'oder deren Tätigkeit Auswirkungen auf Personen in der Schweiz hat (Marktortprinzip, Art.3).'
                )
                ->setActive(true);
            $this->em->persist($framework);
            $this->em->flush();
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0;
        $updated = 0;

        foreach (self::ARTICLES as $reqId => $title) {
            $artNum = (int) preg_replace('/^REVDSG-Art\./', '', $reqId);
            $kapitel = match (true) {
                $artNum <= 5  => '1. Kapitel — Allgemeine Bestimmungen',
                $artNum <= 15 => '2. Kapitel — Voraussetzungen der Bearbeitung (Private)',
                $artNum <= 18 => '3. Kapitel — Grenzüberschreitende Bekanntgabe',
                $artNum <= 29 => '4. Kapitel — Pflichten des Verantwortlichen',
                $artNum <= 32 => '5. Kapitel — Rechtsansprüche',
                $artNum <= 42 => '6. Kapitel — Datenbearbeitung durch Bundesorgane',
                $artNum <= 59 => '7. Kapitel — EDÖB',
                $artNum <= 66 => '8. Kapitel — Strafbestimmungen',
                default       => '9. Kapitel — Schlussbestimmungen',
            };

            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority(isset(self::HIGH_PRIORITY_ART_NUMS[$artNum]) ? 'high' : 'medium');
                $created++;
            } else {
                if (!$update) {
                    continue;
                }
                $updated++;
            }

            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf(
                'revDSG/nDSG (SR 235.1) / %s — %s. '
                . 'Quelle: Bundesgesetz über den Datenschutz vom 25. September 2020 (SR 235.1), '
                . 'in Kraft seit 01.09.2023. Fedlex: https://www.fedlex.admin.ch/eli/cc/2022/491/de.',
                $reqId,
                $title,
            ));
            $req->setCategory($kapitel);
            $this->em->persist($req);
        }

        $this->em->flush();
        $io?->success(sprintf(
            'revDSG-CH (SR 235.1): %d created, %d updated. Total catalogue: %d Articles.',
            $created,
            $updated,
            count(self::ARTICLES),
        ));

        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }
}
