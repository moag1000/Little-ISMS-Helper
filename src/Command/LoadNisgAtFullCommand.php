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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * NISG 2026 — Netz- und Informationssystemsicherheitsgesetz 2026.
 *
 * Österreichisches Bundesgesetz zur Gewährleistung eines hohen
 * Cybersicherheitsniveaus von Netz- und Informationssystemen (BGBl. I
 * Nr. 94/2025, RIS-Gesetzesnummer 20013065). Dies ist die österreichische
 * Umsetzung der NIS-2-Richtlinie (EU) 2022/2555.
 *
 * Das NISG 2026 ist amtliches Werk der Republik Österreich und als
 * öffentliches Recht frei verwendbar (kein Urheberrechtsschutz auf
 * gesetzliche Texte gemäß österreichischem UrhG). Quelle: Rechtsinformations-
 * system des Bundes (RIS), ris.bka.gv.at.
 *
 * Inkrafttreten: 30.09.2026 (§ 51 Abs. 1 NISG 2026).
 */
#[AsCommand(
    name: 'app:load-nisg-at-full',
    description: 'Load Austrian NISG 2026 (BGBl. I Nr. 94/2025) §§-catalogue as ComplianceRequirement rows (NIS2 transposition for Austria).',
)]
final class LoadNisgAtFullCommand extends Command implements FrameworkLoaderInterface
{
    /**
     * Framework code — MUST be NISG-AT everywhere (loader, mappings, tests).
     * MappingLibraryLoader does exact findOneBy(['code' => ...]) — no normalisation.
     */
    private const CODE = 'NISG-AT';

