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
 * Registry-bound loader (`app.framework_loader`, code IKT-MINSTD-CH) for the
 * Swiss IKT-Minimalstandard (Minimalstandard zur Verbesserung der IKT-Resilienz),
 * Version Mai 2023.
 *
 * Publisher: Bundesamt fuer wirtschaftliche Landesversorgung (BWL) / now NCSC
 * Source:    https://www.ncsc.admin.ch/ncsc/de/home/infos-fuer/infos-unternehmen/aktuelle-themen/ikt-minimalstandards.html
 * License:   Swiss federal publication — freely usable for reference purposes.
 *
 * The standard is explicitly structured on the NIST Cybersecurity Framework (CSF)
 * five functions: Identifizieren (ID), Schuetzen (PR), Erkennen (DE),
 * Reagieren (RS), Wiederherstellen (RC). Measure IDs follow the NIST CSF 1.1
 * naming convention (e.g. ID.AM-1, PR.AC-3, DE.CM-4) with 108 measures total.
 *
 * IMPORTANT: getFrameworkCode() returns exactly 'IKT-MINSTD-CH'. This string
 * must match the source_framework/target_framework value in all mapping YAML
 * files and any DB-side findOneBy(['code'=>'IKT-MINSTD-CH']) lookups.
 */
#[AsCommand(
    name: 'app:load-ikt-minstd-ch-full',
    description: 'Load the Swiss IKT-Minimalstandard 2023 (BWL/NCSC, 108 measures) as ComplianceRequirement rows.'
)]
final class LoadIktMinstdChFullCommand extends Command implements FrameworkLoaderInterface
{
    /**
     * All 108 measures from IKT-Minimalstandard 2023, Version Mai 2023.
     * Source: NCSC / BWL official PDF (ikt-minimalstandard-2023-de.pdf).
     * IDs follow NIST CSF 1.1 naming; text is faithful German summary.
     *
     * @var array<string, array{category: string, function: string, title: string, text: string}>
     */
    private const MEASURES = [
        // ── IDENTIFIZIEREN (Identify) ──────────────────────────────────────

        // 2.2.1 Inventar Management (Asset Management)
        'ID.AM-1' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'IKT-Betriebsmittel inventarisieren',
            'text'     => 'Erarbeiten Sie einen Inventarisierungsprozess, welcher sicherstellt, dass zu jedem Zeitpunkt ein vollstaendiges Inventar Ihrer IKT-Betriebsmittel (Assets) vorhanden ist.',
        ],
        'ID.AM-2' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'Software und Applikationen inventarisieren',
            'text'     => 'Inventarisieren Sie all Ihre Softwareplattformen/-Lizenzen und Applikationen innerhalb Ihrer Organisation.',
        ],
        'ID.AM-3' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'Kommunikation und Datenfluesse abbilden',
            'text'     => 'Organisatorische Kommunikation und Datenfluesse werden abgebildet.',
        ],
        'ID.AM-4' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'Externe Informationssysteme katalogisieren',
            'text'     => 'Externe Informationssysteme werden katalogisiert.',
        ],
        'ID.AM-5' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'Ressourcen nach Kritikalitaet priorisieren',
            'text'     => 'Ressourcen (z. B. Hardware, Geraete, Daten, Zeit, Personal und Software) werden basierend auf ihrer Klassifizierung, Kritikalitaet und ihrem Geschaeftswert priorisiert.',
        ],
        'ID.AM-6' => [
            'function' => 'Identifizieren',
            'category' => 'Inventar Management (Asset Management)',
            'title'    => 'Cybersecurity-Rollen und -Verantwortlichkeiten festlegen',
            'text'     => 'Cybersecurity-Rollen und -Verantwortlichkeiten fuer die gesamte Belegschaft und externe Stakeholder (z. B. Lieferanten, Kunden, Partner) sind festgelegt.',
        ],

        // 2.2.2 Geschaeftsumfeld (Business Environment)
        'ID.BE-1' => [
            'function' => 'Identifizieren',
            'category' => 'Geschaeftsumfeld (Business Environment)',
            'title'    => 'Rolle in der Versorgungskette identifizieren',
            'text'     => 'Die Rolle ihres Unternehmens innerhalb der (kritischen) Versorgungskette ist identifiziert, dokumentiert und kommuniziert.',
        ],
        'ID.BE-2' => [
            'function' => 'Identifizieren',
            'category' => 'Geschaeftsumfeld (Business Environment)',
            'title'    => 'Bedeutung als kritische Infrastruktur kommunizieren',
            'text'     => 'Die Bedeutung der Organisation als kritische Infrastruktur und ihre Position innerhalb des kritischen Sektors ist identifiziert und kommuniziert.',
        ],
        'ID.BE-3' => [
            'function' => 'Identifizieren',
            'category' => 'Geschaeftsumfeld (Business Environment)',
            'title'    => 'Geschaeftsziele und -aktivitaeten priorisieren',
            'text'     => 'Die Ziele, Aufgaben und Aktivitaeten innerhalb der Organisation sind bewertet und priorisiert.',
        ],
        'ID.BE-4' => [
            'function' => 'Identifizieren',
            'category' => 'Geschaeftsumfeld (Business Environment)',
            'title'    => 'Abhaengigkeiten und kritische Funktionen bestimmen',
            'text'     => 'Abhaengigkeiten und kritische Funktionen fuer die Bereitstellung kritischer Dienste sind festgelegt.',
        ],
        'ID.BE-5' => [
            'function' => 'Identifizieren',
            'category' => 'Geschaeftsumfeld (Business Environment)',
            'title'    => 'Resilienzziele fuer alle Betriebszustaende festlegen',
            'text'     => 'Fuer alle Betriebszustaende (z. B. unter Zwang/Angriff, waehrend der Wiederherstellung, im Normalbetrieb) sind die Anforderungen an die Widerstandsfaehigkeit zur Unterstuetzung der Erbringung kritischer Dienste festgelegt.',
        ],

        // 2.2.3 Vorgaben (Governance)
        'ID.GV-1' => [
            'function' => 'Identifizieren',
            'category' => 'Vorgaben (Governance)',
            'title'    => 'Informationssicherheitspolitik festlegen',
            'text'     => 'Vorgaben zur Informationssicherheit sind im Unternehmen festgelegt und kommuniziert.',
        ],
        'ID.GV-2' => [
            'function' => 'Identifizieren',
            'category' => 'Vorgaben (Governance)',
            'title'    => 'Informationssicherheits-Rollen koordinieren',
            'text'     => 'Rollen und Verantwortlichkeiten im Bereich der Informationssicherheit sind mit internen Rollen (z. B. aus dem Riskmanagement) sowie externen Partnern koordiniert.',
        ],
        'ID.GV-3' => [
            'function' => 'Identifizieren',
            'category' => 'Vorgaben (Governance)',
            'title'    => 'Gesetzliche und regulatorische Cybersecurity-Vorgaben einhalten',
            'text'     => 'Stellen Sie sicher, dass Ihre Organisation alle gesetzlichen und regulatorischen Vorgaben im Bereich der Cybersecurity erfuellt, inkl. Vorgaben zum Datenschutz.',
        ],
        'ID.GV-4' => [
            'function' => 'Identifizieren',
            'category' => 'Vorgaben (Governance)',
            'title'    => 'Cyberrisiken im unternehmensweiten Risikomanagement verankern',
            'text'     => 'Stellen Sie sicher, dass Cyberrisiken Teil des unternehmensweiten Risikomanagements sind.',
        ],

        // 2.2.4 Risikoanalyse (Risk Assessment)
        'ID.RA-1' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Verwundbarkeiten der Betriebsmittel identifizieren',
            'text'     => 'Identifizieren Sie die (technischen) Verwundbarkeiten Ihrer Betriebsmittel und dokumentieren Sie diese.',
        ],
        'ID.RA-2' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Aktuelle Cyber-Bedrohungsinformationen einholen',
            'text'     => 'Aktuelle Informationen ueber Cyber-Bedrohungen werden durch regelmaessigen Austausch in Foren und Gremien erhalten.',
        ],
        'ID.RA-3' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Interne und externe Cyber-Bedrohungen identifizieren',
            'text'     => 'Identifizieren und dokumentieren Sie interne und externe Cyber-Bedrohungen.',
        ],
        'ID.RA-4' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Auswirkungen von Bedrohungen bewerten',
            'text'     => 'Identifizieren Sie moegliche Auswirkungen der Cyber-Bedrohungen auf die Geschaeftstaeigkeit und bewerten Sie ihre Eintretenswahrscheinlichkeiten.',
        ],
        'ID.RA-5' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Risiken fuer die Organisation bewerten',
            'text'     => 'Bewerten Sie die Risiken fuer Ihre Organisation, basierend auf den Bedrohungen, Verwundbarkeiten, Auswirkungen (auf die Geschaeftstaeigkeit) und Eintretenswahrscheinlichkeiten.',
        ],
        'ID.RA-6' => [
            'function' => 'Identifizieren',
            'category' => 'Risikoanalyse (Risk Assessment)',
            'title'    => 'Risikosofortmassnahmen definieren und priorisieren',
            'text'     => 'Definieren Sie moegliche Sofortmassnahmen bei Eintritt eines Risikos und priorisieren Sie diese.',
        ],

        // 2.2.5 Risikomanagementstrategie (Risk Management Strategy)
        'ID.RM-1' => [
            'function' => 'Identifizieren',
            'category' => 'Risikomanagementstrategie (Risk Management Strategy)',
            'title'    => 'Risikomanagementprozesse etablieren',
            'text'     => 'Etablieren Sie Risikomanagementprozesse, bewirtschaften Sie diese aktiv und lassen Sie sich diesen von den beteiligten Personen/Anspruchsgruppen bestaetigen.',
        ],
        'ID.RM-2' => [
            'function' => 'Identifizieren',
            'category' => 'Risikomanagementstrategie (Risk Management Strategy)',
            'title'    => 'Maximal tragbare Risiken definieren',
            'text'     => 'Definieren und kommunizieren Sie die maximal tragbaren Risiken Ihrer Organisation.',
        ],
        'ID.RM-3' => [
            'function' => 'Identifizieren',
            'category' => 'Risikomanagementstrategie (Risk Management Strategy)',
            'title'    => 'Sektorspezifische Risikoanalysen beruecksichtigen',
            'text'     => 'Stellen Sie sicher, dass die maximal tragbaren Risiken unter Beruecksichtigung der Bedeutung Ihrer Organisation als Betreiber einer kritischen Infrastruktur bewertet werden. Beruecksichtigen Sie dazu auch die sektorspezifischen Risikoanalysen.',
        ],

        // 2.2.6 Lieferketten-Risikomanagement (Supply Chain Risk Management)
        'ID.SC-1' => [
            'function' => 'Identifizieren',
            'category' => 'Lieferketten-Risikomanagement (Supply Chain Risk Management)',
            'title'    => 'Cyber-Supply-Chain-Risikomanagementprozesse etablieren',
            'text'     => 'Prozesse fuer das Risikomanagement in der Cyber-Supply-Chain sind identifiziert, etabliert, bewertet, verwaltet und von den organisatorischen Interessenvertretern vereinbart.',
        ],
        'ID.SC-2' => [
            'function' => 'Identifizieren',
            'category' => 'Lieferketten-Risikomanagement (Supply Chain Risk Management)',
            'title'    => 'Lieferanten nach Risiko priorisieren',
            'text'     => 'Lieferanten und Dienstleister von Informationssystemen, Komponenten und Dienstleistungen werden identifiziert, nach Prioritaeten geordnet und anhand eines Risikobewertungsprozesses fuer die Cyber-Lieferkette bewertet.',
        ],
        'ID.SC-3' => [
            'function' => 'Identifizieren',
            'category' => 'Lieferketten-Risikomanagement (Supply Chain Risk Management)',
            'title'    => 'Cybersicherheitsanforderungen in Lieferantenvertraegen verankern',
            'text'     => 'Vertraege mit Lieferanten und Drittparteien verpflichten diese, Massnahmen zur Erfuellung der Ziele des Cybersicherheitsprogramms und des Cyber-Lieferketten Risikomanagement Plans der Organisation umzusetzen und einzuhalten.',
        ],
        'ID.SC-4' => [
            'function' => 'Identifizieren',
            'category' => 'Lieferketten-Risikomanagement (Supply Chain Risk Management)',
            'title'    => 'Lieferanten-Compliance ueberwachen',
            'text'     => 'Etablieren Sie ein Monitoring, um sicherzustellen, dass all Ihre Lieferanten und Dienstleister ihre Verpflichtungen gemaeass den Vorgaben erfuellen. Lassen Sie sich dies regelmaessig in Audit-Berichten oder technischen Pruefergebnissen bestaetigen.',
        ],
        'ID.SC-5' => [
            'function' => 'Identifizieren',
            'category' => 'Lieferketten-Risikomanagement (Supply Chain Risk Management)',
            'title'    => 'Reaktions- und Wiederherstellungsprozesse mit Lieferanten definieren',
            'text'     => 'Definieren Sie mit Ihren Lieferanten und Dienstleistern Reaktions- und Widerherstellungsprozesse nach Cybersecurity-Vorfaellen. Testen Sie diese Prozesse in Uebungen.',
        ],

        // ── SCHUETZEN (Protect) ───────────────────────────────────────────

        // 2.3.1 Zugriffsmanagement und -steuerung (Access Control)
        'PR.AC-1' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Berechtigungen und Zugangsdaten verwalten',
            'text'     => 'Etablieren Sie einen klar definierten Prozess zur Erteilung und Verwaltung von Berechtigungen und Zugangsdaten fuer Benutzer, Geraete und Prozesse.',
        ],
        'PR.AC-2' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Physischen Zugriff auf IKT-Betriebsmittel schuetzen',
            'text'     => 'Stellen Sie sicher, dass nur autorisierte Personen physischen Zugriff auf die IKT-Betriebsmittel haben. Sorgen Sie mit (baulichen) Massnahmen dafuer, dass die IKT-Betriebsmittel vor unautorisiertem physischem Zugriff geschuetzt sind.',
        ],
        'PR.AC-3' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Fernzugriffsmanagement etablieren',
            'text'     => 'Etablieren Sie Prozesse zur Verwaltung der Fernzugriffe.',
        ],
        'PR.AC-4' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Minimale Berechtigungen und Aufgabentrennung durchsetzen',
            'text'     => 'Definieren sie Zugriffsberechtigungen und Autorisierungen unter Beruecksichtigung der Grundsaetze der geringsten Rechte und der Aufgabentrennung.',
        ],
        'PR.AC-5' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Netzwerkintegritaet schuetzen und segmentieren',
            'text'     => 'Stellen Sie sicher, dass die Integritaet Ihres Netzwerks geschuetzt ist. Segregieren Sie ihr Netzwerk logisch und physisch, wo notwendig und sinnvoll.',
        ],
        'PR.AC-6' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Digitale Identitaeten verifizierten Personen zuordnen',
            'text'     => 'Stellen Sie sicher, dass digitale Identitaeten eindeutig verifizierten Personen oder Prozessen zugeordnet sind.',
        ],
        'PR.AC-7' => [
            'function' => 'Schuetzen',
            'category' => 'Zugriffsmanagement und -steuerung (Access Control)',
            'title'    => 'Risikobasierte Authentifizierung einsetzen',
            'text'     => 'Die Authentifizierung von Benutzern, Geraeten und anderen Vermoogenswerten (z. B. Ein-Faktor- oder Mehr-Faktor-Authentifizierung) erfolgt entsprechend dem Risiko der Transaktion (z. B. Sicherheits- und Datenschutzrisiken fuer Einzelpersonen und andere Unternehmensrisiken).',
        ],

        // 2.3.2 Sensibilisierung und Ausbildung (Awareness and Training)
        'PR.AT-1' => [
            'function' => 'Schuetzen',
            'category' => 'Sensibilisierung und Ausbildung (Awareness and Training)',
            'title'    => 'Mitarbeitende im Bereich Cybersecurity schulen',
            'text'     => 'Stellen Sie sicher, dass alle Mitarbeitenden bezueglich Cybersecurity informiert und geschult sind.',
        ],
        'PR.AT-2' => [
            'function' => 'Schuetzen',
            'category' => 'Sensibilisierung und Ausbildung (Awareness and Training)',
            'title'    => 'Privilegierte Benutzer besonders sensibilisieren',
            'text'     => 'Stellen Sie sicher, dass Anwender mit hoeheren Berechtigungsstufen sich ihrer Rolle und Verantwortung besonders bewusst sind.',
        ],
        'PR.AT-3' => [
            'function' => 'Schuetzen',
            'category' => 'Sensibilisierung und Ausbildung (Awareness and Training)',
            'title'    => 'Externe Akteure ueber ihre Cybersecurity-Verantwortung informieren',
            'text'     => 'Stellen Sie sicher, dass sich alle beteiligten Akteure ausserhalb Ihres Unternehmens (Lieferanten, Kunden, Partner) ihrer Rolle und Verantwortung bewusst sind.',
        ],
        'PR.AT-4' => [
            'function' => 'Schuetzen',
            'category' => 'Sensibilisierung und Ausbildung (Awareness and Training)',
            'title'    => 'Fuehrungskraefte ueber ihre Cybersecurity-Verantwortung informieren',
            'text'     => 'Stellen Sie sicher, dass sich alle Fuehrungskraefte ihrer besonderen Rolle und Verantwortung bewusst sind.',
        ],
        'PR.AT-5' => [
            'function' => 'Schuetzen',
            'category' => 'Sensibilisierung und Ausbildung (Awareness and Training)',
            'title'    => 'Sicherheitsverantwortliche fachlich schulen',
            'text'     => 'Stellen Sie sicher, dass die Verantwortlichen fuer physische Sicherheit und Informationssicherheit sich ihrer besonderen Rolle und Verantwortung bewusst sind.',
        ],

        // 2.3.3 Datensicherheit (Data Security)
        'PR.DS-1' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Gespeicherte Daten schuetzen',
            'text'     => 'Stellen Sie sicher, dass gespeicherte Daten geschuetzt sind (vor Verletzungen der Vertraulichkeit, Integritaet und Verfuegbarkeit).',
        ],
        'PR.DS-2' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Daten bei der Uebertragung schuetzen',
            'text'     => 'Stellen Sie sicher, dass Daten waehrend der Uebertragung (vor Verletzungen der Vertraulichkeit, Integritaet und Verfuegbarkeit) geschuetzt sind.',
        ],
        'PR.DS-3' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Datenschutz bei Betriebsmittelentsorgung sicherstellen',
            'text'     => 'Stellen Sie sicher, dass fuer Ihre IKT-Betriebsmittel ein formaler Prozess etabliert ist, welcher die Daten bei Entfernung, Verschiebung oder Ersatz der Betriebsmittel schuetzt.',
        ],
        'PR.DS-4' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Ausreichende Kapazitaetsreserven sicherstellen',
            'text'     => 'Stellen Sie sicher, dass Ihre IKT-Betriebsmittel bezueglich der Verfuegbarkeit der Daten ueber ausreichende Kapazitaetsreserven verfuegen.',
        ],
        'PR.DS-5' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Datenlecks verhindern (Data Loss Prevention)',
            'text'     => 'Stellen Sie sicher, dass adaequate Massnahmen gegen den Abfluss von Daten (Datenlecks) implementiert sind.',
        ],
        'PR.DS-6' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Integritaet von Firmware, Betriebssystemen und Anwendungen verifizieren',
            'text'     => 'Etablieren Sie einen Prozess, um Firmware, Betriebssysteme, Anwendungssoftware und Daten hinsichtlich ihrer Integritaet zu verifizieren.',
        ],
        'PR.DS-7' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Entwicklungs- und Testumgebungen von Produktion trennen',
            'text'     => 'Stellen Sie eine IT-Umgebung fuer das Entwickeln und Testen zur Verfuegung, welche komplett unabhaengig von den produktiven Systemen ist.',
        ],
        'PR.DS-8' => [
            'function' => 'Schuetzen',
            'category' => 'Datensicherheit (Data Security)',
            'title'    => 'Hardware-Integritaet verifizieren',
            'text'     => 'Etablieren Sie einen Prozess, um die eingesetzte Hardware hinsichtlich ihrer Integritaet zu verifizieren.',
        ],

        // 2.3.4 Informationsschutzrichtlinien (Information Protection Processes and Procedures)
        'PR.IP-1' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Standardkonfiguration der IKT-Infrastruktur erstellen',
            'text'     => 'Erstellen Sie eine Standardkonfiguration fuer die Informations- und Kommunikationsinfrastruktur sowie fuer die industriellen Kontrollsysteme. Stellen Sie sicher, dass diese Standardkonfiguration typische Security-Prinzipien (z. B. N-1-Redundanz, Minimalkonfiguration etc.) einhaelt.',
        ],
        'PR.IP-2' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Lebenszyklus-Prozess fuer IKT-Betriebsmittel etablieren',
            'text'     => 'Etablieren Sie einen Lebenszyklus-Prozess fuer den Einsatz von IKT-Betriebsmitteln.',
        ],
        'PR.IP-3' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Konfigurationsaenderungen kontrollieren',
            'text'     => 'Etablieren Sie einen Prozess zur Kontrolle von Konfigurationsaenderungen.',
        ],
        'PR.IP-4' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Regelmaessige Datensicherungen (Backups) durchfuehren und testen',
            'text'     => 'Stellen Sie sicher, dass Sicherungen (Backups) Ihrer Informationen regelmaessig durchgefuehrt, bewirtschaftet und getestet werden (Rueckspielbarkeit der Backups testen).',
        ],
        'PR.IP-5' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Regulatorische Vorgaben zu physischen Betriebsmitteln einhalten',
            'text'     => 'Stellen Sie sicher, dass Sie alle (regulatorischen) Vorgaben und Richtlinien hinsichtlich den physischen Betriebsmitteln erfuellen.',
        ],
        'PR.IP-6' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Daten gemaess Vorgaben vernichten',
            'text'     => 'Stellen Sie sicher, dass Daten gemaess den Vorgaben vernichtet werden.',
        ],
        'PR.IP-7' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Informationssicherheitsprozesse kontinuierlich verbessern',
            'text'     => 'Stellen Sie sicher, dass Ihre Prozesse zur Informationssicherheit kontinuierlich weiterentwickelt und verbessert werden.',
        ],
        'PR.IP-8' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Effektivitaet von Schutztechnologien austauschen',
            'text'     => 'Tauschen Sie sich bezueglich der Effektivitaet verschiedener Schutztechnologien mit Ihren Partnern aus.',
        ],
        'PR.IP-9' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Incident-Response-Plan und Business-Continuity-Management etablieren',
            'text'     => 'Etablieren Sie Prozesse zur Reaktion auf eingetretene Cyber-Vorfaelle (Incident Response-Planning, Business Continuity Management, Incident Recovery, Disaster Recovery).',
        ],
        'PR.IP-10' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Reaktions- und Wiederherstellungsplaene testen',
            'text'     => 'Testen Sie die Reaktions- und Wiederherstellungsplaene.',
        ],
        'PR.IP-11' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Cybersecurity-Aspekte in Personalrekrutierung integrieren',
            'text'     => 'Etablieren Sie Aspekte der Cybersecurity bereits in den Personalrekrutierungsprozess (z. B. durch die Etablierung von Background-Checks/Personensicherheitspruefungen).',
        ],
        'PR.IP-12' => [
            'function' => 'Schuetzen',
            'category' => 'Informationsschutzrichtlinien (Information Protection Processes and Procedures)',
            'title'    => 'Prozess zum Umgang mit Schwachstellen entwickeln',
            'text'     => 'Entwickeln und implementieren Sie einen Prozess zum Umgang mit erkannten Schwachstellen.',
        ],

        // 2.3.5 Unterhalt (Maintenance)
        'PR.MA-1' => [
            'function' => 'Schuetzen',
            'category' => 'Unterhalt (Maintenance)',
            'title'    => 'Betrieb und Wartung der Betriebsmittel protokollieren',
            'text'     => 'Stellen Sie sicher, dass der Betrieb, die Wartung und allfaellige Reparaturen an den Betriebsmitteln aufgezeichnet und dokumentiert werden (Logging). Stellen Sie sicher, dass diese zeitnah durchgefuehrt werden und nur unter Einsatz von geprueften und freigegebenen Mitteln erfolgen.',
        ],
        'PR.MA-2' => [
            'function' => 'Schuetzen',
            'category' => 'Unterhalt (Maintenance)',
            'title'    => 'Fernwartung protokollieren und absichern',
            'text'     => 'Stellen Sie sicher, dass Unterhaltsarbeiten an Ihren Systemen, die ueber Fernzugriffe erfolgen, aufgezeichnet und dokumentiert werden. Stellen Sie sicher, dass kein unautorisierter Zugriff moeglich ist.',
        ],

        // 2.3.6 Einsatz von Schutztechnologie (Protective Technology)
        'PR.PT-1' => [
            'function' => 'Schuetzen',
            'category' => 'Einsatz von Schutztechnologie (Protective Technology)',
            'title'    => 'Audit- und Log-Aufzeichnungen definieren und pruefen',
            'text'     => 'Definieren Sie Vorgaben zu Audits und Log-Aufzeichnungen. Erstellen und pruefen Sie die regelmaessigen Logs gemaess den Vorgaben und Richtlinien.',
        ],
        'PR.PT-2' => [
            'function' => 'Schuetzen',
            'category' => 'Einsatz von Schutztechnologie (Protective Technology)',
            'title'    => 'Wechseldatentraeger schuetzen und kontrollieren',
            'text'     => 'Stellen Sie sicher, dass Wechseldatentraeger geschuetzt sind, und dass sie nur gemaess den Richtlinien eingesetzt werden.',
        ],
        'PR.PT-3' => [
            'function' => 'Schuetzen',
            'category' => 'Einsatz von Schutztechnologie (Protective Technology)',
            'title'    => 'Minimalfunktionalitaet der Systeme sicherstellen',
            'text'     => 'Stellen Sie sicher, dass Ihr System so konfiguriert ist, dass jederzeit eine Minimalfunktionalitaet gewaehrleistet wird.',
        ],
        'PR.PT-4' => [
            'function' => 'Schuetzen',
            'category' => 'Einsatz von Schutztechnologie (Protective Technology)',
            'title'    => 'Kommunikations- und Steuernetzwerke schuetzen',
            'text'     => 'Stellen Sie sicher, dass Ihre Kommunikations- und Steuernetzwerke geschuetzt sind.',
        ],
        'PR.PT-5' => [
            'function' => 'Schuetzen',
            'category' => 'Einsatz von Schutztechnologie (Protective Technology)',
            'title'    => 'Ausfallsicherheitsmechanismen implementieren',
            'text'     => 'Stellen sie sicher, dass Mechanismen (z. B. Ausfallsicherheit, Lastenausgleich, Hot-Swap) implementiert sind, um die Anforderungen an die Ausfallsicherheit in normalen und unguenstigen Situationen zu erfuellen.',
        ],

        // ── ERKENNEN (Detect) ─────────────────────────────────────────────

        // 2.4.1 Auffaelligkeiten und Vorfaelle (Anomalies and Events)
        'DE.AE-1' => [
            'function' => 'Erkennen',
            'category' => 'Auffaelligkeiten und Vorfaelle (Anomalies and Events)',
            'title'    => 'Standardwerte fuer Netzwerkoperationen und Datenfluesse definieren',
            'text'     => 'Definieren Sie Standardwerte fuer zulaessige Netzwerkoperationen und die zu erwartenden Datenfluesse fuer Anwender und Systeme. Managen Sie diese Werte fortlaufend.',
        ],
        'DE.AE-2' => [
            'function' => 'Erkennen',
            'category' => 'Auffaelligkeiten und Vorfaelle (Anomalies and Events)',
            'title'    => 'Cybersecurity-Vorfaelle analysieren',
            'text'     => 'Stellen Sie sicher, dass entdeckte Cybersecurity-Vorfaelle hinsichtlich ihrer Ziele und ihrer Methoden analysiert werden.',
        ],
        'DE.AE-3' => [
            'function' => 'Erkennen',
            'category' => 'Auffaelligkeiten und Vorfaelle (Anomalies and Events)',
            'title'    => 'Vorfallsinformationen aus mehreren Quellen aggregieren',
            'text'     => 'Stellen Sie sicher, dass Informationen zu Cybersecurity-Vorfaellen aus verschiedenen Quellen und Sensoren aggregiert und aufbereitet werden.',
        ],
        'DE.AE-4' => [
            'function' => 'Erkennen',
            'category' => 'Auffaelligkeiten und Vorfaelle (Anomalies and Events)',
            'title'    => 'Auswirkungen moeglicher Ereignisse bestimmen',
            'text'     => 'Bestimmen sie die Auswirkungen moeglicher Ereignisse.',
        ],
        'DE.AE-5' => [
            'function' => 'Erkennen',
            'category' => 'Auffaelligkeiten und Vorfaelle (Anomalies and Events)',
            'title'    => 'Schwellenwerte fuer Vorfallswarnungen festlegen',
            'text'     => 'Definieren sie Schwellenwerte die fuer Vorfallswarnungen festgelegt sind.',
        ],

        // 2.4.2 Ueberwachung (Security Continuous Monitoring)
        'DE.CM-1' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Kontinuierliches Netzwerkmonitoring etablieren',
            'text'     => 'Etablieren Sie ein kontinuierliches Netzwerkmonitoring, um potentielle Cybersecurity-Vorfaelle zu entdecken.',
        ],
        'DE.CM-2' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Physische Betriebsmittel und Gebaeude kontinuierlich ueberwachen',
            'text'     => 'Etablieren Sie ein kontinuierliches Monitoring/Ueberwachung aller physischen Betriebsmittel und Gebaeude, um Cybersecurity-Vorfaelle entdecken zu koennen.',
        ],
        'DE.CM-3' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Mitarbeiteraktivitaeten ueberwachen',
            'text'     => 'Die Aktivitaeten der Mitarbeiter werden ueberwacht, um potenzielle Cybersicherheitsvorfaelle zu erkennen.',
        ],
        'DE.CM-4' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Schadsoftware erkennen',
            'text'     => 'Stellen Sie sicher, dass Schadsoftware entdeckt werden kann.',
        ],
        'DE.CM-5' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Schadsoftware auf Mobilgeraeten erkennen',
            'text'     => 'Stellen Sie sicher, dass Schadsoftware auf Mobilgeraeten entdeckt werden kann.',
        ],
        'DE.CM-6' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Aktivitaeten externer Dienstleister ueberwachen',
            'text'     => 'Stellen Sie sicher, dass die Aktivitaeten von externen Dienstleistern ueberwacht werden, so dass Cybersecurity-Vorfaelle entdeckt werden koennen.',
        ],
        'DE.CM-7' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Unbefugte Aktivitaeten und Zugriffe erkennen',
            'text'     => 'Ueberwachen Sie ihre Systeme laufend, um sicherzustellen, dass Aktivitaeten/Zugriffe von unberechtigten Personen, Geraeten und Software erkannt werden.',
        ],
        'DE.CM-8' => [
            'function' => 'Erkennen',
            'category' => 'Ueberwachung (Security Continuous Monitoring)',
            'title'    => 'Verwundbarkeitsscans durchfuehren',
            'text'     => 'Fuehren Sie Verwundbarkeitsscans durch.',
        ],

        // 2.4.3 Detektionsprozess (Detection Processes)
        'DE.DP-1' => [
            'function' => 'Erkennen',
            'category' => 'Detektionsprozess (Detection Processes)',
            'title'    => 'Rollen und Verantwortlichkeiten fuer Detektion definieren',
            'text'     => 'Definieren Sie klare Rollen und Verantwortlichkeiten, so dass klar ist, wer wofuer zustaendig ist und wer welche Kompetenzen hat.',
        ],
        'DE.DP-2' => [
            'function' => 'Erkennen',
            'category' => 'Detektionsprozess (Detection Processes)',
            'title'    => 'Detektionsprozesse konform zu Vorgaben betreiben',
            'text'     => 'Stellen Sie sicher, dass die Detektionsprozesse alle Vorgaben und Bedingungen erfuellen.',
        ],
        'DE.DP-3' => [
            'function' => 'Erkennen',
            'category' => 'Detektionsprozess (Detection Processes)',
            'title'    => 'Detektionsprozesse testen',
            'text'     => 'Testen Sie Ihre Detektionsprozesse.',
        ],
        'DE.DP-4' => [
            'function' => 'Erkennen',
            'category' => 'Detektionsprozess (Detection Processes)',
            'title'    => 'Detektierte Vorfaelle an zustaendige Stellen kommunizieren',
            'text'     => 'Kommunizieren Sie detektierte Vorfaelle an die zustaendigen Stellen (z. B. Lieferanten, Kunden, Partner, Behoerden etc.).',
        ],
        'DE.DP-5' => [
            'function' => 'Erkennen',
            'category' => 'Detektionsprozess (Detection Processes)',
            'title'    => 'Detektionsprozesse kontinuierlich verbessern',
            'text'     => 'Verbessern Sie Ihre Detektionsprozesse kontinuierlich.',
        ],

        // ── REAGIEREN (Respond) ───────────────────────────────────────────

        // 2.5.1 Reaktionsplanung (Response Planning)
        'RS.RP-1' => [
            'function' => 'Reagieren',
            'category' => 'Reaktionsplanung (Response Planning)',
            'title'    => 'Reaktionsplan bei Cybersecurity-Vorfaellen ausfuehren',
            'text'     => 'Stellen Sie sicher, dass der Reaktionsplan waehrend oder nach einem detektierten Cybersecurity-Vorfall korrekt und zeitnah durchgefuehrt wird.',
        ],

        // 2.5.2 Kommunikation (Communications)
        'RS.CO-1' => [
            'function' => 'Reagieren',
            'category' => 'Kommunikation (Communications)',
            'title'    => 'Handlungsablaeufe bei Vorfaellen kennen',
            'text'     => 'Stellen Sie sicher, dass alle Personen ihre Aufgaben bezueglich der Reaktion und der Reihenfolge ihrer Handlungen auf eingetretene Cybersecurity-Vorfaelle kennen.',
        ],
        'RS.CO-2' => [
            'function' => 'Reagieren',
            'category' => 'Kommunikation (Communications)',
            'title'    => 'Meldepflichten bei Cybersecurity-Vorfaellen definieren',
            'text'     => 'Definieren Sie Kriterien fuer Meldungen und stellen Sie sicher, dass Cybersecurity-Vorfaelle gemaess diesen Kriterien gemeldet und bearbeitet werden.',
        ],
        'RS.CO-3' => [
            'function' => 'Reagieren',
            'category' => 'Kommunikation (Communications)',
            'title'    => 'Informationen zu Cybersecurity-Vorfaellen teilen',
            'text'     => 'Teilen Sie Informationen und Erkenntnisse zu detektierten Cybersecurity-Vorfaellen gemaess den definierten Kriterien.',
        ],
        'RS.CO-4' => [
            'function' => 'Reagieren',
            'category' => 'Kommunikation (Communications)',
            'title'    => 'Koordination mit Beteiligten und Anspruchsgruppen sicherstellen',
            'text'     => 'Die Koordinierung mit allen Beteiligten und den Anspruchsgruppen erfolgt im Einklang mit den Reaktionsplaenen gemaess den vordefinierten Kriterien.',
        ],
        'RS.CO-5' => [
            'function' => 'Reagieren',
            'category' => 'Kommunikation (Communications)',
            'title'    => 'Freiwilligen Informationsaustausch mit externen Akteuren foerdern',
            'text'     => 'Es werden regelmaessig freiwillig Informationen mit externen Akteuren ausgetauscht, um das Bewusstsein hinsichtlich der aktuellen Cybersicherheitssituation zu steigern.',
        ],

        // 2.5.3 Analyse (Analysis)
        'RS.AN-1' => [
            'function' => 'Reagieren',
            'category' => 'Analyse (Analysis)',
            'title'    => 'Benachrichtigungen aus Detektionssystemen untersuchen',
            'text'     => 'Stellen Sie sicher, dass Benachrichtigungen aus Detektionssystemen beruecksichtigt und Nachforschungen ausgeloest werden.',
        ],
        'RS.AN-2' => [
            'function' => 'Reagieren',
            'category' => 'Analyse (Analysis)',
            'title'    => 'Auswirkungen von Cybersecurity-Vorfaellen verstehen',
            'text'     => 'Stellen sie sicher, dass die Auswirkungen eines Cybersecurity-Vorfalls bekannt ist und verstanden wird.',
        ],
        'RS.AN-3' => [
            'function' => 'Reagieren',
            'category' => 'Analyse (Analysis)',
            'title'    => 'Forensische Analysen nach Vorfaellen durchfuehren',
            'text'     => 'Fuehren Sie nach einem eingetretenen Vorfall forensische Analysen durch.',
        ],
        'RS.AN-4' => [
            'function' => 'Reagieren',
            'category' => 'Analyse (Analysis)',
            'title'    => 'Vorfaelle kategorisieren',
            'text'     => 'Kategorisieren Sie eingetretene Vorfaelle gemaess den Vorgaben im Reaktionsplan.',
        ],
        'RS.AN-5' => [
            'function' => 'Reagieren',
            'category' => 'Analyse (Analysis)',
            'title'    => 'Schwachstellenmeldungen empfangen und verarbeiten',
            'text'     => 'Richten sie Prozesse ein, um Schwachstellen, die der Organisation aus internen und externen Quellen (z. B. interne Audits, Sicherheits-bulletins oder Sicherheitsforscher) bekannt werden, zu empfangen, zu analysieren und darauf zu reagieren.',
        ],

        // 2.5.4 Schadensminderung (Mitigation)
        'RS.MI-1' => [
            'function' => 'Reagieren',
            'category' => 'Schadensminderung (Mitigation)',
            'title'    => 'Cybersecurity-Vorfaelle eindaemmen',
            'text'     => 'Stellen Sie sicher, dass Cybersecurity-Vorfaelle eingegrenzt werden koennen und die weitere Ausbreitung unterbrochen wird.',
        ],
        'RS.MI-2' => [
            'function' => 'Reagieren',
            'category' => 'Schadensminderung (Mitigation)',
            'title'    => 'Auswirkungen von Vorfaellen mindern',
            'text'     => 'Stellen Sie sicher, dass die Auswirkungen von Cybersecurity-Vorfaellen gemindert werden koennen.',
        ],
        'RS.MI-3' => [
            'function' => 'Reagieren',
            'category' => 'Schadensminderung (Mitigation)',
            'title'    => 'Neu identifizierte Verwundbarkeiten reduzieren',
            'text'     => 'Stellen Sie sicher, dass neu identifizierte Verwundbarkeiten reduziert oder als akzeptierte Risiken dokumentiert werden.',
        ],

        // 2.5.5 Verbesserungen (Improvements)
        'RS.IM-1' => [
            'function' => 'Reagieren',
            'category' => 'Verbesserungen — Reagieren (Improvements)',
            'title'    => 'Lehren aus Vorfaellen in Reaktionsplaene einfliessen lassen',
            'text'     => 'Stellen Sie sicher, dass Erkenntnisse und Lehren aus vorangegangenen Cybersecurity-Vorfaellen in Ihre Reaktionsplaene einfliessen.',
        ],
        'RS.IM-2' => [
            'function' => 'Reagieren',
            'category' => 'Verbesserungen — Reagieren (Improvements)',
            'title'    => 'Reaktionsstrategien aktualisieren',
            'text'     => 'Aktualisieren Sie Ihre Reaktionsstrategien.',
        ],

        // ── WIEDERHERSTELLEN (Recover) ────────────────────────────────────

        // 2.6.1 Wiederherstellungsplanung (Recovery Planning)
        'RC.RP-1' => [
            'function' => 'Wiederherstellen',
            'category' => 'Wiederherstellungsplanung (Recovery Planning)',
            'title'    => 'Wiederherstellungsplan nach Cybersecurity-Vorfall ausfuehren',
            'text'     => 'Stellen Sie sicher, dass der Wiederherstellungsplan nach einem eingetretenen Cybersecurity-Vorfall korrekt durchgefuehrt werden kann.',
        ],

        // 2.6.2 Verbesserungen (Improvements)
        'RC.IM-1' => [
            'function' => 'Wiederherstellen',
            'category' => 'Verbesserungen — Wiederherstellen (Improvements)',
            'title'    => 'Lehren aus Vorfaellen in Wiederherstellungsplaene einfliessen lassen',
            'text'     => 'Stellen Sie sicher, dass Erkenntnisse und Lehren aus frueheren Cybersecurity-Vorfaellen in Ihre Wiederherstellungsplaene einfliessen.',
        ],
        'RC.IM-2' => [
            'function' => 'Wiederherstellen',
            'category' => 'Verbesserungen — Wiederherstellen (Improvements)',
            'title'    => 'Wiederherstellungsstrategie aktualisieren',
            'text'     => 'Aktualisieren Sie Ihre Wiederherstellungsstrategie.',
        ],

        // 2.6.3 Kommunikation (Communications)
        'RC.CO-1' => [
            'function' => 'Wiederherstellen',
            'category' => 'Kommunikation — Wiederherstellen (Communications)',
            'title'    => 'Oeffentliche Wahrnehmung nach Vorfaellen managen',
            'text'     => 'Stellen Sie sicher, dass Ihre oeffentliche Wahrnehmung aktiv angegangen wird.',
        ],
        'RC.CO-2' => [
            'function' => 'Wiederherstellen',
            'category' => 'Kommunikation — Wiederherstellen (Communications)',
            'title'    => 'Reputation nach Cybersecurity-Vorfall wiederherstellen',
            'text'     => 'Stellen Sie sicher, dass Ihre Organisation nach einem eingetretenen Cybersecurity-Vorfall wieder positiv wahrgenommen wird.',
        ],
        'RC.CO-3' => [
            'function' => 'Wiederherstellen',
            'category' => 'Kommunikation — Wiederherstellen (Communications)',
            'title'    => 'Wiederherstellungsaktivitaeten intern kommunizieren',
            'text'     => 'Kommunizieren Sie alle Ihre Wiederherstellungsaktivitaeten an die internen Anspruchsgruppen, insbesondere auch an das Management/die Geschaeftsleitung.',
        ],
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'IKT-MINSTD-CH';
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $framework = $this->resolveFramework();
        $reqRepo   = $this->em->getRepository(ComplianceRequirement::class);

        $created = 0;
        $updated = 0;

        foreach (self::MEASURES as $reqId => $row) {
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority($this->derivePriority($reqId));
                $created++;
            } else {
                if (!$update) {
                    continue;
                }
                $updated++;
            }

            $req->setTitle(mb_substr($row['title'], 0, 250));
            $req->setDescription(sprintf(
                'IKT-MINSTD-CH / %s — %s. Funktion: %s. Quelle: Minimalstandard zur Verbesserung der IKT-Resilienz, Version Mai 2023, NCSC / BWL (Bundesamt fuer wirtschaftliche Landesversorgung), Schweiz.',
                $reqId,
                $row['text'],
                $row['function'],
            ));
            $req->setCategory(mb_substr($row['category'], 0, 100));
            $this->em->persist($req);
        }

        $this->em->flush();

        $io?->success(sprintf(
            'IKT-MINSTD-CH: %d created, %d updated (update=%s). Total measures: %d.',
            $created,
            $updated,
            $update ? 'yes' : 'no',
            count(self::MEASURES),
        ));

        return Command::SUCCESS;
    }

    /**
     * Priority heuristic: Governance, Risk Assessment and Access Control
     * measures are high; detect/respond core measures are high; others medium.
     */
    private function derivePriority(string $reqId): string
    {
        if (str_starts_with($reqId, 'ID.GV')
            || str_starts_with($reqId, 'ID.RA')
            || str_starts_with($reqId, 'ID.RM')
            || str_starts_with($reqId, 'PR.AC')
            || str_starts_with($reqId, 'RS.RP')
            || str_starts_with($reqId, 'RS.MI')
            || str_starts_with($reqId, 'RC.RP')
        ) {
            return 'high';
        }

        return 'medium';
    }

    private function resolveFramework(): ComplianceFramework
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'IKT-MINSTD-CH']);
        if ($framework instanceof ComplianceFramework) {
            return $framework;
        }

        $framework = new ComplianceFramework();
        $framework
            ->setCode('IKT-MINSTD-CH')
            ->setName('IKT-Minimalstandard zur Verbesserung der IKT-Resilienz (Schweiz)')
            ->setDescription(
                'Schweizerischer Minimalstandard zur Verbesserung der IKT-Resilienz, Version Mai 2023. '
                . 'Herausgegeben vom Bundesamt fuer wirtschaftliche Landesversorgung (BWL) / NCSC. '
                . 'Basiert auf dem NIST Cybersecurity Framework (CSF) und umfasst 108 Massnahmen in '
                . 'fuenf Funktionen: Identifizieren, Schuetzen, Erkennen, Reagieren, Wiederherstellen. '
                . 'Empfohlen fuer Betreiber kritischer Infrastrukturen in der Schweiz; grundsaetzlich '
                . 'fuer jedes Unternehmen oder jede Organisation anwendbar.'
            )
            ->setVersion('2023')
            ->setApplicableIndustry('critical_infrastructure')
            ->setRegulatoryBody('NCSC / BWL — Bundesamt fuer wirtschaftliche Landesversorgung, Schweiz')
            ->setMandatory(false)
            ->setScopeDescription('Betreiber kritischer Infrastrukturen in der Schweiz; empfohlen fuer alle Unternehmen und Organisationen.')
            ->setActive(true);

        $this->em->persist($framework);

        return $framework;
    }
}
