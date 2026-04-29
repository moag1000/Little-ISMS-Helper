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
 * BSI IT-Grundschutz-Kompendium 2023 — Delta-Loader.
 *
 * Fills the gaps left by `app:load-bsi-grundschutz-requirements` and
 * `app:supplement-bsi-grundschutz-requirements` with high-value Bausteine
 * that are commonly in audit scope for small/medium customers but were
 * missing from the base + supplement loaders:
 *
 *   CON.4   Auswahl und Einsatz von Standardsoftware
 *   CON.5   Entwicklung und Einsatz allgemeiner Anwendungen
 *   OPS.2.3 Nutzung von Outsourcing
 *   APP.4.4 Kubernetes
 *   APP.6   Allgemeine Software
 *   SYS.1.2 Windows Server
 *   SYS.1.3 Server unter Linux und Unix
 *   SYS.1.6 Containerisierung
 *   SYS.2.2 Clients unter Windows
 *   SYS.3.3 Mobiltelefon
 *   NET.4.2 VPN
 *   INF.10  Besprechungs-, Veranstaltungs- und Schulungsräume
 *
 * Each Baustein contributes 2–3 Basis-Anforderungen (MUSS) plus one
 * Standard-Anforderung (SOLLTE) where cross-framework reuse is high.
 *
 * The command is idempotent — it skips Anforderungen whose
 * requirementId is already present for the BSI framework, so repeated
 * runs are safe. `absicherungsStufe` is populated ('basis' | 'standard')
 * so that the existing filter UI picks the new rows up without further
 * migration work.
 */