    /**
     * NISG 2026 §§ catalogue.
     * Format: requirementId => [title (verbatim §-heading from RIS), obligation_summary (DE)]
     * Source: BGBl. I Nr. 94/2025, RIS 20013065, abgerufen 2026-06-14.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     * Format: requirementId => [heading, description, category]
     */
    private const PARAGRAPHS = [
        // 1. Hauptstück — Allgemeine Bestimmungen
        'NISG-§1'  => [
            'Verfassungsbestimmung',
            'Bestätigt die Bundeskompetenz für Cybersicherheit; die Cybersicherheitsbehörde übt Befugnisse auch gegenüber obersten Organen des Bundes aus.',
            '1. Hauptstück — Allgemeine Bestimmungen',
        ],
        'NISG-§2'  => [
            'Gegenstand und Ziel',
            'Legt Maßnahmen zur Erreichung eines hohen Cybersicherheitsniveaus in 18 Sektoren fest (Energie, Verkehr, Bankwesen, Gesundheit, Wasser, Digitale Infrastruktur u. a.).',
            '1. Hauptstück — Allgemeine Bestimmungen',
        ],
        'NISG-§3'  => [
            'Begriffsbestimmungen',
            'Definiert zentrale Begriffe: Netz- und Informationssystem, Cybersicherheit, Schwachstelle, Risiko, Cyberbedrohung, Cybersicherheitsvorfall, CSIRT und verwandte Konzepte.',
            '1. Hauptstück — Allgemeine Bestimmungen',
        ],
        // 2. Hauptstück — Strukturen und Aufgaben (Behörden, CSIRTs, Koordinierung)
        'NISG-§3a' => [
            'Bundesamt für Cybersicherheit',
            'Errichtet die Cybersicherheitsbehörde (Bundesamt für Cybersicherheit) als dem Bundesminister für Inneres nachgeordnete Behörde mit bundesweiter Zuständigkeit.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§3b' => [
            'Organisation',
            'Regelungen zu Direktor, Stellvertreter, Sitz in Wien, Außenstellen, Geschäftseinteilung und Sicherheitsüberprüfungen der Bediensteten.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§4'  => [
            'Aufgaben der Cybersicherheitsbehörde',
            'Benennt 15 Aufgaben der Behörde: ÖSCS-Koordination, EU-Vertretung, Betrieb der zentralen Anlaufstelle, GovCERT-Betrieb, Vorfallmanagement und Lagebilderstellung.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§5'  => [
            'Zentrale Anlaufstelle',
            'Betreibt die zentrale Anlaufstelle für operative Verbindungen, Weitergabe von Meldungen und Registerauszüge an ENISA; unterrichtet andere Mitgliedstaaten bei grenzüberschreitenden Vorfällen.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§6'  => [
            'Nationales Koordinierungszentrum für Cybersicherheit',
            'Nimmt 13 Funktionen wahr: EU-Kompetenzzentrum-Betrieb, öffentlich-private Kooperation, Berichte, Sensibilisierungskampagnen, Risikobewertungen und strategische Planung.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§7'  => [
            'Unabhängige Stellen und unabhängige Prüfer',
            'Definiert Zulassungsvoraussetzungen für unabhängige Prüfer (Reife-/Diplomprüfung, 3 Jahre Berufserfahrung, Eignungsprüfung) und verpflichtet diese zur Vertraulichkeit.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§8'  => [
            'Zweck und Aufgaben der CSIRTs',
            'Benennt 8 Aufgaben der CSIRTs: Überwachung/Analyse von Bedrohungen, Warnungen/Alarme, Vorfallreaktion, forensische Datenerhebung, Schwachstellenscans und Teilnahme am CSIRTs-Netzwerk.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§9'  => [
            'Anforderungen und Eignung von CSIRTs',
            'Legt 8 Anforderungen an CSIRTs fest: sichere Kommunikationskanäle, Anfrageverwaltungssystem, Vertraulichkeit, geschultes Personal und Redundanzsysteme.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§10' => [
            'Aufsicht über CSIRTs',
            'CSIRTs unterliegen der Aufsicht der Cybersicherheitsbehörde; jährliche Berichte erforderlich; Überprüfungsermächtigung und Widerrufsrecht bei Nichtbefolgung.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§11' => [
            'Koordinierte Offenlegung von Schwachstellen',
            'Das nationale CSIRT koordiniert die Schwachstellenoffenlegung als vertrauenswürdiger Vermittler; anonyme Meldungen zulässig; Benachrichtigung der Aufsichtsstelle innerhalb 24 Stunden für qualifizierte Dienste.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§12' => [
            'Cyber Sicherheit Steuerungsgruppe (CSS)',
            'Zentrales strategisches Koordinierungsorgan unter Leitung der Cybersicherheitsbehörde; Aufgaben: ÖSCS-Entwicklung, Umsetzungsmonitoring und Berichterstattung.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§13' => [
            'Innerer Kreis der Operativen Koordinierungsstruktur (IKDOK)',
            'Erörtert und aktualisiert das Lagebild von Risiken, Bedrohungen und Vorfällen; ermöglicht Austausch klassifizierter Informationen zwischen Teilnehmern.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§14' => [
            'Operative Koordinierungsstruktur (OpKoord)',
            'Setzt sich aus IKDOK und CSIRTs zusammen; kann um wesentliche/wichtige Einrichtungen erweitert werden; Geheimhaltungsverpflichtung für externe Teilnehmer.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§15' => [
            'Nationale Cybersicherheitsstrategie (ÖSCS)',
            'Cybersicherheitsbehörde koordiniert die Erstellung der ÖSCS; von der Bundesregierung erlassen; enthält 13 Mindestinhalte (Ziele, Steuerungsrahmen, Risikobewertung, Cyberdiplomacie-Plan u. a.).',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§16' => [
            'Management von Cybersicherheitsvorfällen großen Ausmaßes',
            'Cybersicherheitsbehörde trägt Verantwortung für das Management großer Vorfälle; erstellt nationalen Reaktionsplan mit 6 Elementen; übermittelt Informationen an EU-Kommission/EU-CyCLONe.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§17' => [
            'Betrieb von IKT-Lösungen',
            'Cybersicherheitsbehörde betreibt IKT-Lösungen zur Früherkennung von Risiken, Bedrohungen und Vorfällen; wesentliche/wichtige Einrichtungen können freiwillig teilnehmen.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§20' => [
            'Zusammenarbeit auf nationaler Ebene',
            'Cybersicherheitsbehörde und CSIRTs kooperieren mit Kriminalpolizei, Staatsanwaltschaften, Finanzaufsicht und anderen Behörden; Unterrichtungspflichten vor Durchsetzungsmaßnahmen.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        'NISG-§22' => [
            'Internationale Zusammenarbeit',
            'Cybersicherheitsbehörde kooperiert mit zuständigen Stellen anderer Mitgliedstaaten und Drittländer; beteiligt sich an EU-Maßnahmen und kann internationale Vereinbarungen treffen.',
            '2. Hauptstück — Strukturen und Aufgaben',
        ],
        // 3. Hauptstück — Wesentliche und wichtige Einrichtungen (Kernpflichten)
        'NISG-§24' => [
            'Wesentliche und wichtige Einrichtungen',
            'Definiert wesentliche Einrichtungen (18 Sektoren nach Unternehmensgröße, Anlage 1) und wichtige Einrichtungen (andere Sektoren, Anlage 2); Einstufung nach Größe und Kritikalität.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§25' => [
            'Ermittlung der Unternehmensgröße',
            'Größenbestimmung nach Mitarbeiterzahl und Jahresumsatz gemäß KMU-Definition der Europäischen Kommission.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§26' => [
            'Größenunabhängige Einstufung',
            'Cybersicherheitsbehörde kann Einrichtungen als wesentlich oder wichtig einstufen, wenn Strom-/Infrastruktur-/Dienstversorgung oder Bevölkerung kritisch abhängig ist.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§27' => [
            'Ausnahmen aufgrund sektorspezifischer Rechtsakte',
            'Finanzsektor (DLT-Regulierung, Verordnung 2022/2554 DORA) und Telekommunikation können von bestimmten NISG-Pflichten ausgenommen sein, sofern gleichwertige sektorspezifische Regelungen bestehen.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§29' => [
            'Register der Einrichtungen',
            'Cybersicherheitsbehörde führt ein Register wesentlicher und wichtiger Einrichtungen; übermittelt Auszüge an ENISA; sensible Daten unterliegen der Geheimhaltung.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§31' => [
            'Governance',
            'Wesentliche und wichtige Einrichtungen etablieren eine Governance-Struktur mit Leitungsorgan-Verantwortung, Sicherheitsrichtlinien und regelmäßiger Überprüfung der Maßnahmen.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§32' => [
            'Risikomanagementmaßnahmen im Bereich der Cybersicherheit',
            'Einrichtungen treffen technische und organisatorische Maßnahmen zur Identifikation, Bewertung und Behandlung von Risiken; kontinuierliches Monitoring, Lieferkettensicherheit und Versicherungsoptionen.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§33' => [
            'Nachweis der Wirksamkeit von Risikomanagementmaßnahmen',
            'Wesentliche Einrichtungen: unabhängige externe Prüfung mindestens alle 2 Jahre; wichtige Einrichtungen: Selbstbewertung oder externe Prüfung mindestens alle 3 Jahre; Berichte der Cybersicherheitsbehörde zugänglich.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§34' => [
            'Berichtspflichten (Meldepflicht)',
            'Meldung erheblicher Cybersicherheitsvorfälle an Cybersicherheitsbehörde/CSIRT unverzüglich; enthält Vorfallbeschreibung, Daten, Auswirkungen; Meldung an Datenschutzbehörde erforderlich bei personenbezogenen Daten.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§35' => [
            'Erheblicher Cybersicherheitsvorfall',
            'Definiert erhebliche Cybersicherheitsvorfälle als Vorfälle mit wesentlicher Auswirkung durch Verfügbarkeitsverlust, Authentizitätsverletzung, Integritätsverletzung oder Vertraulichkeitsverlust.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§36' => [
            'Vereinbarungen über den Austausch von Informationen zur Cybersicherheit',
            'Einrichtungen können freiwillige Vereinbarungen zur Cybersicherheitsinformationsmeldung treffen; Vertraulichkeit und Schutz der Meldenden sind zu gewährleisten.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§37' => [
            'Freiwillige Meldung relevanter Informationen',
            'Einrichtungen können Cybersicherheitsinformationen (Risiken, Bedrohungen, Vorfälle, Beinahe-Vorfälle) freiwillig melden; Immunität für gutgläubig Meldende unter definierten Bedingungen.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§38' => [
            'Aufsichtsmaßnahmen gegenüber wesentlichen und wichtigen Einrichtungen',
            'Cybersicherheitsbehörde kann Auskunft fordern, Unterlagen verlangen, Inspektionen durchführen, Tests/Audits/Prüfungen anordnen und forensische Ermittlungen durchführen; Maßnahmen müssen verhältnismäßig sein.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§39' => [
            'Durchsetzungsmaßnahmen gegenüber wesentlichen und wichtigen Einrichtungen',
            'Bei Nichterfüllung: Verwaltungsstrafen bis 10 Mio. € oder 2 % des Jahresumsatzes für wesentliche Einrichtungen, bis 5 Mio. € oder 1 % für wichtige Einrichtungen.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        'NISG-§40' => [
            'Nutzung europäischer Schemata für Cybersicherheitszertifizierung',
            'Wesentliche und wichtige Einrichtungen können EU-Cybersicherheitszertifizierungsschemata nutzen; Cybersicherheitsbehörde erkennt Zertifikate als Nachweis der Risikomanagement-Wirksamkeit an.',
            '3. Hauptstück — Wesentliche und wichtige Einrichtungen',
        ],
        // 4. Hauptstück — Datenschutz
        'NISG-§42' => [
            'Datenverarbeitung',
            'Cybersicherheitsbehörde und CSIRTs dürfen personenbezogene Daten (einschl. sensibler Daten) zur Aufgabenerfüllung verarbeiten (DSGVO Art. 6 Abs. 1 lit. c/e); Zweckbindung und angemessene Schutzmaßnahmen erforderlich.',
            '4. Hauptstück — Datenschutz',
        ],
        // 5. Hauptstück — Strafbestimmungen
        'NISG-§44' => [
            'Allgemeine Bedingungen für die Verhängung von Geldstrafen',
            'Proportionalität ist zu beachten; Kriterien: Absichtlichkeit, Schwere des Verstoßes, erzielte Vorteile, Umsatz, Wiederholung und Kooperation mit der Behörde.',
            '5. Hauptstück — Strafbestimmungen',
        ],
        'NISG-§45' => [
            'Verwaltungsstrafbestimmungen',
            'Detaillierter Strafkatalog: Nichterfüllung der Governance-Anforderungen (bis 5 Mio. € / 1 % Umsatz für wesentliche Einrichtungen), Risikomanagement-Maßnahmen (bis 10 Mio. € / 2 %), Meldungs- und Prüfpflichten.',
            '5. Hauptstück — Strafbestimmungen',
        ],
        'NISG-§46' => [
            'Nichteinhaltung von Verpflichtungen durch Stellen der öffentlichen Verwaltung',
            'Öffentliche Verwaltungsstellen sind nicht geldstrafbar; Cybersicherheitsbehörde kann jedoch Anordnungen erlassen; Bericht an den zuständigen Bundesminister erforderlich.',
            '5. Hauptstück — Strafbestimmungen',
        ],
        // 6. Hauptstück — Schlussbestimmungen
        'NISG-§48' => [
            'Durchführung und Umsetzung von Rechtsakten der Europäischen Union',
            'Cybersicherheitsbehörde setzt EU-Rechtsakte um; Verordnungen zur Konkretisierung sind ermöglicht.',
            '6. Hauptstück — Schlussbestimmungen',
        ],
        'NISG-§50' => [
            'Vollziehung',
            'Bundesminister für Inneres ist für den Vollzug des NISG 2026 zuständig, mit Ausnahmen für spezialisierte Bundesminister in ihren Sektoren.',
            '6. Hauptstück — Schlussbestimmungen',
        ],
        'NISG-§51' => [
            'Inkrafttretens-, Außerkrafttretens- und Übergangsbestimmungen',
            'Gesetz tritt am 30.09.2026 in Kraft; NIS-Gesetz BGBl. I 2018/111 wird gleichzeitig aufgehoben; Übergangsregelungen für bestehende Registrierungen und Zulassungen.',
            '6. Hauptstück — Schlussbestimmungen',
        ],
    ];

    /** §§ mit hoher Compliance-Priorität (Kernpflichten für Einrichtungen). */
    private const HIGH_PRIORITY = ['NISG-§31', 'NISG-§32', 'NISG-§33', 'NISG-§34', 'NISG-§35', 'NISG-§38', 'NISG-§39', 'NISG-§45'];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return self::CODE;
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        // Upsert the framework row if absent.
        $framework = $this->frameworkRepository->findOneBy(['code' => self::CODE]);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode(self::CODE)
            ->setName('NISG 2026 — Netz- und Informationssystemsicherheitsgesetz 2026')
            ->setDescription(
                'Österreichisches Bundesgesetz zur Gewährleistung eines hohen Cybersicherheitsniveaus '
                . 'von Netz- und Informationssystemen (BGBl. I Nr. 94/2025). '
                . 'NIS-2-Richtlinie-Umsetzung für Österreich; tritt 30.09.2026 in Kraft. '
                . 'RIS-Gesetzesnummer 20013065 (ris.bka.gv.at). '
                . 'Amtliches Werk der Republik Österreich — freie Verwendung gemäß österreichischem Recht.'
            )
            ->setVersion('BGBl. I Nr. 94/2025')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Bundesamt für Cybersicherheit / Bundesminister für Inneres (AT)')
            ->setMandatory(true)
            ->setScopeDescription(
                'Gilt für wesentliche und wichtige Einrichtungen in 18 Sektoren (Anlage 1 + 2 NISG 2026) '
                . 'mit Sitz oder Vertretung in Österreich ab 30.09.2026.'
            )
            ->setActive(true);
        if ($isNew) {
            $this->em->persist($framework);
            $this->em->flush();
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0;
        $updated = 0;

        foreach (self::PARAGRAPHS as $reqId => [$title, $description, $category]) {
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $created++;
            } elseif (!$update) {
                $updated++;
                continue;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf(
                'NISG 2026 / %s — %s Quelle: BGBl. I Nr. 94/2025, RIS 20013065 (ris.bka.gv.at, abgerufen 2026-06-14).',
                $reqId,
                $description
            ));
            $req->setCategory($category);
            $req->setPriority(in_array($reqId, self::HIGH_PRIORITY, true) ? 'high' : 'medium');
            $this->em->persist($req);
        }

        $this->em->flush();
        $io?->success(sprintf(
            'NISG-AT: %d created, %d updated. Total catalogue: %d §§.',
            $created,
            $updated,
            count(self::PARAGRAPHS),
        ));

        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');

        return $this->loadRequirements($update, $io);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', null, InputOption::VALUE_NONE, 'Update existing requirement rows in place');
    }
}
