<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-nis2umsucg-requirements',
    description: 'Load NIS-2-Umsetzungs- und Cybersicherheitsstaerkungsgesetz (BGBl. 2025 I Nr. 301) requirements with ISO 27001 control mappings'
)]
class LoadNis2UmsuCGRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping them')]
        bool $update = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $updateMode = $update;

        $symfonyStyle->title('Loading NIS2UmsuCG Requirements');
        $symfonyStyle->text(sprintf('Mode: %s', $updateMode ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Create or get NIS2UmsuCG framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'NIS2UMSUCG']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode('NIS2UMSUCG')
            ->setName('NIS-2-Umsetzungs- und Cybersicherheitsstärkungsgesetz')
            ->setDescription('German implementation of EU NIS2 directive - Gesetz zur Umsetzung der NIS-2-Richtlinie und zur Staerkung der Cybersicherheit (BGBl. 2025 I Nr. 301)')
            ->setVersion('2025')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('BSI / BMI')
            ->setMandatory(true)
            ->setScopeDescription('German implementation of EU NIS2 directive. Applies to besonders wichtige Einrichtungen and wichtige Einrichtungen in critical and important sectors in Germany.')
            ->setActive(true);

        if ($isNew) {
            $this->entityManager->persist($framework);
            $symfonyStyle->text('Created framework');
        } else {
            $symfonyStyle->text('Framework exists');
        }

        $requirements = $this->getNis2UmsuCGRequirements();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqData['id'],
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($updateMode) {
                    $existing->setTitle($reqData['title'])
                        ->setDescription($reqData['description'])
                        ->setCategory($reqData['category'])
                        ->setPriority($reqData['priority'])
                        ->setDataSourceMapping($reqData['data_source_mapping']);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $stats['created']++;
            }

            // Batch flush
            if (($stats['created'] + $stats['updated']) % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success('NIS2UmsuCG requirements loaded!');
        $symfonyStyle->table(
            ['Action', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Total', count($requirements)],
            ]
        );

        return Command::SUCCESS;
    }

    private function getNis2UmsuCGRequirements(): array
    {
        return [
            // Registrierung und Meldepflichten
            [
                'id' => 'NIS2UMSUCG-1',
                'title' => 'BSI-Registrierungspflicht',
                'description' => 'Registrierung bei BSI innerhalb 3 Monaten nach Inkrafttreten des Gesetzes. Besonders wichtige und wichtige Einrichtungen muessen sich beim Bundesamt fuer Sicherheit in der Informationstechnik registrieren (§33 NIS2UmsuCG).',
                'category' => 'Registrierung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.5'],
                    'legal_reference' => '§33 NIS2UmsuCG',
                    'deadline' => '3_months',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-2',
                'title' => 'Meldepflicht Stufe 1 - Erstmeldung',
                'description' => 'Erstmeldung eines erheblichen Sicherheitsvorfalls innerhalb von 24 Stunden an das BSI. Die Meldung muss eine erste Bewertung des Vorfalls, einschliesslich seiner Schwere und Auswirkungen, sowie gegebenenfalls die Kompromittierungsindikatoren enthalten (§32 Abs. 1 NIS2UmsuCG).',
                'category' => 'Meldepflichten',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'legal_reference' => '§32 Abs. 1 NIS2UmsuCG',
                    'reporting_deadline' => '24h',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-3',
                'title' => 'Meldepflicht Stufe 2 - Aktualisierte Meldung',
                'description' => 'Aktualisierte Meldung innerhalb von 72 Stunden nach Kenntniserlangung des erheblichen Sicherheitsvorfalls. Die Meldung aktualisiert die Erstmeldung und enthaelt eine erste Bewertung des Vorfalls, einschliesslich seiner Schwere und Auswirkungen (§32 Abs. 2 NIS2UmsuCG).',
                'category' => 'Meldepflichten',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'legal_reference' => '§32 Abs. 2 NIS2UmsuCG',
                    'reporting_deadline' => '72h',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-4',
                'title' => 'Meldepflicht Stufe 3 - Abschlussbericht',
                'description' => 'Abschlussbericht innerhalb eines Monats nach der Meldung des Sicherheitsvorfalls. Der Bericht muss eine ausfuehrliche Beschreibung des Vorfalls, der Art der Bedrohung, der ergriffenen Abhilfemassnahmen sowie der grenzueberschreitenden Auswirkungen enthalten (§32 Abs. 3 NIS2UmsuCG).',
                'category' => 'Meldepflichten',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26', '5.27', '5.28'],
                    'legal_reference' => '§32 Abs. 3 NIS2UmsuCG',
                    'reporting_deadline' => '1_month',
                    'incident_management' => true,
                ],
            ],

            // Governance und Haftung
            [
                'id' => 'NIS2UMSUCG-5',
                'title' => 'Geschaeftsfuehrerhaftung',
                'description' => 'Persoenliche Haftung der Geschaeftsfuehrung bei Pflichtverletzung. Geschaeftsleitungen haften fuer Schaeden, die durch Verstoesse gegen die Pflichten zur Umsetzung von Risikomanagementmassnahmen entstehen. Die Haftung kann nicht durch Vereinbarung ausgeschlossen werden (§38 NIS2UmsuCG).',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3', '5.4'],
                    'legal_reference' => '§38 NIS2UmsuCG',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-6',
                'title' => 'Risikomanagementmassnahmen',
                'description' => 'Umsetzung technischer und organisatorischer Massnahmen nach Stand der Technik zur Sicherheit der Netz- und Informationssysteme. Die Massnahmen muessen verhaeltnismaessig sein und dem Risikoniveau angemessen (§30 NIS2UmsuCG).',
                'category' => 'Risikomanagement',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '8.1', '8.2'],
                    'legal_reference' => '§30 NIS2UmsuCG',
                    'risk_management_required' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-7',
                'title' => 'Supply-Chain-Sicherheit',
                'description' => 'Sicherheit der Lieferkette einschliesslich sicherheitsbezogener Aspekte der Beziehungen zwischen den einzelnen Einrichtungen und ihren unmittelbaren Anbietern oder Diensteanbietern (§30 Abs. 2 Nr. 5 NIS2UmsuCG).',
                'category' => 'Lieferkettensicherheit',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21', '5.22'],
                    'legal_reference' => '§30 Abs. 2 Nr. 5 NIS2UmsuCG',
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-8',
                'title' => 'Schulungen Geschaeftsfuehrung',
                'description' => 'Regelmaessige Cybersicherheitsschulungen fuer die Geschaeftsfuehrung. Mitglieder der Geschaeftsleitungen muessen an Schulungen teilnehmen, um ausreichende Kenntnisse und Faehigkeiten zur Erkennung und Bewertung von Risiken zu erwerben (§38 Abs. 3 NIS2UmsuCG).',
                'category' => 'Schulung',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3', '6.3'],
                    'legal_reference' => '§38 Abs. 3 NIS2UmsuCG',
                    'training_required' => true,
                ],
            ],

            // Nachweise und Aufsicht
            [
                'id' => 'NIS2UMSUCG-9',
                'title' => 'Nachweispflicht',
                'description' => 'Nachweis der Massnahmen gegenueber dem BSI. Besonders wichtige Einrichtungen muessen erstmalig drei Jahre nach Inkrafttreten und danach alle drei Jahre die Erfuellung der Anforderungen gegenueber dem BSI nachweisen (§34 NIS2UmsuCG).',
                'category' => 'Nachweis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.35', '5.36'],
                    'legal_reference' => '§34 NIS2UmsuCG',
                    'audit_evidence' => true,
                    'recurrence' => '3_years',
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-10',
                'title' => 'Sektoraufsicht',
                'description' => 'Zusammenarbeit mit zustaendiger Sektoraufsichtsbehoerde (BaFin, BNetzA, etc.). Die Sektoraufsichtsbehoerden ueberwachen die Einhaltung der Verpflichtungen in ihrem jeweiligen Zustaendigkeitsbereich (§60-63 NIS2UmsuCG).',
                'category' => 'Aufsicht',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.5'],
                    'legal_reference' => '§60-63 NIS2UmsuCG',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-11',
                'title' => 'Bussgeldrahmen',
                'description' => 'Bis zu 10 Mio. EUR oder 2% des weltweiten Jahresumsatzes fuer besonders wichtige Einrichtungen. Fuer wichtige Einrichtungen bis zu 7 Mio. EUR oder 1,4% des weltweiten Jahresumsatzes (§65 NIS2UmsuCG).',
                'category' => 'Sanktionen',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.31'],
                    'legal_reference' => '§65 NIS2UmsuCG',
                ],
            ],

            // Definitionen und technische Massnahmen
            [
                'id' => 'NIS2UMSUCG-12',
                'title' => 'Sicherheitsvorfall-Definition',
                'description' => 'Erweiterte Definition von Sicherheitsvorfaellen gemaess §2 Nr. 38 NIS2UmsuCG. Ein erheblicher Sicherheitsvorfall liegt vor, wenn er schwerwiegende Betriebsstoerungen oder finanzielle Verluste verursacht hat oder verursachen kann, oder wenn er andere natuerliche oder juristische Personen durch erhebliche materielle oder immaterielle Schaeden beeintraechtigt hat oder beeintraechtigen kann.',
                'category' => 'Definitionen',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'legal_reference' => '§2 Nr. 38 NIS2UmsuCG',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-13',
                'title' => 'Multi-Faktor-Authentisierung',
                'description' => 'Verwendung von Loesungen zur Multi-Faktor-Authentifizierung oder kontinuierlichen Authentifizierung, gesicherter Sprach-, Video- und Textkommunikation sowie gegebenenfalls gesicherter Notfallkommunikationssysteme (§30 Abs. 2 Nr. 10 NIS2UmsuCG).',
                'category' => 'Zugriffskontrolle',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                    'legal_reference' => '§30 Abs. 2 Nr. 10 NIS2UmsuCG',
                    'mfa_required' => true,
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-14',
                'title' => 'Kryptografie',
                'description' => 'Konzepte und Verfahren fuer den Einsatz von Kryptografie und gegebenenfalls Verschluesselung. Die eingesetzten kryptografischen Verfahren muessen dem Stand der Technik entsprechen (§30 Abs. 2 Nr. 8 NIS2UmsuCG).',
                'category' => 'Kryptografie',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'legal_reference' => '§30 Abs. 2 Nr. 8 NIS2UmsuCG',
                ],
            ],
            [
                'id' => 'NIS2UMSUCG-15',
                'title' => 'Personalsicherheit',
                'description' => 'Sicherheit des Personals, Konzepte fuer die Zugriffskontrolle und Management von Anlagen. Umfasst Massnahmen bei Einstellung, Beschaeftigung und nach Beendigung des Beschaeftigungsverhaeltnisses sowie Sensibilisierungsmassnahmen (§30 Abs. 2 Nr. 9 NIS2UmsuCG).',
                'category' => 'Personalsicherheit',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '5.15', '5.16', '6.1', '6.2', '6.3', '6.4', '6.5'],
                    'legal_reference' => '§30 Abs. 2 Nr. 9 NIS2UmsuCG',
                ],
            ],
        ];
    }
}