#[AsCommand(
    name: 'app:load-bsi-kompendium-delta',
    description: 'Fill BSI IT-Grundschutz gaps (Kompendium 2023): high-value Bausteine missing from base + supplement loaders'
)]
class LoadBsiKompendium2023DeltaCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);

        if (!$framework instanceof ComplianceFramework) {
            $io->error('BSI IT-Grundschutz framework not found. Run app:load-bsi-grundschutz-requirements first.');
            return Command::FAILURE;
        }

        $io->info('Loading BSI Kompendium 2023 delta Bausteine…');

        $requirements = $this->deltaRequirements();
        $added = 0;
        $skipped = 0;

        try {
            $this->entityManager->beginTransaction();

            $repo = $this->entityManager->getRepository(ComplianceRequirement::class);

            foreach ($requirements as $req) {
                $existing = $repo->findOneBy([
                    'framework' => $framework,
                    'requirementId' => $req['id'],
                ]);

                if ($existing instanceof ComplianceRequirement) {
                    $skipped++;
                    continue;
                }

                $entity = new ComplianceRequirement();
                $entity->setFramework($framework)
                    ->setRequirementId($req['id'])
                    ->setTitle($req['title'])
                    ->setDescription($req['description'])
                    ->setCategory($req['category'])
                    ->setPriority($req['priority'])
                    ->setDataSourceMapping($req['data_source_mapping']);

                if (isset($req['absicherungsStufe'])) {
                    $entity->setAbsicherungsStufe($req['absicherungsStufe']);
                }
                if (isset($req['requirementType'])) {
                    $entity->setRequirementType($req['requirementType']);
                }

                $this->entityManager->persist($entity);
                $added++;
            }

            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->success(sprintf(
                'BSI Kompendium delta complete: %d new Anforderungen added, %d already present (skipped).',
                $added,
                $skipped
            ));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $io->error('Delta load failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{
     *     id: string, title: string, description: string, category: string,
     *     priority: string, data_source_mapping: array,
     *     absicherungsStufe?: string, requirementType?: string
     * }>
     */
    private function deltaRequirements(): array
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

    /** CON.4 Standardsoftware + CON.5 Entwicklung allgemeiner Anwendungen */
    private function conRequirements(): array
    {
        return [
            [
                'id' => 'CON.4.A1',
                'title' => 'Erstellung eines Anforderungskatalogs für Standardsoftware',
                'description' => 'Für den Einsatz von Standardsoftware MUSS ein Anforderungskatalog erstellt werden, der funktionale und sicherheitstechnische Anforderungen enthält.',
                'category' => 'CON.4 Auswahl und Einsatz von Standardsoftware',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23', '8.8', '8.25'],
                    'entity' => 'Software',
                ],
            ],
            [
                'id' => 'CON.4.A2',
                'title' => 'Sichere Installation von Standardsoftware',
                'description' => 'Standardsoftware MUSS kontrolliert installiert werden. Die Installationspakete MÜSSEN auf Integrität geprüft werden.',
                'category' => 'CON.4 Auswahl und Einsatz von Standardsoftware',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19', '8.32'],
                    'entity' => 'Patch',
                ],
            ],
            [
                'id' => 'CON.4.A5',
                'title' => 'Lizenzmanagement und Versionskontrolle',
                'description' => 'Der Einsatz von Standardsoftware SOLLTE durch ein Lizenzmanagement kontrolliert werden; Versionsstände MÜSSEN erfasst sein.',
                'category' => 'CON.4 Auswahl und Einsatz von Standardsoftware',
                'priority' => 'medium',
                'absicherungsStufe' => 'standard',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '8.8'],
                    'entity' => 'Software',
                ],
            ],
            [
                'id' => 'CON.5.A1',
                'title' => 'Sicherheitsrichtlinie für Softwareentwicklung',
                'description' => 'Für die Entwicklung eigener Software MUSS eine Richtlinie festgelegt werden, die Sicherheitsanforderungen über den gesamten Lebenszyklus regelt.',
                'category' => 'CON.5 Entwicklung und Einsatz allgemeiner Anwendungen',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27', '8.28'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'CON.5.A2',
                'title' => 'Auswahl vertrauenswürdiger Entwicklungswerkzeuge',
                'description' => 'Werkzeuge zur Softwareentwicklung MÜSSEN so ausgewählt werden, dass sie keine bekannten Schwachstellen einbringen.',
                'category' => 'CON.5 Entwicklung und Einsatz allgemeiner Anwendungen',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28', '8.30'],
                ],
            ],
            [
                'id' => 'CON.5.A7',
                'title' => 'Trennung von Entwicklungs-, Test- und Produktivumgebung',
                'description' => 'Entwicklungs-, Test- und Produktivumgebungen SOLLTEN getrennt betrieben werden.',
                'category' => 'CON.5 Entwicklung und Einsatz allgemeiner Anwendungen',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.31'],
                ],
            ],
        ];
    }

    /** OPS.2.3 Nutzung von Outsourcing */
    private function opsRequirements(): array
    {
        return [
            [
                'id' => 'OPS.2.3.A1',
                'title' => 'Erstellung einer Outsourcing-Strategie',
                'description' => 'Für die Nutzung von Outsourcing MUSS eine Strategie festgelegt werden, die Risiken, Governance und Exit-Optionen regelt.',
                'category' => 'OPS.2.3 Nutzung von Outsourcing',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                    'entity' => 'Supplier',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'OPS.2.3.A2',
                'title' => 'Vertragliche Regelungen mit dem Outsourcing-Dienstleister',
                'description' => 'Die Anforderungen an den Dienstleister MÜSSEN vertraglich geregelt sein, einschließlich SLA, Audit-Rechten und Meldewegen.',
                'category' => 'OPS.2.3 Nutzung von Outsourcing',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.22'],
                    'entity' => 'Supplier',
                ],
            ],
            [
                'id' => 'OPS.2.3.A6',
                'title' => 'Überwachung des Outsourcing-Dienstleisters',
                'description' => 'Die Einhaltung der vereinbarten Sicherheitsanforderungen SOLLTE regelmäßig überprüft werden.',
                'category' => 'OPS.2.3 Nutzung von Outsourcing',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                    'entity' => 'Supplier',
                ],
            ],
        ];
    }

    /** APP.4.4 Kubernetes + APP.6 Allgemeine Software */
    private function appRequirements(): array
    {
        return [
            [
                'id' => 'APP.4.4.A1',
                'title' => 'Planung der Absicherung von Kubernetes',
                'description' => 'Bevor Kubernetes eingesetzt wird, MUSS die Absicherung auf allen Ebenen (Cluster, Nodes, Pods, Images) geplant werden.',
                'category' => 'APP.4.4 Kubernetes',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.22', '8.23'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'APP.4.4.A4',
                'title' => 'Absicherung der Konfiguration der Control-Plane',
                'description' => 'Die Control-Plane von Kubernetes MUSS gehärtet konfiguriert werden; administrative Zugriffe MÜSSEN auf das Nötigste beschränkt sein.',
                'category' => 'APP.4.4 Kubernetes',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.5', '8.9'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'APP.4.4.A8',
                'title' => 'Separierung von Workloads über Namespaces',
                'description' => 'Workloads unterschiedlicher Schutzklassen SOLLTEN durch Namespaces und Netzwerkrichtlinien voneinander getrennt werden.',
                'category' => 'APP.4.4 Kubernetes',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'APP.6.A1',
                'title' => 'Planung der Softwareauswahl',
                'description' => 'Bevor allgemeine Software beschafft wird, MUSS der Bedarf dokumentiert und gegen vorhandene Lösungen geprüft werden.',
                'category' => 'APP.6 Allgemeine Software',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23', '8.8'],
                    'entity' => 'Software',
                ],
            ],
            [
                'id' => 'APP.6.A2',
                'title' => 'Einsatz freigegebener Versionen',
                'description' => 'Es MÜSSEN ausschließlich vom Hersteller unterstützte Versionen der Software eingesetzt werden.',
                'category' => 'APP.6 Allgemeine Software',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.19'],
                    'entity' => 'Software',
                ],
            ],
        ];
    }

    /** SYS.1.2 Windows Server + SYS.1.3 Unix Server + SYS.1.6 Containerisierung + SYS.2.2 Windows Clients + SYS.3.3 Mobiltelefon */
    private function sysRequirements(): array
    {
        return [
            [
                'id' => 'SYS.1.2.A1',
                'title' => 'Planung eines Windows Servers',
                'description' => 'Vor dem Einsatz eines Windows Servers MUSS geplant werden, welche Rollen auf dem Server betrieben werden und welche Härtungsmaßnahmen zu treffen sind.',
                'category' => 'SYS.1.2 Windows Server',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.19'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.1.2.A2',
                'title' => 'Sichere Grundkonfiguration eines Windows Servers',
                'description' => 'Ein Windows Server MUSS sicher grundkonfiguriert werden; nicht benötigte Dienste und Benutzerkonten MÜSSEN deaktiviert sein.',
                'category' => 'SYS.1.2 Windows Server',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.1.3.A1',
                'title' => 'Sichere Installation eines Unix-Servers',
                'description' => 'Ein Unix-Server MUSS mit einer minimal notwendigen Komponentenauswahl installiert werden.',
                'category' => 'SYS.1.3 Server unter Linux und Unix',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.19'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.1.3.A5',
                'title' => 'Sichere Authentisierung am Unix-Server',
                'description' => 'Für den administrativen Zugriff auf den Unix-Server MÜSSEN sichere Authentisierungsverfahren eingesetzt werden.',
                'category' => 'SYS.1.3 Server unter Linux und Unix',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.1.6.A1',
                'title' => 'Planung des Container-Einsatzes',
                'description' => 'Vor dem Einsatz von Containern MUSS geplant werden, welche Workloads containerisiert werden und wie die Container-Plattform abgesichert ist.',
                'category' => 'SYS.1.6 Containerisierung',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.22'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.1.6.A3',
                'title' => 'Nur vertrauenswürdige Images verwenden',
                'description' => 'Nur vertrauenswürdige, signierte Container-Images MÜSSEN eingesetzt werden; Images MÜSSEN regelmäßig auf Schwachstellen gescannt werden.',
                'category' => 'SYS.1.6 Containerisierung',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.32'],
                    'entity' => 'Vulnerability',
                ],
            ],
            [
                'id' => 'SYS.2.2.A1',
                'title' => 'Planung des Einsatzes von Clients',
                'description' => 'Vor dem Einsatz von Windows-Clients MUSS geplant werden, welche Anwendungsfälle abgedeckt werden und welche Schutzbedürfnisse bestehen.',
                'category' => 'SYS.2.2 Allgemeiner Client',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.2.2.A3',
                'title' => 'Absicherung der Administratoren-Zugänge am Client',
                'description' => 'Administrative Zugriffe auf Clients MÜSSEN getrennt von der täglichen Arbeit erfolgen.',
                'category' => 'SYS.2.2 Allgemeiner Client',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.5'],
                    'entity' => 'Asset',
                ],
            ],
            [
                'id' => 'SYS.3.3.A1',
                'title' => 'Sicherheitsrichtlinie für den Einsatz von Mobiltelefonen',
                'description' => 'Für den Einsatz von Mobiltelefonen MUSS eine Richtlinie festgelegt werden, die Nutzung, MDM-Anforderungen und Verlustbehandlung regelt.',
                'category' => 'SYS.3.3 Mobiltelefon',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9', '8.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'SYS.3.3.A3',
                'title' => 'Sichere Grundkonfiguration',
                'description' => 'Mobiltelefone MÜSSEN über ein MDM grundkonfiguriert werden, einschließlich Geräteverschlüsselung und Remote-Wipe.',
                'category' => 'SYS.3.3 Mobiltelefon',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9', '8.1', '8.24'],
                ],
            ],
        ];
    }

    /** NET.4.2 VPN */
    private function netRequirements(): array
    {
        return [
            [
                'id' => 'NET.4.2.A1',
                'title' => 'Planung des VPN-Einsatzes',
                'description' => 'Vor dem Einsatz eines VPN MUSS der Einsatzzweck festgelegt und die Auswahl der Protokolle dokumentiert werden.',
                'category' => 'NET.4.2 VPN',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'NET.4.2.A3',
                'title' => 'Sichere Installation von VPN-Dienstleistern',
                'description' => 'Für VPN-Verbindungen MÜSSEN ausschließlich als sicher geltende kryptographische Protokolle und Verfahren eingesetzt werden.',
                'category' => 'NET.4.2 VPN',
                'priority' => 'critical',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.24'],
                ],
            ],
            [
                'id' => 'NET.4.2.A8',
                'title' => 'Absicherung der VPN-Gegenstellen',
                'description' => 'Die Endgeräte, die sich per VPN verbinden, SOLLTEN ausreichend abgesichert sein (z. B. Härtung, EDR, Patch-Stand).',
                'category' => 'NET.4.2 VPN',
                'priority' => 'high',
                'absicherungsStufe' => 'standard',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1', '8.7', '8.20'],
                    'entity' => 'Asset',
                ],
            ],
        ];
    }

    /** INF.10 Besprechungsräume */
    private function infRequirements(): array
    {
        return [
            [
                'id' => 'INF.10.A1',
                'title' => 'Planung der Nutzung von Besprechungs-, Veranstaltungs- und Schulungsräumen',
                'description' => 'Für die Nutzung von Besprechungs-, Veranstaltungs- und Schulungsräumen MUSS festgelegt werden, welche Informationen dort verarbeitet werden dürfen.',
                'category' => 'INF.10 Besprechungs-, Veranstaltungs- und Schulungsräume',
                'priority' => 'medium',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.3'],
                    'entity' => 'Location',
                ],
            ],
            [
                'id' => 'INF.10.A3',
                'title' => 'Beaufsichtigung von externen Personen',
                'description' => 'Externe Personen in Besprechungs-, Veranstaltungs- und Schulungsräumen MÜSSEN beaufsichtigt werden.',
                'category' => 'INF.10 Besprechungs-, Veranstaltungs- und Schulungsräume',
                'priority' => 'high',
                'absicherungsStufe' => 'basis',
                'requirementType' => 'core',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2'],
                    'entity' => 'Location',
                ],
            ],
        ];
    }
}
