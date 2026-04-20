<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BSI IT-Grundschutz-Kompendium 2023 — extended seeder.
 *
 * Erweitert den Delta-Loader um weitere 20 Bausteine mit ihren
 * Basis-Anforderungen (A1–A3 / MUSS), die für Mittelstands-Audits
 * regelmäßig relevant sind:
 *
 *   CON.11   Kommunikation
 *   OPS.1.2.3 Patch- und Änderungsmanagement
 *   OPS.2.2  Cloud-Nutzung
 *   APP.3.2  Webserver
 *   APP.3.3  Fileserver
 *   APP.3.6  DNS-Server
 *   APP.4.2  Office-Produkte
 *   APP.5.3  Mobile Anwendungen (Apps)
 *   SYS.1.2.3 Windows Server 2022 (Nachfolger)
 *   SYS.1.3  Linux-Server (ergänzt)
 *   SYS.1.8  Storage-Lösungen
 *   SYS.1.9  Terminalserver
 *   SYS.2.4  macOS Client
 *   SYS.3.1  Laptops
 *   SYS.3.2.2 Mobile Device Management
 *   SYS.4.1  Drucker / Multifunktionsgeräte
 *   SYS.4.5  Wechseldatenträger
 *   NET.4.1  Telekommunikationsanlagen
 *   NET.4.3  Router und Switches
 *   INF.13   Technischer Raum
 *
 * Command bleibt idempotent (skip wenn requirementId bereits vorhanden).
 *
 * **Scope-Hinweis:** Das Kompendium 2023 umfasst ca. 1 100 Anforderungen.
 * Dieses Command + die beiden Vorgänger-Loader decken ~220 Anforderungen
 * kuratiert ab (alle wichtigsten Basis-Anforderungen für Mittelstands-
 * Audits). Für **byte-exakte Kompendium-Parität** ist ein Import des
 * offiziellen BSI-Grundschutz-XML-Profils erforderlich — das liegt
 * außerhalb dieser Seed-Command-Kaskade. BSI publiziert das XML als
 * Teil des Kompendium-Download-Pakets.
 */
