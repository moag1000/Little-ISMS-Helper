<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\Risk;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * BSI IT-Grundschutz elementare Gefährdungen G0.1 - G0.47.
 *
 * Foundation P-14. The canonical "elementare Gefährdungen" catalog from the
 * BSI IT-Grundschutz-Kompendium. Module-gated under `bsi_it_grundschutz`
 * (mapped to the generic `risks` module here as fallback because the BSI
 * module key is not yet present in modules.yaml; the gating is enforced by
 * the registry and additionally checked in the apply-controller).
 *
 * All 47 hazards are emitted as a single bulk-template; consumers can
 * adopt the entire BSI baseline catalog in one step.
 */
final class BsiGefaehrdungenProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        foreach (['de', 'en'] as $lang) {
            yield new SystemTemplate(
                key: 'risk.bsi.elementare-gefaehrdungen.' . $lang,
                entityClass: Risk::class,
                module: 'risks',
                language: $lang,
                name: $lang === 'de'
                    ? 'BSI Elementare Gefährdungen G0.1 - G0.47'
                    : 'BSI Elementary Hazards G0.1 - G0.47',
                description: $lang === 'de'
                    ? 'Vollständiger Katalog der 47 elementaren Gefährdungen aus dem BSI IT-Grundschutz-Kompendium (Edition 2023). Geeignet als Risiko-Basisinventar für Basis- und Standard-Absicherung.'
                    : 'Complete catalog of 47 elementary hazards from the BSI IT-Grundschutz Compendium (Edition 2023). Suitable as a baseline risk inventory for Basis- and Standard-Absicherung.',
                prefill: [
                    'probability' => 2,
                    'impact' => 3,
                    'residualProbability' => 1,
                    'residualImpact' => 1,
                ],
                items: $this->items($lang),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $lang): array
    {
        $de = $lang === 'de';
        $rows = [];

        // G0.1 - G0.47 — short German titles canonical from BSI IT-Grundschutz-Kompendium 2023.
        $catalog = [
            ['G0.1', 'Feuer', 'Fire'],
            ['G0.2', 'Ungünstige klimatische Bedingungen', 'Unfavourable climatic conditions'],
            ['G0.3', 'Wasser', 'Water'],
            ['G0.4', 'Verschmutzung, Staub, Korrosion', 'Pollution, dust, corrosion'],
            ['G0.5', 'Naturkatastrophen', 'Natural disasters'],
            ['G0.6', 'Katastrophen im Umfeld', 'Environmental disasters'],
            ['G0.7', 'Großereignisse im Umfeld', 'Major events in surroundings'],
            ['G0.8', 'Ausfall oder Störung der Stromversorgung', 'Failure or disruption of power supply'],
            ['G0.9', 'Ausfall oder Störung von Kommunikationsnetzen', 'Failure or disruption of communication networks'],
            ['G0.10', 'Ausfall oder Störung von Versorgungsnetzen', 'Failure or disruption of supply networks'],
            ['G0.11', 'Ausfall oder Störung von Dienstleistern', 'Failure or disruption of service providers'],
            ['G0.12', 'Elektromagnetische Störstrahlung', 'Electromagnetic interference'],
            ['G0.13', 'Abfangen kompromittierender Strahlung', 'Interception of compromising emanations'],
            ['G0.14', 'Ausspähen von Informationen (Spionage)', 'Reconnaissance of information (espionage)'],
            ['G0.15', 'Abhören', 'Eavesdropping'],
            ['G0.16', 'Diebstahl von Geräten, Datenträgern oder Dokumenten', 'Theft of devices, data carriers, or documents'],
            ['G0.17', 'Verlust von Geräten, Datenträgern oder Dokumenten', 'Loss of devices, data carriers, or documents'],
            ['G0.18', 'Fehlplanung oder fehlende Anpassung', 'Inadequate planning or failure to adapt'],
            ['G0.19', 'Offenlegung schützenswerter Informationen', 'Disclosure of information requiring protection'],
            ['G0.20', 'Informationen oder Produkte aus unzuverlässiger Quelle', 'Information or products from unreliable source'],
            ['G0.21', 'Manipulation von Hard- oder Software', 'Manipulation of hardware or software'],
            ['G0.22', 'Manipulation von Informationen', 'Manipulation of information'],
            ['G0.23', 'Unbefugtes Eindringen in IT-Systeme', 'Unauthorised intrusion into IT systems'],
            ['G0.24', 'Zerstörung von Geräten oder Datenträgern', 'Destruction of devices or data carriers'],
            ['G0.25', 'Ausfall von Geräten oder Systemen', 'Failure of devices or systems'],
            ['G0.26', 'Fehlfunktion von Geräten oder Systemen', 'Malfunction of devices or systems'],
            ['G0.27', 'Ressourcenmangel', 'Lack of resources'],
            ['G0.28', 'Software-Schwachstellen oder -Fehler', 'Software vulnerabilities or errors'],
            ['G0.29', 'Verstoß gegen Gesetze oder Regelungen', 'Violation of laws or regulations'],
            ['G0.30', 'Unberechtigte Nutzung oder Administration von Geräten und Systemen', 'Unauthorised use or administration of devices and systems'],
            ['G0.31', 'Fehlerhafte Nutzung oder Administration von Geräten und Systemen', 'Faulty use or administration of devices and systems'],
            ['G0.32', 'Missbrauch von Berechtigungen', 'Abuse of authorisations'],
            ['G0.33', 'Personalausfall', 'Personnel absence'],
            ['G0.34', 'Anschlag', 'Attack'],
            ['G0.35', 'Nötigung, Erpressung, Korruption', 'Coercion, blackmail, corruption'],
            ['G0.36', 'Identitätsdiebstahl', 'Identity theft'],
            ['G0.37', 'Abstreiten von Handlungen', 'Repudiation of actions'],
            ['G0.38', 'Missbrauch personenbezogener Daten', 'Misuse of personal data'],
            ['G0.39', 'Schadprogramme', 'Malware'],
            ['G0.40', 'Verhinderung von Diensten (Denial of Service)', 'Denial of service'],
            ['G0.41', 'Sabotage', 'Sabotage'],
            ['G0.42', 'Social Engineering', 'Social engineering'],
            ['G0.43', 'Einspielen von Nachrichten', 'Replay of messages'],
            ['G0.44', 'Unbefugtes Eindringen in Räumlichkeiten', 'Unauthorised intrusion into premises'],
            ['G0.45', 'Datenverlust', 'Data loss'],
            ['G0.46', 'Integritätsverlust schützenswerter Informationen', 'Loss of integrity of information requiring protection'],
            ['G0.47', 'Schädliche Seiteneffekte IT-gestützter Angriffe', 'Harmful side effects of IT-supported attacks'],
        ];

        foreach ($catalog as [$code, $titleDe, $titleEn]) {
            $title = $de ? $titleDe : $titleEn;
            $rows[] = [
                'title' => $code . ' ' . $title,
                'category' => $de ? 'BSI elementare Gefährdung' : 'BSI elementary hazard',
                'threat' => $title,
                'description' => $de
                    ? sprintf('%s (%s) — siehe BSI IT-Grundschutz-Kompendium, Edition 2023.', $title, $code)
                    : sprintf('%s (%s) — see BSI IT-Grundschutz Compendium, Edition 2023.', $title, $code),
            ];
        }

        return $rows;
    }
}
