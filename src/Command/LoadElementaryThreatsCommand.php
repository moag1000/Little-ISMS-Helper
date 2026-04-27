<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ElementaryThreat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-elementary-threats',
    description: 'Load BSI 200-3 Elementary Threats (Elementare Gefährdungen G 0.1 - G 0.47) into the database'
)]
class LoadElementaryThreatsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing threats instead of skipping them')]
        bool $update = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int {
        $symfonyStyle->title('Loading BSI 200-3 Elementary Threats (Elementare Gefährdungen)');
        $symfonyStyle->text(sprintf('Mode: %s', $update ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        $threats = $this->getElementaryThreats();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($threats as $threatData) {
            $existing = $this->entityManager->getRepository(ElementaryThreat::class)
                ->findOneBy(['threatId' => $threatData['threatId']]);

            if ($existing instanceof ElementaryThreat) {
                if ($update) {
                    $existing->setName($threatData['name'])
                        ->setNameEn($threatData['nameEn'])
                        ->setCategory($threatData['category'])
                        ->setDescription($threatData['description']);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $threat = new ElementaryThreat();
                $threat->setThreatId($threatData['threatId'])
                    ->setName($threatData['name'])
                    ->setNameEn($threatData['nameEn'])
                    ->setCategory($threatData['category'])
                    ->setDescription($threatData['description']);

                $this->entityManager->persist($threat);
                $stats['created']++;
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success(sprintf(
            'Elementary Threats: %d created, %d updated, %d skipped (total catalog: %d)',
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            count($threats)
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{threatId: string, name: string, nameEn: string, category: string, description: string}>
     */
    private function getElementaryThreats(): array
    {
        return [
            // Force Majeure (G 0.1 - G 0.7)
            [
                'threatId' => 'G 0.1',
                'name' => 'Feuer',
                'nameEn' => 'Fire',
                'category' => 'force_majeure',
                'description' => 'Feuer kann schwere Schäden an Gebäuden, Infrastruktur und IT-Systemen verursachen. Neben direkten Schäden durch Flammen können auch Rauch, Ruß und Löschwasser erhebliche Folgeschäden verursachen.',
            ],
            [
                'threatId' => 'G 0.2',
                'name' => 'Ungünstige klimatische Bedingungen',
                'nameEn' => 'Unfavorable climatic conditions',
                'category' => 'force_majeure',
                'description' => 'Extreme Temperaturen, Feuchtigkeit oder andere klimatische Einflüsse können IT-Systeme und Infrastruktur beeinträchtigen oder beschädigen.',
            ],
            [
                'threatId' => 'G 0.3',
                'name' => 'Wasser',
                'nameEn' => 'Water',
                'category' => 'force_majeure',
                'description' => 'Wasser durch Überschwemmung, Rohrbruch, Leckagen oder Starkregen kann IT-Systeme und Infrastruktur schwer beschädigen.',
            ],
            [
                'threatId' => 'G 0.4',
                'name' => 'Verschmutzung, Staub, Korrosion',
                'nameEn' => 'Pollution, dust, corrosion',
                'category' => 'force_majeure',
                'description' => 'Verschmutzung, Staub und Korrosion können die Funktionsfähigkeit von IT-Systemen und technischer Infrastruktur beeinträchtigen.',
            ],
            [
                'threatId' => 'G 0.5',
                'name' => 'Naturkatastrophen',
                'nameEn' => 'Natural disasters',
                'category' => 'force_majeure',
                'description' => 'Naturkatastrophen wie Erdbeben, Vulkanausbrüche, Tsunamis, Erdrutsche oder Lawinen können massive Schäden verursachen.',
            ],
            [
                'threatId' => 'G 0.6',
                'name' => 'Katastrophen im Umfeld',
                'nameEn' => 'Disasters in the environment',
                'category' => 'force_majeure',
                'description' => 'Katastrophen in der Umgebung wie Industrieunfälle, Gefahrguttransporte oder Explosionen in benachbarten Einrichtungen können die eigene Organisation gefährden.',
            ],
            [
                'threatId' => 'G 0.7',
                'name' => 'Großereignisse im Umfeld',
                'nameEn' => 'Major events in the environment',
                'category' => 'force_majeure',
                'description' => 'Großereignisse in der Umgebung wie Demonstrationen, Sportveranstaltungen oder Streiks können den Geschäftsbetrieb beeinträchtigen.',
            ],

            // Organizational (G 0.8 - G 0.13, G 0.18, G 0.27, G 0.29)
            [
                'threatId' => 'G 0.8',
                'name' => 'Ausfall oder Störung der Stromversorgung',
                'nameEn' => 'Power supply failure',
                'category' => 'organizational',
                'description' => 'Ein Ausfall oder Störungen der Stromversorgung können zum Totalausfall der IT-Infrastruktur führen und Datenverluste verursachen.',
            ],
            [
                'threatId' => 'G 0.9',
                'name' => 'Ausfall oder Störung von Kommunikationsnetzen',
                'nameEn' => 'Communication network failure',
                'category' => 'organizational',
                'description' => 'Störungen oder Ausfälle von Kommunikationsnetzen können die Erreichbarkeit von Diensten und die Kommunikationsfähigkeit erheblich einschränken.',
            ],
            [
                'threatId' => 'G 0.10',
                'name' => 'Ausfall oder Störung von Versorgungsnetzen',
                'nameEn' => 'Supply network failure',
                'category' => 'organizational',
                'description' => 'Der Ausfall von Versorgungsnetzen wie Wasser, Heizung oder Kühlung kann den Betrieb von IT-Systemen und Rechenzentren gefährden.',
            ],
            [
                'threatId' => 'G 0.11',
                'name' => 'Ausfall oder Störung von Dienstleistern',
                'nameEn' => 'Service provider failure',
                'category' => 'organizational',
                'description' => 'Der Ausfall oder die Störung bei externen Dienstleistern kann kritische Geschäftsprozesse und IT-Services beeinträchtigen.',
            ],
            [
                'threatId' => 'G 0.12',
                'name' => 'Elektromagnetische Störstrahlung',
                'nameEn' => 'Electromagnetic interference',
                'category' => 'organizational',
                'description' => 'Elektromagnetische Störstrahlung kann die Funktion elektronischer Geräte beeinträchtigen und zu Datenverlust oder Fehlfunktionen führen.',
            ],
            [
                'threatId' => 'G 0.13',
                'name' => 'Abfangen kompromittierender Strahlung',
                'nameEn' => 'Interception of compromising emanations',
                'category' => 'organizational',
                'description' => 'Durch Abfangen elektromagnetischer Abstrahlung von IT-Geräten können vertrauliche Informationen ausgespäht werden.',
            ],

            // Human (G 0.14 - G 0.17)
            [
                'threatId' => 'G 0.14',
                'name' => 'Ausspähen von Informationen',
                'nameEn' => 'Espionage',
                'category' => 'human',
                'description' => 'Durch gezieltes Ausspähen können vertrauliche Informationen in unbefugte Hände gelangen, z.B. durch Wirtschaftsspionage oder Konkurrenzausspähung.',
            ],
            [
                'threatId' => 'G 0.15',
                'name' => 'Abhören',
                'nameEn' => 'Eavesdropping',
                'category' => 'human',
                'description' => 'Durch Abhören von Kommunikationsverbindungen oder Gesprächen können vertrauliche Informationen kompromittiert werden.',
            ],
            [
                'threatId' => 'G 0.16',
                'name' => 'Diebstahl von Geräten, Datenträgern oder Dokumenten',
                'nameEn' => 'Theft of devices',
                'category' => 'human',
                'description' => 'Der Diebstahl von IT-Geräten, Datenträgern oder Dokumenten kann zu Verlust vertraulicher Informationen und zur Unterbrechung von Geschäftsprozessen führen.',
            ],
            [
                'threatId' => 'G 0.17',
                'name' => 'Verlust von Geräten, Datenträgern oder Dokumenten',
                'nameEn' => 'Loss of devices',
                'category' => 'human',
                'description' => 'Der Verlust von IT-Geräten, Datenträgern oder Dokumenten durch Unachtsamkeit kann zum unkontrollierten Abfluss vertraulicher Informationen führen.',
            ],

            // Organizational (G 0.18)
            [
                'threatId' => 'G 0.18',
                'name' => 'Fehlplanung oder fehlende Anpassung',
                'nameEn' => 'Faulty planning or lack of adaptation',
                'category' => 'organizational',
                'description' => 'Fehlende oder mangelhafte Planung von IT-Systemen und Prozessen sowie fehlende Anpassung an veränderte Anforderungen können zu Sicherheitslücken führen.',
            ],

            // Human (G 0.19 - G 0.24)
            [
                'threatId' => 'G 0.19',
                'name' => 'Offenlegung schützenswerter Informationen',
                'nameEn' => 'Disclosure of sensitive information',
                'category' => 'human',
                'description' => 'Durch versehentliche oder absichtliche Offenlegung können schützenswerte Informationen an Unbefugte gelangen.',
            ],
            [
                'threatId' => 'G 0.20',
                'name' => 'Informationen oder Produkte aus unzuverlässiger Quelle',
                'nameEn' => 'Information from unreliable source',
                'category' => 'human',
                'description' => 'Die Verwendung von Informationen oder Produkten aus unzuverlässigen Quellen kann zu Fehlentscheidungen oder Sicherheitsvorfällen führen.',
            ],
            [
                'threatId' => 'G 0.21',
                'name' => 'Manipulation von Hard- oder Software',
                'nameEn' => 'Manipulation of hardware or software',
                'category' => 'human',
                'description' => 'Durch Manipulation von Hard- oder Software können Sicherheitsmechanismen umgangen und die Integrität von Systemen kompromittiert werden.',
            ],
            [
                'threatId' => 'G 0.22',
                'name' => 'Manipulation von Informationen',
                'nameEn' => 'Manipulation of information',
                'category' => 'human',
                'description' => 'Die unbefugte Manipulation von Informationen kann zu Fehlentscheidungen, finanziellen Schäden oder Reputationsverlust führen.',
            ],
            [
                'threatId' => 'G 0.23',
                'name' => 'Unbefugtes Eindringen in IT-Systeme',
                'nameEn' => 'Unauthorized intrusion into IT systems',
                'category' => 'human',
                'description' => 'Unbefugtes Eindringen in IT-Systeme durch Ausnutzung von Schwachstellen kann zu Datenverlust, Manipulation oder Betriebsunterbrechungen führen.',
            ],
            [
                'threatId' => 'G 0.24',
                'name' => 'Zerstörung von Geräten oder Datenträgern',
                'nameEn' => 'Destruction of devices or media',
                'category' => 'human',
                'description' => 'Die absichtliche oder fahrlässige Zerstörung von IT-Geräten oder Datenträgern kann zu Datenverlust und Betriebsunterbrechungen führen.',
            ],

            // Technical (G 0.25 - G 0.26)
            [
                'threatId' => 'G 0.25',
                'name' => 'Ausfall von Geräten oder Systemen',
                'nameEn' => 'Failure of devices or systems',
                'category' => 'technical',
                'description' => 'Der technische Ausfall von IT-Geräten oder Systemen durch Defekte, Verschleiß oder Überlastung kann Geschäftsprozesse unterbrechen.',
            ],
            [
                'threatId' => 'G 0.26',
                'name' => 'Fehlfunktion von Geräten oder Systemen',
                'nameEn' => 'Malfunction of devices or systems',
                'category' => 'technical',
                'description' => 'Fehlfunktionen von IT-Geräten oder Systemen können zu fehlerhaften Ergebnissen, Datenverlust oder Sicherheitsproblemen führen.',
            ],

            // Organizational (G 0.27)
            [
                'threatId' => 'G 0.27',
                'name' => 'Ressourcenmangel',
                'nameEn' => 'Resource shortage',
                'category' => 'organizational',
                'description' => 'Mangel an personellen, finanziellen oder technischen Ressourcen kann die ordnungsgemäße Umsetzung von Sicherheitsmaßnahmen gefährden.',
            ],

            // Technical (G 0.28)
            [
                'threatId' => 'G 0.28',
                'name' => 'Software-Schwachstellen',
                'nameEn' => 'Software vulnerabilities',
                'category' => 'technical',
                'description' => 'Software-Schwachstellen durch Programmierfehler oder Design-Mängel können von Angreifern ausgenutzt werden, um Systeme zu kompromittieren.',
            ],

            // Organizational (G 0.29)
            [
                'threatId' => 'G 0.29',
                'name' => 'Verstoß gegen Gesetze oder Regelungen',
                'nameEn' => 'Violation of laws or regulations',
                'category' => 'organizational',
                'description' => 'Verstöße gegen Gesetze, Vorschriften oder vertragliche Regelungen können rechtliche Konsequenzen und finanzielle Schäden nach sich ziehen.',
            ],

            // Human (G 0.30 - G 0.38)
            [
                'threatId' => 'G 0.30',
                'name' => 'Unberechtigte Nutzung oder Administration von Geräten und Systemen',
                'nameEn' => 'Unauthorized use',
                'category' => 'human',
                'description' => 'Die unberechtigte Nutzung oder Administration von IT-Geräten und Systemen kann zu Sicherheitsverletzungen und Datenverlust führen.',
            ],
            [
                'threatId' => 'G 0.31',
                'name' => 'Fehlerhafte Nutzung oder Administration von Geräten und Systemen',
                'nameEn' => 'Incorrect usage',
                'category' => 'human',
                'description' => 'Fehlerhafte Bedienung oder Administration von IT-Systemen durch unzureichend geschultes Personal kann zu Störungen und Sicherheitsproblemen führen.',
            ],
            [
                'threatId' => 'G 0.32',
                'name' => 'Missbrauch von Berechtigungen',
                'nameEn' => 'Abuse of permissions',
                'category' => 'human',
                'description' => 'Der Missbrauch von Zugriffsberechtigungen durch autorisierte Benutzer kann zu unbefugtem Zugriff auf sensible Daten führen.',
            ],
            [
                'threatId' => 'G 0.33',
                'name' => 'Personalausfall',
                'nameEn' => 'Personnel loss',
                'category' => 'human',
                'description' => 'Der Ausfall von Schlüsselpersonal durch Krankheit, Kündigung oder andere Gründe kann kritische Geschäftsprozesse gefährden.',
            ],
            [
                'threatId' => 'G 0.34',
                'name' => 'Anschlag',
                'nameEn' => 'Attack',
                'category' => 'human',
                'description' => 'Gezielte physische Anschläge auf Gebäude, Infrastruktur oder Personen können den Geschäftsbetrieb erheblich stören oder zerstören.',
            ],
            [
                'threatId' => 'G 0.35',
                'name' => 'Nötigung, Erpressung oder Korruption',
                'nameEn' => 'Coercion, blackmail, corruption',
                'category' => 'human',
                'description' => 'Durch Nötigung, Erpressung oder Korruption können Mitarbeiter dazu gebracht werden, gegen Sicherheitsrichtlinien zu verstoßen.',
            ],
            [
                'threatId' => 'G 0.36',
                'name' => 'Identitätsdiebstahl',
                'nameEn' => 'Identity theft',
                'category' => 'human',
                'description' => 'Durch Identitätsdiebstahl können sich Angreifer als autorisierte Benutzer ausgeben und unbefugten Zugriff auf Systeme und Daten erlangen.',
            ],
            [
                'threatId' => 'G 0.37',
                'name' => 'Abstreiten von Handlungen',
                'nameEn' => 'Repudiation',
                'category' => 'human',
                'description' => 'Wenn durchgeführte Handlungen nicht nachweisbar sind, können Benutzer diese abstreiten, was zu rechtlichen und sicherheitstechnischen Problemen führt.',
            ],
            [
                'threatId' => 'G 0.38',
                'name' => 'Missbrauch personenbezogener Daten',
                'nameEn' => 'Abuse of personal data',
                'category' => 'human',
                'description' => 'Der Missbrauch personenbezogener Daten kann zu Datenschutzverletzungen, rechtlichen Konsequenzen und Reputationsschäden führen.',
            ],

            // Technical (G 0.39 - G 0.40)
            [
                'threatId' => 'G 0.39',
                'name' => 'Schadprogramme',
                'nameEn' => 'Malware',
                'category' => 'technical',
                'description' => 'Schadprogramme wie Viren, Trojaner, Ransomware oder Würmer können IT-Systeme infizieren und erhebliche Schäden verursachen.',
            ],
            [
                'threatId' => 'G 0.40',
                'name' => 'Verhinderung von Diensten (Denial of Service)',
                'nameEn' => 'Denial of Service',
                'category' => 'technical',
                'description' => 'Denial-of-Service-Angriffe können die Verfügbarkeit von IT-Diensten und Systemen durch gezielte Überlastung beeinträchtigen.',
            ],

            // Human (G 0.41 - G 0.44)
            [
                'threatId' => 'G 0.41',
                'name' => 'Sabotage',
                'nameEn' => 'Sabotage',
                'category' => 'human',
                'description' => 'Sabotage durch interne oder externe Täter kann zur gezielten Zerstörung oder Beeinträchtigung von IT-Systemen und Infrastruktur führen.',
            ],
            [
                'threatId' => 'G 0.42',
                'name' => 'Social Engineering',
                'nameEn' => 'Social Engineering',
                'category' => 'human',
                'description' => 'Durch Social Engineering werden Mitarbeiter manipuliert, um vertrauliche Informationen preiszugeben oder sicherheitskritische Handlungen durchzuführen.',
            ],
            [
                'threatId' => 'G 0.43',
                'name' => 'Einspielen von Nachrichten',
                'nameEn' => 'Message injection',
                'category' => 'human',
                'description' => 'Durch Einspielen gefälschter Nachrichten in Kommunikationskanäle können Systeme manipuliert oder Benutzer getäuscht werden.',
            ],
            [
                'threatId' => 'G 0.44',
                'name' => 'Unbefugtes Eindringen in Räumlichkeiten',
                'nameEn' => 'Unauthorized physical access',
                'category' => 'human',
                'description' => 'Unbefugtes Eindringen in Gebäude oder Räume kann zu Diebstahl, Manipulation oder Zerstörung von IT-Systemen und Informationen führen.',
            ],

            // Technical (G 0.45 - G 0.47)
            [
                'threatId' => 'G 0.45',
                'name' => 'Datenverlust',
                'nameEn' => 'Data loss',
                'category' => 'technical',
                'description' => 'Der Verlust von Daten durch technische Defekte, menschliches Versagen oder gezielte Angriffe kann erhebliche geschäftliche Auswirkungen haben.',
            ],
            [
                'threatId' => 'G 0.46',
                'name' => 'Integritätsverlust schützenswerter Informationen',
                'nameEn' => 'Integrity loss',
                'category' => 'technical',
                'description' => 'Der Verlust der Integrität schützenswerter Informationen durch unbefugte oder unbeabsichtigte Änderungen kann zu Fehlentscheidungen führen.',
            ],
            [
                'threatId' => 'G 0.47',
                'name' => 'Schädliche Seiteneffekte IT-gestützter Angriffe',
                'nameEn' => 'Harmful side effects of IT attacks',
                'category' => 'technical',
                'description' => 'IT-gestützte Angriffe können neben den beabsichtigten auch unbeabsichtigte schädliche Seiteneffekte auf andere Systeme und Prozesse haben.',
            ],
        ];
    }
}
