<?php

namespace App\Command;

use DateTimeImmutable;
use Exception;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BSI IT-Grundschutz Kompendium Edition 2023/2024 Supplement Command
 *
 * The BSI IT-Grundschutz-Kompendium contains 111 Bausteine (building blocks)
 * organized into 10 Schichten (layers):
 *
 * 1. ISMS - Sicherheitsmanagement
 * 2. ORP - Organisation und Personal
 * 3. CON - Konzepte und Vorgehensweisen
 * 4. OPS - Betrieb
 * 5. APP - Anwendungen
 * 6. SYS - IT-Systeme
 * 7. IND - Industrielle IT
 * 8. NET - Netze und Kommunikation
 * 9. INF - Infrastruktur
 * 10. DER - Detektion und Reaktion
 *
 * This command supplements the base requirements with additional Bausteine.
 *
 * @see https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html
 */
#[AsCommand(
    name: 'app:supplement-bsi-grundschutz-requirements',
    description: 'Supplement BSI IT-Grundschutz with additional Bausteine from Kompendium Edition 2023/2024'
)]
class SupplementBsiGrundschutzRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);

        if (!$framework instanceof ComplianceFramework) {
            $symfonyStyle->error('BSI IT-Grundschutz framework not found. Please run app:load-bsi-grundschutz-requirements first.');
            return Command::FAILURE;
        }

        $symfonyStyle->info('Supplementing BSI IT-Grundschutz with additional Bausteine (Edition 2023/2024)...');

        try {
            $this->entityManager->beginTransaction();

            $requirements = array_merge(
                $this->getOrpRequirements(),
                $this->getConRequirements(),
                $this->getOpsRequirements(),
                $this->getAppRequirements(),
                $this->getSysRequirements(),
                $this->getNetRequirements(),
                $this->getInfRequirements(),
                $this->getIndRequirements(),
                $this->getDerRequirements()
            );

            $addedCount = 0;

            foreach ($requirements as $reqData) {
                $existing = $this->entityManager
                    ->getRepository(ComplianceRequirement::class)
                    ->findOneBy([
                        'complianceFramework' => $framework,
                        'requirementId' => $reqData['id']
                    ]);

                if ($existing instanceof ComplianceRequirement) {
                    continue;
                }

                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $addedCount++;
            }

            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($framework);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $symfonyStyle->success(sprintf('Successfully added %d additional BSI IT-Grundschutz requirements', $addedCount));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $symfonyStyle->error('Failed to supplement BSI IT-Grundschutz requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * ORP - Organisation und Personal
     */
    private function getOrpRequirements(): array
    {
        return [
            [
                'id' => 'ORP.4.A1',
                'title' => 'Regelung für die Einrichtung und Löschung von Benutzern und Benutzergruppen',
                'description' => 'Es MUSS geregelt sein, wie Benutzerkonten und Benutzergruppen eingerichtet und gelöscht werden.',
                'category' => 'ORP.4 Identitäts- und Berechtigungsmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.18'],
                ],
            ],
            [
                'id' => 'ORP.4.A2',
                'title' => 'Einrichtung, Änderung und Entzug von Berechtigungen',
                'description' => 'Berechtigungen DÜRFEN NUR auf Antrag eingerichtet, geändert oder entzogen werden.',
                'category' => 'ORP.4 Identitäts- und Berechtigungsmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.18'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ORP.4.A3',
                'title' => 'Dokumentation der Berechtigungen',
                'description' => 'Die vergebenen Zugriffsrechte MÜSSEN dokumentiert werden.',
                'category' => 'ORP.4 Identitäts- und Berechtigungsmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ORP.4.A4',
                'title' => 'Aufgabenverteilung und Funktionstrennung',
                'description' => 'Die Aufgabenverteilung und die Funktionstrennung zwischen den Rollen MÜSSEN nach dem Prinzip der geringsten Berechtigung geregelt sein.',
                'category' => 'ORP.4 Identitäts- und Berechtigungsmanagement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                ],
            ],
            [
                'id' => 'ORP.5.A1',
                'title' => 'Identifizierung der rechtlichen Rahmenbedingungen',
                'description' => 'Die für die Institution relevanten rechtlichen Rahmenbedingungen MÜSSEN identifiziert werden.',
                'category' => 'ORP.5 Compliance Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ORP.5.A2',
                'title' => 'Beachtung rechtlicher Rahmenbedingungen',
                'description' => 'Die identifizierten rechtlichen Rahmenbedingungen MÜSSEN bei allen relevanten Aktivitäten beachtet werden.',
                'category' => 'ORP.5 Compliance Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31', '5.32'],
                ],
            ],
        ];
    }

    /**
     * CON - Konzepte und Vorgehensweisen
     */
    private function getConRequirements(): array
    {
        return [
            [
                'id' => 'CON.6.A1',
                'title' => 'Regelung der Vorgehensweise für das Löschen und Vernichten',
                'description' => 'Es MUSS eine Vorgehensweise für das Löschen und Vernichten von Informationen und Datenträgern festgelegt werden.',
                'category' => 'CON.6 Löschen und Vernichten',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'CON.6.A2',
                'title' => 'Ordnungsgemäßes Löschen und Vernichten von Informationen',
                'description' => 'Alle Informationen MÜSSEN so gelöscht werden, dass keine Rückschlüsse auf die Inhalte möglich sind.',
                'category' => 'CON.6 Löschen und Vernichten',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'CON.7.A1',
                'title' => 'Sicherheitsrichtlinie für Auslandsreisen',
                'description' => 'Für Auslandsreisen MUSS eine Sicherheitsrichtlinie erstellt werden.',
                'category' => 'CON.7 Informationssicherheit auf Auslandsreisen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
            [
                'id' => 'CON.8.A1',
                'title' => 'Planung und Dokumentation der Software-Entwicklung',
                'description' => 'Software-Entwicklungsprojekte MÜSSEN geplant und dokumentiert werden.',
                'category' => 'CON.8 Software-Entwicklung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25'],
                ],
            ],
            [
                'id' => 'CON.8.A2',
                'title' => 'Auswahl und Verwendung von Programmiersprachen und Entwicklungsumgebungen',
                'description' => 'Die eingesetzten Programmiersprachen und Entwicklungsumgebungen MÜSSEN auf ihre Eignung geprüft werden.',
                'category' => 'CON.8 Software-Entwicklung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.28'],
                ],
            ],
            [
                'id' => 'CON.9.A1',
                'title' => 'Regelungen zum Informationsaustausch',
                'description' => 'Es MÜSSEN Regelungen für den Informationsaustausch mit externen Partnern erstellt werden.',
                'category' => 'CON.9 Informationsaustausch',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],
            [
                'id' => 'CON.10.A1',
                'title' => 'Sichere Entwicklung von Webanwendungen',
                'description' => 'Webanwendungen MÜSSEN nach dem Prinzip der sicheren Software-Entwicklung erstellt werden.',
                'category' => 'CON.10 Entwicklung von Webanwendungen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.26'],
                ],
            ],
        ];
    }

    /**
     * OPS - Betrieb
     */
    private function getOpsRequirements(): array
    {
        return [
            [
                'id' => 'OPS.1.1.1.A1',
                'title' => 'Planung des IT-Betriebs',
                'description' => 'Der IT-Betrieb MUSS systematisch geplant werden.',
                'category' => 'OPS.1.1.1 Allgemeiner IT-Betrieb',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'OPS.1.1.2.A1',
                'title' => 'Personalauswahl für administrative Tätigkeiten',
                'description' => 'Administratoren MÜSSEN sorgfältig ausgewählt werden.',
                'category' => 'OPS.1.1.2 Ordnungsgemäße IT-Administration',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1', '8.2'],
                ],
            ],
            [
                'id' => 'OPS.1.1.2.A2',
                'title' => 'Vertretungsregelungen und Notfallvorsorge',
                'description' => 'Es MÜSSEN Vertretungsregelungen für Administratoren festgelegt werden.',
                'category' => 'OPS.1.1.2 Ordnungsgemäße IT-Administration',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '5.29'],
                ],
            ],
            [
                'id' => 'OPS.1.1.4.A1',
                'title' => 'Erstellung eines Konzepts für den Schutz vor Schadprogrammen',
                'description' => 'Es MUSS ein Konzept für den Schutz vor Schadprogrammen erstellt werden.',
                'category' => 'OPS.1.1.4 Schutz vor Schadprogrammen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'OPS.1.1.4.A2',
                'title' => 'Nutzung systemspezifischer Schutzmechanismen',
                'description' => 'Die systemspezifischen Schutzmechanismen MÜSSEN aktiviert und konfiguriert werden.',
                'category' => 'OPS.1.1.4 Schutz vor Schadprogrammen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'OPS.1.1.6.A1',
                'title' => 'Planung der Software-Tests',
                'description' => 'Software-Tests MÜSSEN geplant werden.',
                'category' => 'OPS.1.1.6 Software-Tests und -Freigaben',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => 'OPS.1.1.6.A2',
                'title' => 'Durchführung von Software-Tests',
                'description' => 'Software MUSS vor der Inbetriebnahme getestet werden.',
                'category' => 'OPS.1.1.6 Software-Tests und -Freigaben',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29', '8.31'],
                ],
            ],
            [
                'id' => 'OPS.1.1.7.A1',
                'title' => 'Anforderungen an das Systemmanagement',
                'description' => 'Die Anforderungen an das Systemmanagement MÜSSEN definiert werden.',
                'category' => 'OPS.1.1.7 Systemmanagement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'OPS.1.2.4.A1',
                'title' => 'Konzept für die Telearbeit',
                'description' => 'Es MUSS ein Konzept für die Telearbeit erstellt werden.',
                'category' => 'OPS.1.2.4 Telearbeit',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
        ];
    }

    /**
     * APP - Anwendungen
     */
    private function getAppRequirements(): array
    {
        return [
            [
                'id' => 'APP.1.1.A1',
                'title' => 'Planung und Konzeption einer Standard-Software',
                'description' => 'Die Einführung einer Standard-Software MUSS geplant werden.',
                'category' => 'APP.1.1 Office-Produkte',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'APP.2.1.A2',
                'title' => 'Planung des Einsatzes von Verzeichnisdiensten',
                'description' => 'Der Einsatz von Verzeichnisdiensten MUSS sorgfältig geplant werden.',
                'category' => 'APP.2.1 Allgemeiner Verzeichnisdienst',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.16'],
                ],
            ],
            [
                'id' => 'APP.2.2.A1',
                'title' => 'Planung des Active Directory Domain Services',
                'description' => 'Der Einsatz von Active Directory Domain Services (AD DS) MUSS sorgfältig geplant werden.',
                'category' => 'APP.2.2 Active Directory Domain Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '8.2'],
                ],
            ],
            [
                'id' => 'APP.3.1.A1',
                'title' => 'Authentisierung bei Webanwendungen',
                'description' => 'Bei Webanwendungen MUSS eine sichere Authentisierung implementiert werden.',
                'category' => 'APP.3.1 Webanwendungen und Webservices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                ],
            ],
            [
                'id' => 'APP.3.2.A1',
                'title' => 'Sichere Konfiguration eines Webservers',
                'description' => 'Webserver MÜSSEN sicher konfiguriert werden.',
                'category' => 'APP.3.2 Webserver',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'APP.4.3.A1',
                'title' => 'Planung des Einsatzes einer relationalen Datenbank',
                'description' => 'Der Einsatz einer relationalen Datenbank MUSS sorgfältig geplant werden.',
                'category' => 'APP.4.3 Relationale Datenbanken',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'APP.5.1.A1',
                'title' => 'Sichere Konfiguration von E-Mail-Clients',
                'description' => 'E-Mail-Clients MÜSSEN sicher konfiguriert werden.',
                'category' => 'APP.5.1 E-Mail-Clients',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'APP.5.2.A1',
                'title' => 'Planung des Einsatzes eines Microsoft Exchange-Servers',
                'description' => 'Der Einsatz von Microsoft Exchange MUSS sorgfältig geplant werden.',
                'category' => 'APP.5.2 Microsoft Exchange und Outlook',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
        ];
    }

    /**
     * SYS - IT-Systeme
     */
    private function getSysRequirements(): array
    {
        return [
            [
                'id' => 'SYS.1.1.A2',
                'title' => 'Benutzerauthentisierung an Servern',
                'description' => 'Die Authentisierung von Benutzern an Servern MUSS nach dem Stand der Technik erfolgen.',
                'category' => 'SYS.1.1 Allgemeiner Server',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                ],
            ],
            [
                'id' => 'SYS.1.1.A3',
                'title' => 'Schutz von Schnittstellen',
                'description' => 'Nicht genutzte Schnittstellen von Servern MÜSSEN deaktiviert werden.',
                'category' => 'SYS.1.1 Allgemeiner Server',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.1.5.A1',
                'title' => 'Geeignete Auswahl von Virtualisierungsprodukten',
                'description' => 'Die eingesetzten Virtualisierungsprodukte MÜSSEN sorgfältig ausgewählt werden.',
                'category' => 'SYS.1.5 Virtualisierung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'SYS.1.5.A2',
                'title' => 'Sicherer Einsatz virtueller Infrastruktur',
                'description' => 'Die virtuelle Infrastruktur MUSS sicher betrieben werden.',
                'category' => 'SYS.1.5 Virtualisierung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1', '8.9'],
                ],
            ],
            [
                'id' => 'SYS.2.1.A2',
                'title' => 'Rollentrennung bei Clients',
                'description' => 'Die Rollen von Benutzern und Administratoren MÜSSEN getrennt werden.',
                'category' => 'SYS.2.1 Allgemeiner Client',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2'],
                ],
            ],
            [
                'id' => 'SYS.2.3.A1',
                'title' => 'Planung des Einsatzes von Windows-Clients',
                'description' => 'Der Einsatz von Windows-Clients MUSS sorgfältig geplant werden.',
                'category' => 'SYS.2.3 Clients unter Windows',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'SYS.3.1.A1',
                'title' => 'Regelungen zur Nutzung von Laptops',
                'description' => 'Es MÜSSEN Regelungen zur Nutzung von Laptops erstellt werden.',
                'category' => 'SYS.3.1 Laptops',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7', '8.1'],
                ],
            ],
            [
                'id' => 'SYS.3.2.1.A1',
                'title' => 'Planung des Einsatzes von Smartphones und Tablets',
                'description' => 'Der Einsatz von Smartphones und Tablets MUSS sorgfältig geplant werden.',
                'category' => 'SYS.3.2.1 Allgemeine Smartphones und Tablets',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'SYS.3.2.1.A2',
                'title' => 'Mobile Device Management (MDM)',
                'description' => 'Die mobilen Endgeräte SOLLTEN über ein MDM verwaltet werden.',
                'category' => 'SYS.3.2.1 Allgemeine Smartphones und Tablets',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.3.4.A1',
                'title' => 'Regelungen für den Einsatz mobiler Datenträger',
                'description' => 'Es MÜSSEN Regelungen für den Umgang mit mobilen Datenträgern erstellt werden.',
                'category' => 'SYS.3.4 Mobile Datenträger',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.4.4.A1',
                'title' => 'Planung des Einsatzes von IoT-Geräten',
                'description' => 'Der Einsatz von IoT-Geräten MUSS sorgfältig geplant werden.',
                'category' => 'SYS.4.4 Allgemeines IoT-Gerät',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                    'asset_types' => ['hardware'],
                ],
            ],
        ];
    }

    /**
     * NET - Netze und Kommunikation
     */
    private function getNetRequirements(): array
    {
        return [
            [
                'id' => 'NET.1.2.A1',
                'title' => 'Planung des Netzmanagements',
                'description' => 'Das Netzmanagement MUSS sorgfältig geplant werden.',
                'category' => 'NET.1.2 Netzmanagement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'NET.2.1.A1',
                'title' => 'Planung des WLAN-Einsatzes',
                'description' => 'Der WLAN-Einsatz MUSS sorgfältig geplant werden.',
                'category' => 'NET.2.1 WLAN-Betrieb',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'NET.2.1.A2',
                'title' => 'Auswahl geeigneter WLAN-Geräte',
                'description' => 'Die WLAN-Geräte MÜSSEN nach dem aktuellen Stand der Technik ausgewählt werden.',
                'category' => 'NET.2.1 WLAN-Betrieb',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'NET.2.2.A1',
                'title' => 'Regelungen zur WLAN-Nutzung',
                'description' => 'Es MÜSSEN Regelungen zur WLAN-Nutzung erstellt werden.',
                'category' => 'NET.2.2 WLAN-Nutzung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'NET.3.1.A1',
                'title' => 'Sichere Grundkonfiguration von Routern und Switches',
                'description' => 'Router und Switches MÜSSEN sicher konfiguriert werden.',
                'category' => 'NET.3.1 Router und Switches',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.20'],
                ],
            ],
            [
                'id' => 'NET.3.2.A1',
                'title' => 'Erstellung einer Firewall-Richtlinie',
                'description' => 'Es MUSS eine Firewall-Richtlinie erstellt werden.',
                'category' => 'NET.3.2 Firewall',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'NET.3.2.A2',
                'title' => 'Einrichten von Paketfiltern',
                'description' => 'Paketfilter MÜSSEN nach dem Prinzip der restriktiven Rechtevergabe konfiguriert werden.',
                'category' => 'NET.3.2 Firewall',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'NET.3.3.A1',
                'title' => 'Planung des VPN-Einsatzes',
                'description' => 'Der VPN-Einsatz MUSS sorgfältig geplant werden.',
                'category' => 'NET.3.3 VPN',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21', '8.24'],
                ],
            ],
            [
                'id' => 'NET.3.4.A1',
                'title' => 'Planung des NAC-Einsatzes',
                'description' => 'Der Einsatz von Network Access Control (NAC) MUSS sorgfältig geplant werden.',
                'category' => 'NET.3.4 Network Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
        ];
    }

    /**
     * INF - Infrastruktur
     */
    private function getInfRequirements(): array
    {
        return [
            [
                'id' => 'INF.2.A1',
                'title' => 'Planung von Rechenzentrum und Serverraum',
                'description' => 'Rechenzentren und Serverräume MÜSSEN angemessen geplant werden.',
                'category' => 'INF.2 Rechenzentrum und Serverraum',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.5', '7.8'],
                ],
            ],
            [
                'id' => 'INF.2.A2',
                'title' => 'Bildung von Brandabschnitten',
                'description' => 'Es MÜSSEN Brandabschnitte gebildet werden.',
                'category' => 'INF.2 Rechenzentrum und Serverraum',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],
            [
                'id' => 'INF.5.A1',
                'title' => 'Planung der Raumnutzung für technische Infrastruktur',
                'description' => 'Die Raumnutzung für technische Infrastruktur MUSS geplant werden.',
                'category' => 'INF.5 Raum für technische Infrastruktur',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],
            [
                'id' => 'INF.7.A1',
                'title' => 'Geeignete Auswahl und Nutzung eines Büroraums',
                'description' => 'Büroräume MÜSSEN sicher ausgewählt und genutzt werden.',
                'category' => 'INF.7 Büroarbeitsplatz',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7'],
                ],
            ],
            [
                'id' => 'INF.8.A1',
                'title' => 'Planung des häuslichen Arbeitsplatzes',
                'description' => 'Die Einrichtung eines häuslichen Arbeitsplatzes MUSS geplant werden.',
                'category' => 'INF.8 Häuslicher Arbeitsplatz',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
            [
                'id' => 'INF.9.A1',
                'title' => 'Geeignete Auswahl und Nutzung mobiler Arbeitsplätze',
                'description' => 'Mobile Arbeitsplätze MÜSSEN sicher ausgewählt und genutzt werden.',
                'category' => 'INF.9 Mobiler Arbeitsplatz',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
            [
                'id' => 'INF.12.A1',
                'title' => 'Auswahl geeigneter Kabeltypen',
                'description' => 'Es MÜSSEN geeignete Kabeltypen ausgewählt werden.',
                'category' => 'INF.12 Verkabelung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.12'],
                ],
            ],
        ];
    }

    /**
     * IND - Industrielle IT
     */
    private function getIndRequirements(): array
    {
        return [
            [
                'id' => 'IND.1.A1',
                'title' => 'Segmentierung der OT-Netze',
                'description' => 'OT-Netze MÜSSEN von anderen Netzen (z.B. Office-IT) segmentiert werden.',
                'category' => 'IND.1 Prozessleit- und Automatisierungstechnik',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'IND.1.A2',
                'title' => 'Einschränkung der Fernwartung',
                'description' => 'Fernwartungszugänge für ICS-Komponenten MÜSSEN eingeschränkt werden.',
                'category' => 'IND.1 Prozessleit- und Automatisierungstechnik',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'IND.2.1.A1',
                'title' => 'Sichere Konfiguration von ICS-Komponenten',
                'description' => 'ICS-Komponenten MÜSSEN sicher konfiguriert werden.',
                'category' => 'IND.2.1 Allgemeine ICS-Komponente',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'IND.2.2.A1',
                'title' => 'Planung des SPS-Einsatzes',
                'description' => 'Der Einsatz von Speicherprogrammierbaren Steuerungen (SPS) MUSS geplant werden.',
                'category' => 'IND.2.2 Speicherprogrammierbare Steuerung (SPS)',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                    'asset_types' => ['hardware'],
                ],
            ],
            [
                'id' => 'IND.3.2.A1',
                'title' => 'Planung der Fernwartung',
                'description' => 'Die Fernwartung von ICS-Komponenten MUSS sorgfältig geplant werden.',
                'category' => 'IND.3.2 Fernwartung im industriellen Umfeld',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
        ];
    }

    /**
     * DER - Detektion und Reaktion
     */
    private function getDerRequirements(): array
    {
        return [
            [
                'id' => 'DER.1.A2',
                'title' => 'Festlegung von Meldewegen für Sicherheitsvorfälle',
                'description' => 'Es MÜSSEN klare Meldewege für Sicherheitsvorfälle festgelegt werden.',
                'category' => 'DER.1 Detektion von sicherheitsrelevanten Ereignissen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DER.2.1.A3',
                'title' => 'Festlegung von Verantwortlichkeiten bei Sicherheitsvorfällen',
                'description' => 'Es MÜSSEN Verantwortlichkeiten bei Sicherheitsvorfällen festgelegt werden.',
                'category' => 'DER.2.1 Behandlung von Sicherheitsvorfällen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DER.2.3.A1',
                'title' => 'Festlegung von Verfahren zur forensischen Sicherung',
                'description' => 'Es MÜSSEN Verfahren zur forensischen Sicherung von Spuren festgelegt werden.',
                'category' => 'DER.2.3 Bereinigung nach APT-Angriffen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DER.3.1.A1',
                'title' => 'Festlegung eines Audit-Programms',
                'description' => 'Ein Audit-Programm MUSS festgelegt werden.',
                'category' => 'DER.3.1 Audits und Revisionen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DER.3.1.A2',
                'title' => 'Definition von Audit-Zielen',
                'description' => 'Die Audit-Ziele MÜSSEN definiert werden.',
                'category' => 'DER.3.1 Audits und Revisionen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DER.4.A1',
                'title' => 'Erstellung eines Notfallhandbuchs',
                'description' => 'Es MUSS ein Notfallhandbuch erstellt werden.',
                'category' => 'DER.4 Notfallmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DER.4.A2',
                'title' => 'Integration in das Sicherheitskonzept',
                'description' => 'Das Notfallmanagement MUSS in das Sicherheitskonzept integriert werden.',
                'category' => 'DER.4 Notfallmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29'],
                    'bcm_required' => true,
                ],
            ],
        ];
    }
}
