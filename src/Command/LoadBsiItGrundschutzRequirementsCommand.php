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

#[AsCommand(
    name: 'app:load-bsi-grundschutz-requirements',
    description: 'Load BSI IT-Grundschutz requirements with ISMS data mappings'
)]
class LoadBsiItGrundschutzRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Create or get BSI IT-Grundschutz framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('BSI_GRUNDSCHUTZ')
                ->setName('BSI IT-Grundschutz')
                ->setDescription('German information security standard by the Federal Office for Information Security (BSI)')
                ->setVersion('Edition 2023')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('BSI (Bundesamt für Sicherheit in der Informationstechnik)')
                ->setMandatory(false)
                ->setScopeDescription('Comprehensive IT security standard applicable to organizations of all sizes in Germany')
                ->setActive(true);

            $this->entityManager->persist($framework);
        } else {
            // Framework exists - check if requirements are already loaded
            $existingRequirements = $this->entityManager
                ->getRepository(ComplianceRequirement::class)
                ->findBy(['complianceFramework' => $framework]);

            if ($existingRequirements !== []) {
                $symfonyStyle->warning(sprintf(
                    'Framework BSI IT-Grundschutz already has %d requirements loaded. Skipping to avoid duplicates.',
                    count($existingRequirements)
                ));
                return Command::SUCCESS;
            }

            // Framework exists but has no requirements - update timestamp
            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($framework);
        }
        try {
            $this->entityManager->beginTransaction();

            $requirements = $this->getBsiGrundschutzRequirements();

            foreach ($requirements as $reqData) {
                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $symfonyStyle->success(sprintf('Successfully loaded %d BSI IT-Grundschutz requirements', count($requirements)));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $symfonyStyle->error('Failed to load BSI IT-Grundschutz requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    private function getBsiGrundschutzRequirements(): array
    {
        return [
            // ISMS - Information Security Management
            [
                'id' => 'ISMS.1.A1',
                'title' => 'Übernahme der Gesamtverantwortung für Informationssicherheit durch die Leitungsebene',
                'description' => 'Die Leitungsebene MUSS die Gesamtverantwortung für Informationssicherheit in der Institution übernehmen.',
                'category' => 'ISMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISMS.1.A2',
                'title' => 'Festlegung der Sicherheitsziele und -strategie',
                'description' => 'Es MÜSSEN angemessene Sicherheitsziele und eine Sicherheitsstrategie für die Institution festgelegt werden.',
                'category' => 'ISMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISMS.1.A3',
                'title' => 'Erstellung einer Leitlinie zur Informationssicherheit',
                'description' => 'Eine Leitlinie zur Informationssicherheit MUSS von der Leitungsebene verabschiedet werden.',
                'category' => 'ISMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISMS.1.A4',
                'title' => 'Benennung eines Informationssicherheitsbeauftragten',
                'description' => 'Die Leitungsebene MUSS einen Informationssicherheitsbeauftragten benennen.',
                'category' => 'ISMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                ],
            ],
            [
                'id' => 'ISMS.1.A5',
                'title' => 'Vertragsgestaltung bei Bestellung eines externen Informationssicherheitsbeauftragten',
                'description' => 'Bei der Bestellung eines externen Informationssicherheitsbeauftragten MÜSSEN bestimmte Punkte schriftlich vereinbart werden.',
                'category' => 'ISMS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],

            // ORP - Organisation und Personal
            [
                'id' => 'ORP.1.A1',
                'title' => 'Geeignete Auswahl von Mitarbeitern',
                'description' => 'Mitarbeiter MÜSSEN für ihre Aufgaben und Tätigkeiten geeignet sein.',
                'category' => 'Organisation und Personal',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'ORP.2.A1',
                'title' => 'Geregelte Einarbeitung neuer Mitarbeiter',
                'description' => 'Neue Mitarbeiter MÜSSEN in ihre Aufgaben und Tätigkeiten eingearbeitet werden.',
                'category' => 'Organisation und Personal',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'ORP.3.A1',
                'title' => 'Sensibilisierung des Managements für Informationssicherheit',
                'description' => 'Das Management MUSS für Informationssicherheit sensibilisiert werden.',
                'category' => 'Organisation und Personal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'ORP.3.A2',
                'title' => 'Ansprechpartner zu Sicherheitsfragen',
                'description' => 'Es MÜSSEN Ansprechpartner für Sicherheitsfragen benannt werden.',
                'category' => 'Organisation und Personal',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                ],
            ],
            [
                'id' => 'ORP.3.A3',
                'title' => 'Einweisung des Personals in den sicheren Umgang mit IT',
                'description' => 'Alle Mitarbeiter MÜSSEN in den sicheren Umgang mit IT eingewiesen werden.',
                'category' => 'Organisation und Personal',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],

            // CON - Konzeption und Vorgehensweisen
            [
                'id' => 'CON.1.A1',
                'title' => 'Erstellung eines Kryptokonzepts',
                'description' => 'Es MUSS ein Kryptokonzept erstellt werden.',
                'category' => 'Kryptographie',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'CON.2.A1',
                'title' => 'Festlegung von Verantwortlichkeiten für den Datenschutz',
                'description' => 'Die Verantwortlichkeiten für den Datenschutz MÜSSEN festgelegt werden.',
                'category' => 'Datenschutz',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'CON.3.A1',
                'title' => 'Erhebung der Einflussfaktoren der Datensicherung',
                'description' => 'Es MÜSSEN die Einflussfaktoren für die Datensicherung erhoben werden.',
                'category' => 'Datensicherung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'CON.3.A2',
                'title' => 'Festlegung der Verfahrensweise für die Datensicherung',
                'description' => 'Es MUSS ein Verfahren für die Datensicherung festgelegt werden.',
                'category' => 'Datensicherung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],

            // OPS - Betrieb
            [
                'id' => 'OPS.1.1.2.A1',
                'title' => 'Planung des Einsatzes von Cloud-Diensten',
                'description' => 'Der Einsatz von Cloud-Diensten MUSS sorgfältig geplant werden.',
                'category' => 'Cloud-Nutzung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'OPS.1.1.3.A1',
                'title' => 'Erstellung eines Patchmanagement-Konzepts',
                'description' => 'Es MUSS ein Patchmanagement-Konzept erstellt werden.',
                'category' => 'Patch- und Änderungsmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.19'],
                ],
            ],
            [
                'id' => 'OPS.1.1.3.A2',
                'title' => 'Festlegung von Zuständigkeiten im Patchmanagement',
                'description' => 'Die Zuständigkeiten für das Patchmanagement MÜSSEN festgelegt werden.',
                'category' => 'Patch- und Änderungsmanagement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3', '8.8'],
                ],
            ],
            [
                'id' => 'OPS.1.1.5.A1',
                'title' => 'Erstellung eines Protokollierungskonzepts',
                'description' => 'Es MUSS ein Protokollierungskonzept erstellt werden.',
                'category' => 'Protokollierung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS.1.1.5.A2',
                'title' => 'Konfiguration der Protokollierung',
                'description' => 'Die Protokollierung MUSS so konfiguriert werden, dass sicherheitsrelevante Ereignisse protokolliert werden.',
                'category' => 'Protokollierung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS.1.2.2.A1',
                'title' => 'Erstellung einer Richtlinie zur Archivierung',
                'description' => 'Es MUSS eine Richtlinie zur Archivierung erstellt werden.',
                'category' => 'Archivierung',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],

            // DER - Detektion und Reaktion
            [
                'id' => 'DER.1.A1',
                'title' => 'Erstellung eines Notfallkonzepts',
                'description' => 'Es MUSS ein Notfallkonzept erstellt werden.',
                'category' => 'Notfallmanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DER.2.1.A1',
                'title' => 'Erstellung einer Richtlinie zur Behandlung von Sicherheitsvorfällen',
                'description' => 'Es MUSS eine Richtlinie zur Behandlung von Sicherheitsvorfällen erstellt werden.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DER.2.1.A2',
                'title' => 'Einrichtung eines Incident-Response-Teams',
                'description' => 'Es MUSS ein Incident-Response-Team eingerichtet werden.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DER.2.2.A1',
                'title' => 'Erstellung eines Auswertungskonzepts für Protokolldaten',
                'description' => 'Es MUSS ein Auswertungskonzept für Protokolldaten erstellt werden.',
                'category' => 'Auswertung von Ereignissen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],

            // APP - Anwendungen
            [
                'id' => 'APP.1.A1',
                'title' => 'Erstellung von Sicherheitsanforderungen für Anwendungen',
                'description' => 'Für jede Anwendung MÜSSEN Sicherheitsanforderungen erstellt werden.',
                'category' => 'Allgemeine Anwendungen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.26'],
                ],
            ],
            [
                'id' => 'APP.2.1.A1',
                'title' => 'Erstellung einer Active Directory-Sicherheitsrichtlinie',
                'description' => 'Es MUSS eine Sicherheitsrichtlinie für Active Directory erstellt werden.',
                'category' => 'Active Directory',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '8.2'],
                ],
            ],

            // SYS - IT-Systeme
            [
                'id' => 'SYS.1.1.A1',
                'title' => 'Geeignete Aufstellung von Servern',
                'description' => 'Server MÜSSEN an einem geeigneten Ort aufgestellt werden.',
                'category' => 'Allgemeiner Server',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],
            [
                'id' => 'SYS.2.1.A1',
                'title' => 'Geeignete Aufstellung von Clients',
                'description' => 'Clients MÜSSEN so aufgestellt werden, dass ein unberechtigter Zugriff verhindert wird.',
                'category' => 'Allgemeiner Client',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],

            // NET - Netze und Kommunikation
            [
                'id' => 'NET.1.1.A1',
                'title' => 'Erstellung einer Netzarchitektur',
                'description' => 'Es MUSS eine Netzarchitektur erstellt werden.',
                'category' => 'Netzarchitektur und -design',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'NET.1.1.A2',
                'title' => 'Netztrennung in Sicherheitszonen',
                'description' => 'Das Netz MUSS in Sicherheitszonen getrennt werden.',
                'category' => 'Netzarchitektur und -design',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],

            // INF - Infrastruktur
            [
                'id' => 'INF.1.A1',
                'title' => 'Planung der Gebäudeabsicherung',
                'description' => 'Die Absicherung von Gebäuden MUSS geplant werden.',
                'category' => 'Allgemeines Gebäude',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                ],
            ],
            [
                'id' => 'INF.1.A2',
                'title' => 'Angemessene Zutrittskontrolle und Zugangskontrolle',
                'description' => 'Es MUSS eine angemessene Zutritts- und Zugangskontrolle eingerichtet werden.',
                'category' => 'Allgemeines Gebäude',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
        ];
    }
}