#[AsCommand(
    name: 'app:load-bsi-kompendium-extended',
    description: 'Erweitert BSI IT-Grundschutz um ~80 zusätzliche Anforderungen aus 20 Bausteinen (Kompendium 2023)'
)]
class LoadBsiKompendium2023ExtendedCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);

        if (!$framework instanceof ComplianceFramework) {
            $io->error('BSI IT-Grundschutz framework nicht geladen. Starten Sie zuerst app:load-bsi-grundschutz-requirements.');
            return Command::FAILURE;
        }

        $io->info('Lade ergänzende BSI-Kompendium-2023-Anforderungen (Extended)…');

        $rows = $this->requirements();
        $added = 0;
        $skipped = 0;

        try {
            $this->entityManager->beginTransaction();

            $repo = $this->entityManager->getRepository(ComplianceRequirement::class);

            foreach ($rows as $row) {
                $existing = $repo->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $row['id'],
                ]);
                if ($existing instanceof ComplianceRequirement) {
                    $skipped++;
                    continue;
                }

                $req = new ComplianceRequirement();
                $req->setFramework($framework)
                    ->setRequirementId($row['id'])
                    ->setTitle($row['title'])
                    ->setDescription($row['description'])
                    ->setCategory($row['category'])
                    ->setPriority($row['priority'])
                    ->setDataSourceMapping($row['data_source_mapping']);

                if (isset($row['absicherungsStufe'])) {
                    $req->setAbsicherungsStufe($row['absicherungsStufe']);
                }

                $this->entityManager->persist($req);
                $added++;
            }

            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->success(sprintf(
                'Extended-Load abgeschlossen: %d neu, %d übersprungen (bereits vorhanden).',
                $added,
                $skipped
            ));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $io->error('Extended-Load fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{
     *     id: string, title: string, description: string, category: string,
     *     priority: string, data_source_mapping: array,
     *     absicherungsStufe?: string
     * }>
     */
    private function requirements(): array
    {
        return array_merge(
            $this->conRequirements(),
            $this->opsRequirements(),
            $this->appRequirements(),
            $this->sysRequirements(),
            $this->netRequirements(),
            $this->infRequirements(),
        );
    }

    private function conRequirements(): array
    {
        return [
            [
                'id' => 'CON.11.A1',
                'title' => 'Regelung der Kommunikation',
                'description' => 'Für die Kommunikation MÜSSEN Regelungen festgelegt werden, einschließlich zulässiger Kanäle, Verschlüsselungsanforderungen und Klassifizierungsstufen.',
                'category' => 'CON.11 Kommunikation',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.14', '8.20']],
            ],
            [
                'id' => 'CON.11.A2',
                'title' => 'Sichere Übertragung vertraulicher Informationen',
                'description' => 'Vertrauliche Informationen MÜSSEN bei der Übertragung durch kryptographische Verfahren geschützt werden.',
                'category' => 'CON.11 Kommunikation',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.24']],
            ],
        ];
    }

    private function opsRequirements(): array
    {
        return [
            [
                'id' => 'OPS.1.2.3.A1',
                'title' => 'Konzept für Patch- und Änderungsmanagement',
                'description' => 'Für das Patch- und Änderungsmanagement MUSS ein schriftliches Konzept existieren, das Prozessschritte, Verantwortlichkeiten und Zeitvorgaben festlegt.',
                'category' => 'OPS.1.2.3 Patch- und Änderungsmanagement',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.8', '8.32']],
            ],
            [
                'id' => 'OPS.1.2.3.A2',
                'title' => 'Umgang mit sicherheitsrelevanten Patches',
                'description' => 'Sicherheitsrelevante Patches MÜSSEN innerhalb definierter Fristen getestet und eingespielt werden. Die Fristen MÜSSEN an der Kritikalität der Schwachstelle orientiert sein.',
                'category' => 'OPS.1.2.3 Patch- und Änderungsmanagement',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.8'], 'entity' => 'Patch'],
            ],
            [
                'id' => 'OPS.1.2.3.A3',
                'title' => 'Funktionstest und Freigabe von Patches',
                'description' => 'Vor dem produktiven Einsatz MÜSSEN Patches auf einem Testsystem erprobt und durch den Verantwortlichen freigegeben werden.',
                'category' => 'OPS.1.2.3 Patch- und Änderungsmanagement',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.8', '8.31']],
            ],
            [
                'id' => 'OPS.2.2.A1',
                'title' => 'Erstellung einer Cloud-Strategie',
                'description' => 'Vor der Nutzung von Cloud-Diensten MUSS eine Cloud-Strategie erstellt werden, die Auswahlkriterien, Exit-Anforderungen und Datenschutz-Rahmenbedingungen regelt.',
                'category' => 'OPS.2.2 Cloud-Nutzung',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.23', '5.30'], 'audit_evidence' => true],
            ],
            [
                'id' => 'OPS.2.2.A2',
                'title' => 'Auswahl geeigneter Cloud-Dienste',
                'description' => 'Cloud-Dienste MÜSSEN anhand vorher definierter Kriterien (Sicherheit, Verfügbarkeit, Compliance, Exit-Möglichkeiten) ausgewählt und dokumentiert werden.',
                'category' => 'OPS.2.2 Cloud-Nutzung',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.23'], 'entity' => 'Supplier'],
            ],
            [
                'id' => 'OPS.2.2.A3',
                'title' => 'Vertragliche Gestaltung der Cloud-Nutzung',
                'description' => 'Vertragliche Regelungen mit dem Cloud-Dienstleister MÜSSEN sicherheitsrelevante Anforderungen, SLA, Datenortsgarantien und Audit-Rechte enthalten.',
                'category' => 'OPS.2.2 Cloud-Nutzung',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.20', '5.23'], 'entity' => 'Supplier'],
            ],
        ];
    }

    private function appRequirements(): array
    {
        return [
            // APP.3.2 Webserver
            [
                'id' => 'APP.3.2.A1',
                'title' => 'Sichere Konfiguration eines Webservers',
                'description' => 'Ein Webserver MUSS sicher konfiguriert werden. Nicht benötigte Module, Dienste und Funktionen MÜSSEN deaktiviert sein.',
                'category' => 'APP.3.2 Webserver',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9'], 'entity' => 'Asset'],
            ],
            [
                'id' => 'APP.3.2.A2',
                'title' => 'Schutz der Webserver-Dateien und -Skripte',
                'description' => 'Die Dateien und Skripte des Webservers MÜSSEN vor unberechtigter Veränderung geschützt werden. Rechte MÜSSEN minimal gesetzt sein.',
                'category' => 'APP.3.2 Webserver',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.3', '8.9']],
            ],
            [
                'id' => 'APP.3.2.A5',
                'title' => 'Protokollierung von Ereignissen',
                'description' => 'Sicherheitsrelevante Ereignisse am Webserver MÜSSEN protokolliert werden. Die Protokolle MÜSSEN regelmäßig ausgewertet werden.',
                'category' => 'APP.3.2 Webserver',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            // APP.3.3 Fileserver
            [
                'id' => 'APP.3.3.A1',
                'title' => 'Geeignete Auswahl des Fileservers',
                'description' => 'Vor dem Einsatz MUSS geprüft werden, welcher Fileserver-Typ (SMB, NFS, objektbasiert) die funktionalen und sicherheitstechnischen Anforderungen erfüllt.',
                'category' => 'APP.3.3 Fileserver',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9'], 'entity' => 'Asset'],
            ],
            [
                'id' => 'APP.3.3.A2',
                'title' => 'Sichere Grundkonfiguration eines Fileservers',
                'description' => 'Ein Fileserver MUSS sicher grundkonfiguriert werden. Zugriffsrechte MÜSSEN nach dem Prinzip der geringsten Rechte gesetzt sein.',
                'category' => 'APP.3.3 Fileserver',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.15', '8.3', '8.9']],
            ],
            [
                'id' => 'APP.3.3.A3',
                'title' => 'Kontrollierte Einrichtung von Freigaben',
                'description' => 'Freigaben auf einem Fileserver MÜSSEN kontrolliert eingerichtet werden. Anonyme Zugriffe SOLLTEN nicht zulässig sein.',
                'category' => 'APP.3.3 Fileserver',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.15', '8.3']],
            ],
            // APP.3.6 DNS-Server
            [
                'id' => 'APP.3.6.A1',
                'title' => 'Planung des DNS-Einsatzes',
                'description' => 'Vor dem Einsatz eines DNS-Servers MUSS geplant werden, welche Dienste bereitgestellt werden, welche Master-/Slave-Rollen existieren und welche Zonen verwaltet werden.',
                'category' => 'APP.3.6 DNS-Server',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9', '8.21']],
            ],
            [
                'id' => 'APP.3.6.A2',
                'title' => 'Sichere Grundkonfiguration eines DNS-Servers',
                'description' => 'Ein DNS-Server MUSS gehärtet konfiguriert werden. Nicht benötigte Funktionen (wie Zonentransfers an Unbekannte) MÜSSEN deaktiviert sein.',
                'category' => 'APP.3.6 DNS-Server',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9', '8.21']],
            ],
            [
                'id' => 'APP.3.6.A5',
                'title' => 'Überwachung von DNS-Servern',
                'description' => 'DNS-Server MÜSSEN auf Verfügbarkeit und Anomalien überwacht werden. Unerwartete Änderungen an Zonendaten MÜSSEN erkannt werden.',
                'category' => 'APP.3.6 DNS-Server',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.16']],
            ],
            // APP.4.2 Office-Produkte (generisch, nicht nur MS)
            [
                'id' => 'APP.4.2.A1',
                'title' => 'Auswahl und Freigabe von Office-Produkten',
                'description' => 'Für den produktiven Einsatz MÜSSEN ausschließlich Office-Produkte verwendet werden, die offiziell freigegeben wurden. Nicht freigegebene Office-Software DARF NICHT installiert werden.',
                'category' => 'APP.4.2 Office-Produkte',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.9', '8.19']],
            ],
            [
                'id' => 'APP.4.2.A2',
                'title' => 'Einschränkung von Makrofunktionen',
                'description' => 'Makros und aktive Inhalte MÜSSEN standardmäßig deaktiviert sein. Signierte Makros dürfen nur nach Prüfung und Freigabe durch zentrale IT-Rollen zugelassen werden.',
                'category' => 'APP.4.2 Office-Produkte',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.19', '8.22']],
            ],
            [
                'id' => 'APP.4.2.A3',
                'title' => 'Dokumentenprüfung auf Metadaten',
                'description' => 'Office-Dokumente, die das Unternehmen verlassen, SOLLTEN auf versehentlich enthaltene Metadaten (Kommentare, Änderungshistorie, Autor) geprüft werden.',
                'category' => 'APP.4.2 Office-Produkte',
                'priority' => 'medium',
                'absicherungsStufe' => 'standard',
                'data_source_mapping' => ['iso_controls' => ['5.12', '5.14']],
            ],
            // APP.5.3 Mobile Apps
            [
                'id' => 'APP.5.3.A1',
                'title' => 'Sicherheitsrichtlinie für Mobile Apps',
                'description' => 'Für den Einsatz mobiler Apps MUSS eine Richtlinie festgelegt werden, die zulässige App-Quellen, Freigabe-Prozess und Schutzmaßnahmen regelt.',
                'category' => 'APP.5.3 Mobile Anwendungen',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.19']],
            ],
            [
                'id' => 'APP.5.3.A2',
                'title' => 'Sichere Beschaffung und Installation mobiler Apps',
                'description' => 'Apps MÜSSEN aus vertrauenswürdigen Quellen (MDM-Katalog, offizielle Stores mit Unternehmens-Account) bezogen werden. Seitliche Installationen („sideloading") MÜSSEN unterbunden sein.',
                'category' => 'APP.5.3 Mobile Anwendungen',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.19', '8.32']],
            ],
        ];
    }

    private function sysRequirements(): array
    {
        return [
            // SYS.1.2.3 Windows Server 2022
            [
                'id' => 'SYS.1.2.3.A1',
                'title' => 'Planung eines Windows Server 2022',
                'description' => 'Vor dem Einsatz eines Windows Server 2022 MUSS geplant werden, welche Rollen betrieben werden und welche Härtungsmaßnahmen umzusetzen sind.',
                'category' => 'SYS.1.2.3 Windows Server 2022',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9'], 'entity' => 'Asset'],
            ],
            [
                'id' => 'SYS.1.2.3.A2',
                'title' => 'Sichere Grundkonfiguration eines Windows Server 2022',
                'description' => 'Ein Windows Server 2022 MUSS sicher grundkonfiguriert werden. Nicht benötigte Dienste und Rollen MÜSSEN deaktiviert sein. Administrative Freigaben MÜSSEN beschränkt sein.',
                'category' => 'SYS.1.2.3 Windows Server 2022',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],
            [
                'id' => 'SYS.1.2.3.A5',
                'title' => 'Schutz des lokalen Administrator-Kontos',
                'description' => 'Das lokale Administrator-Konto MUSS mit einem starken, einzigartigen Passwort geschützt und die Anmeldung MUSS auf das Notwendige begrenzt sein.',
                'category' => 'SYS.1.2.3 Windows Server 2022',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.15', '5.17']],
            ],
            // SYS.1.3 Linux-Server Erweiterung
            [
                'id' => 'SYS.1.3.A10',
                'title' => 'Mandantentrennung auf Unix-Servern',
                'description' => 'Ist ein Unix-Server von mehreren Nutzergruppen benutzbar, MUSS die Mandantentrennung durch geeignete Mechanismen (z. B. Container, getrennte Benutzergruppen) umgesetzt werden.',
                'category' => 'SYS.1.3 Server unter Linux und Unix',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'data_source_mapping' => ['iso_controls' => ['5.3', '8.22']],
            ],
            [
                'id' => 'SYS.1.3.A11',
                'title' => 'Protokollierung auf Unix-Servern',
                'description' => 'Auf einem Unix-Server MÜSSEN sicherheitsrelevante Ereignisse (Login, Sudo, Fehler) protokolliert und die Protokolle zentral gesichert werden.',
                'category' => 'SYS.1.3 Server unter Linux und Unix',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            // SYS.1.8 Storage
            [
                'id' => 'SYS.1.8.A1',
                'title' => 'Anforderungsanalyse für Storage',
                'description' => 'Vor der Beschaffung einer Storage-Lösung MÜSSEN die Anforderungen an Kapazität, Verfügbarkeit, Redundanz und Sicherheit erhoben und dokumentiert werden.',
                'category' => 'SYS.1.8 Storage-Lösungen',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.6'], 'entity' => 'Asset'],
            ],
            [
                'id' => 'SYS.1.8.A2',
                'title' => 'Sichere Konfiguration von Storage-Lösungen',
                'description' => 'Storage-Lösungen MÜSSEN sicher konfiguriert werden. Administrative Schnittstellen DÜRFEN NUR aus vertrauenswürdigen Netzen erreichbar sein.',
                'category' => 'SYS.1.8 Storage-Lösungen',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9', '8.22']],
            ],
            // SYS.1.9 Terminalserver
            [
                'id' => 'SYS.1.9.A1',
                'title' => 'Planung des Terminalserver-Einsatzes',
                'description' => 'Der Einsatz von Terminalservern MUSS geplant werden: unterstützte Clients, verwendete Protokolle (RDP, ICA), Lastverteilung und Lizenzierung.',
                'category' => 'SYS.1.9 Terminalserver',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],
            [
                'id' => 'SYS.1.9.A3',
                'title' => 'Absicherung der Terminalserver-Kommunikation',
                'description' => 'Die Kommunikation zwischen Terminalclient und -server MUSS verschlüsselt erfolgen. Die Integrität der Sitzung MUSS sichergestellt werden.',
                'category' => 'SYS.1.9 Terminalserver',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.24']],
            ],
            // SYS.2.4 macOS Client
            [
                'id' => 'SYS.2.4.A1',
                'title' => 'Planung des Einsatzes von macOS-Clients',
                'description' => 'Vor dem Einsatz von macOS-Clients MUSS geplant werden, welche Anwendungsfälle unterstützt werden, welche MDM-Lösung eingesetzt wird und welche Sicherheitsmaßnahmen Pflicht sind.',
                'category' => 'SYS.2.4 Clients unter macOS',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.9']],
            ],
            [
                'id' => 'SYS.2.4.A2',
                'title' => 'Sichere Grundkonfiguration von macOS-Clients',
                'description' => 'macOS-Clients MÜSSEN über eine Verwaltungslösung (MDM) sicher grundkonfiguriert werden. FileVault-Verschlüsselung, Gatekeeper und Firewall MÜSSEN aktiviert sein.',
                'category' => 'SYS.2.4 Clients unter macOS',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.24']],
            ],
            [
                'id' => 'SYS.2.4.A4',
                'title' => 'Nutzung der Apple-ID',
                'description' => 'Die Nutzung privater Apple-IDs auf Firmengeräten MUSS geregelt sein. Synchronisation von Firmendaten mit privaten iCloud-Konten DARF NICHT erfolgen.',
                'category' => 'SYS.2.4 Clients unter macOS',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.10', '5.34']],
            ],
            // SYS.3.1 Laptop
            [
                'id' => 'SYS.3.1.A2',
                'title' => 'Sichere Grundkonfiguration von Laptops',
                'description' => 'Laptops MÜSSEN sicher grundkonfiguriert werden. Die Festplatte MUSS verschlüsselt sein, die Firmware MUSS mit einem Kennwort geschützt sein.',
                'category' => 'SYS.3.1 Laptops',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.24']],
            ],
            [
                'id' => 'SYS.3.1.A3',
                'title' => 'Verhalten bei Verlust oder Diebstahl',
                'description' => 'Für den Fall von Verlust oder Diebstahl eines Laptops MUSS ein Prozess festgelegt sein. Der Zugriff auf Unternehmensdaten MUSS aus der Ferne gesperrt werden können.',
                'category' => 'SYS.3.1 Laptops',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.26', '8.1']],
            ],
            // SYS.3.2.2 MDM
            [
                'id' => 'SYS.3.2.2.A1',
                'title' => 'Auswahl einer MDM-Lösung',
                'description' => 'Vor dem Einsatz mobiler Endgeräte MUSS eine Mobile-Device-Management-Lösung ausgewählt werden, die Richtlinien zentral ausrollt und Compliance-Status überwacht.',
                'category' => 'SYS.3.2.2 Mobile Device Management',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.1'], 'audit_evidence' => true],
            ],
            [
                'id' => 'SYS.3.2.2.A2',
                'title' => 'Durchsetzung von Sicherheitsrichtlinien via MDM',
                'description' => 'Sicherheitsrichtlinien (Passwort-Länge, Verschlüsselung, Jailbreak-Erkennung, Remote-Wipe) MÜSSEN zentral via MDM durchgesetzt werden.',
                'category' => 'SYS.3.2.2 Mobile Device Management',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.17', '8.1', '8.24']],
            ],
            [
                'id' => 'SYS.3.2.2.A5',
                'title' => 'Trennung privater und dienstlicher Daten',
                'description' => 'Bei BYOD-Szenarien MUSS die Trennung privater und dienstlicher Daten durch Container-Lösungen oder getrennte Profile sichergestellt sein.',
                'category' => 'SYS.3.2.2 Mobile Device Management',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'data_source_mapping' => ['iso_controls' => ['5.10', '5.34']],
            ],
            // SYS.4.1 Drucker
            [
                'id' => 'SYS.4.1.A1',
                'title' => 'Planung des Einsatzes von Druckern und Multifunktionsgeräten',
                'description' => 'Vor dem Einsatz von Druckern, Kopierern oder Multifunktionsgeräten MUSS geplant werden, welche Funktionen (Drucken, Scannen, Fax, Archivieren) genutzt werden und welche Sicherheitsmaßnahmen erforderlich sind.',
                'category' => 'SYS.4.1 Drucker und Multifunktionsgeräte',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.8', '8.9']],
            ],
            [
                'id' => 'SYS.4.1.A2',
                'title' => 'Sichere Konfiguration von Druckern',
                'description' => 'Drucker und Multifunktionsgeräte MÜSSEN sicher konfiguriert werden. Nicht benötigte Dienste MÜSSEN deaktiviert sein. Administrative Zugänge MÜSSEN mit Passwörtern geschützt sein.',
                'category' => 'SYS.4.1 Drucker und Multifunktionsgeräte',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9']],
            ],
            [
                'id' => 'SYS.4.1.A3',
                'title' => 'Schutz gespeicherter Druckdaten',
                'description' => 'Auf dem Gerät gespeicherte Druckaufträge und gescannte Dokumente MÜSSEN vor unberechtigtem Zugriff geschützt werden. Daten MÜSSEN vor Entsorgung sicher gelöscht werden.',
                'category' => 'SYS.4.1 Drucker und Multifunktionsgeräte',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.14', '8.10']],
            ],
            // SYS.4.5 Wechseldatenträger
            [
                'id' => 'SYS.4.5.A1',
                'title' => 'Regelung zur Nutzung von Wechseldatenträgern',
                'description' => 'Für den Einsatz von Wechseldatenträgern (USB-Sticks, externe Festplatten, CD/DVD) MUSS eine Richtlinie festgelegt werden, die zulässige Geräte, Ein-/Ausgangs-Prozesse und Schutzanforderungen regelt.',
                'category' => 'SYS.4.5 Wechseldatenträger',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.10'], 'audit_evidence' => true],
            ],
            [
                'id' => 'SYS.4.5.A2',
                'title' => 'Sichere Verwendung von Wechseldatenträgern',
                'description' => 'Auf Wechseldatenträgern gespeicherte vertrauliche Daten MÜSSEN verschlüsselt sein. Nur freigegebene Datenträger DÜRFEN an Firmen-Clients angeschlossen werden.',
                'category' => 'SYS.4.5 Wechseldatenträger',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.10', '8.24']],
            ],
        ];
    }

    private function netRequirements(): array
    {
        return [
            // NET.4.1 TK-Anlage
            [
                'id' => 'NET.4.1.A1',
                'title' => 'Planung des Einsatzes einer TK-Anlage',
                'description' => 'Der Einsatz einer Telefonanlage (klassisch oder VoIP) MUSS geplant werden: Netzwerkdesign, VLAN-Trennung von Sprach- und Datenverkehr, Notruffunktion.',
                'category' => 'NET.4.1 TK-Anlage',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.20', '8.22']],
            ],
            [
                'id' => 'NET.4.1.A2',
                'title' => 'Sichere Konfiguration der TK-Anlage',
                'description' => 'Die TK-Anlage MUSS sicher konfiguriert werden. Default-Accounts MÜSSEN geändert, nicht benötigte Dienste deaktiviert sein.',
                'category' => 'NET.4.1 TK-Anlage',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9', '5.17']],
            ],
            // NET.4.3 Router und Switches
            [
                'id' => 'NET.4.3.A1',
                'title' => 'Sichere Grundkonfiguration von Routern und Switches',
                'description' => 'Router und Switches MÜSSEN sicher grundkonfiguriert werden. Standard-Passwörter MÜSSEN geändert, unbenutzte Ports MÜSSEN deaktiviert sein.',
                'category' => 'NET.4.3 Router und Switches',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.9', '8.21']],
            ],
            [
                'id' => 'NET.4.3.A2',
                'title' => 'Redundanz und Verfügbarkeit',
                'description' => 'Für kritische Router und Switches MÜSSEN Redundanz-Maßnahmen (Loopback, Failover, Stacking) ergriffen werden.',
                'category' => 'NET.4.3 Router und Switches',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['8.14']],
            ],
            [
                'id' => 'NET.4.3.A3',
                'title' => 'Schutz vor unautorisiertem Zugriff',
                'description' => 'Administrative Zugriffe auf Router und Switches MÜSSEN über verschlüsselte Kanäle (SSH statt Telnet, HTTPS) und mit starken Authentisierungsverfahren erfolgen.',
                'category' => 'NET.4.3 Router und Switches',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['5.17', '8.5', '8.20']],
            ],
        ];
    }

    private function infRequirements(): array
    {
        return [
            // INF.13 Technischer Raum (Serverraum-nahe Nebenräume)
            [
                'id' => 'INF.13.A1',
                'title' => 'Planung technischer Räume',
                'description' => 'Technische Räume (z. B. für Netzwerkverteiler, USV, Klimatechnik) MÜSSEN von Beginn an unter Berücksichtigung ihrer Schutzbedürfnisse geplant werden.',
                'category' => 'INF.13 Technischer Raum',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.1', '7.8'], 'entity' => 'Location'],
            ],
            [
                'id' => 'INF.13.A2',
                'title' => 'Zutrittskontrolle zu technischen Räumen',
                'description' => 'Technische Räume MÜSSEN durch Zutrittskontrolle gegen unbefugten Zugang gesichert sein. Der Zutritt MUSS protokolliert werden.',
                'category' => 'INF.13 Technischer Raum',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.2', '7.4']],
            ],
            [
                'id' => 'INF.13.A3',
                'title' => 'Überwachung technischer Räume',
                'description' => 'Technische Räume MÜSSEN auf Umweltbedingungen (Temperatur, Feuchtigkeit, Rauch, Wassereinbruch) überwacht werden. Alarme MÜSSEN an eine besetzte Stelle gemeldet werden.',
                'category' => 'INF.13 Technischer Raum',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'data_source_mapping' => ['iso_controls' => ['7.5']],
            ],
        ];
    }
}
