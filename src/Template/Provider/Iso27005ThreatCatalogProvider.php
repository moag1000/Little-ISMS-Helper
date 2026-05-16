<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\Risk;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * ISO 27005:2022 Annex C threat-catalog — generic IT risk starter pack.
 *
 * Foundation P-14. ~30 threats grouped by ISO 27005 category. Each entry
 * yields a Risk-Template prefilled with `title`, `category`, `threat`,
 * `description` and conservative default scores (probability=2, impact=3,
 * treatmentStrategy='mitigate').
 *
 * The whole batch is emitted as ONE bulk-template (`risk.iso27005.catalog`)
 * so the user can adopt the entire ISO 27005 baseline in one click. The
 * Apply-Controller iterates over `records()` and creates one Risk per row.
 */
final class Iso27005ThreatCatalogProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        foreach (['de', 'en'] as $lang) {
            yield new SystemTemplate(
                key: 'risk.iso27005.catalog.' . $lang,
                entityClass: Risk::class,
                module: 'risks',
                language: $lang,
                name: $lang === 'de'
                    ? 'ISO 27005:2022 Bedrohungskatalog (Anhang C)'
                    : 'ISO 27005:2022 Threat Catalog (Annex C)',
                description: $lang === 'de'
                    ? 'Standardisierter Bedrohungskatalog nach ISO 27005:2022 Anhang C. 30 generische IT-Risiken in 7 Kategorien (Höhere Gewalt, Vorsätzliche Handlungen, Fahrlässigkeit, Technisches Versagen, Software-Fehler, Kompromittierung, Funktionsstörung).'
                    : 'Standardized threat catalog from ISO 27005:2022 Annex C. 30 generic IT risks across 7 categories (Force majeure, Deliberate acts, Negligence, Technical failure, Software flaws, Compromise, Malfunction).',
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

        return [
            // Higher force / natural threats
            ['title' => $de ? 'Feuer / Brand' : 'Fire',
                'category' => $de ? 'Höhere Gewalt' : 'Force majeure',
                'threat' => $de ? 'Brandereignis in Gebäude oder Rack' : 'Fire in building or rack',
                'description' => $de ? 'Vollständiger oder partieller Verlust physischer Assets durch Brand.' : 'Total or partial loss of physical assets due to fire.'],
            ['title' => $de ? 'Wasserschaden' : 'Water damage',
                'category' => $de ? 'Höhere Gewalt' : 'Force majeure',
                'threat' => $de ? 'Rohrbruch, Hochwasser, Löschwasser' : 'Pipe burst, flooding, fire-suppression water',
                'description' => $de ? 'Korrosion und Kurzschluss in IT-Equipment durch Wasser.' : 'Corrosion and short-circuit damage to IT equipment.'],
            ['title' => $de ? 'Erdbeben / Naturkatastrophe' : 'Earthquake / natural disaster',
                'category' => $de ? 'Höhere Gewalt' : 'Force majeure',
                'threat' => $de ? 'Naturereignis mit struktureller Schadenwirkung' : 'Natural event causing structural damage',
                'description' => $de ? 'Bauliche Schäden mit Folge-Effekten auf Verfügbarkeit.' : 'Structural damage cascading into availability loss.'],
            ['title' => $de ? 'Stromausfall' : 'Power outage',
                'category' => $de ? 'Versorgungsausfall' : 'Supply failure',
                'threat' => $de ? 'Ausfall der Stromversorgung > 15 Minuten' : 'Power supply failure > 15 minutes',
                'description' => $de ? 'Verfügbarkeitsverlust bei nicht-redundanter USV.' : 'Availability loss with non-redundant UPS.'],
            ['title' => $de ? 'Klimatisierung Ausfall' : 'Climate-control failure',
                'category' => $de ? 'Versorgungsausfall' : 'Supply failure',
                'threat' => $de ? 'Überhitzung im Serverraum' : 'Overheating in server room',
                'description' => $de ? 'Hardware-Notabschaltung und Lebensdauer-Reduktion.' : 'Hardware emergency shutdown and lifespan reduction.'],

            // Deliberate threats
            ['title' => $de ? 'Diebstahl von Hardware' : 'Hardware theft',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Entwendung von Laptops, Mobilgeräten, Datenträgern' : 'Theft of laptops, mobile devices, data carriers',
                'description' => $de ? 'Verlust von Vertraulichkeit und Verfügbarkeit.' : 'Loss of confidentiality and availability.'],
            ['title' => $de ? 'Sabotage' : 'Sabotage',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Mutwillige Zerstörung oder Manipulation' : 'Wilful destruction or tampering',
                'description' => $de ? 'Innere oder äußere Täter mit Schadensabsicht.' : 'Internal or external actors with destructive intent.'],
            ['title' => $de ? 'Phishing / Social Engineering' : 'Phishing / Social engineering',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Täuschung zur Preisgabe von Zugangsdaten' : 'Deception to obtain credentials',
                'description' => $de ? 'E-Mail-, Voice-, SMS-Phishing als Initialvektor.' : 'Email, voice, SMS phishing as initial vector.'],
            ['title' => $de ? 'Malware / Ransomware' : 'Malware / Ransomware',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Schadcode mit Verschlüsselung oder Exfiltration' : 'Malicious code encrypting or exfiltrating data',
                'description' => $de ? 'Wiederherstellungsaufwand + Lösegeldforderung + Reputationsschaden.' : 'Recovery cost + ransom + reputational damage.'],
            ['title' => $de ? 'Denial of Service (DoS/DDoS)' : 'Denial of Service (DoS/DDoS)',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Überlastung exponierter Dienste' : 'Overload of exposed services',
                'description' => $de ? 'Verfügbarkeitsverlust kundennaher Schnittstellen.' : 'Availability loss of customer-facing interfaces.'],
            ['title' => $de ? 'Unbefugter Zugriff' : 'Unauthorised access',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Umgehung der Zugriffskontrolle' : 'Bypass of access controls',
                'description' => $de ? 'Privilege Escalation oder ungeschützte Schnittstelle.' : 'Privilege escalation or unguarded interface.'],
            ['title' => $de ? 'Datendiebstahl / Exfiltration' : 'Data theft / exfiltration',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Abfluss vertraulicher Daten' : 'Outflow of confidential data',
                'description' => $de ? 'Innentäter oder kompromittierte Konten.' : 'Insider or compromised accounts.'],
            ['title' => $de ? 'Identitätsdiebstahl' : 'Identity theft',
                'category' => $de ? 'Vorsätzliche Handlung' : 'Deliberate act',
                'threat' => $de ? 'Übernahme legitimer Identitäten' : 'Takeover of legitimate identities',
                'description' => $de ? 'Credential-Stuffing und MFA-Bypass-Angriffe.' : 'Credential-stuffing and MFA-bypass attacks.'],

            // Negligence
            ['title' => $de ? 'Fehlbedienung' : 'Operator error',
                'category' => $de ? 'Fahrlässigkeit' : 'Negligence',
                'threat' => $de ? 'Fehlerhafte Konfiguration oder Eingabe' : 'Faulty configuration or input',
                'description' => $de ? 'Menschliches Versagen ohne Vorsatz.' : 'Human error without intent.'],
            ['title' => $de ? 'Verlust mobiler Datenträger' : 'Loss of mobile media',
                'category' => $de ? 'Fahrlässigkeit' : 'Negligence',
                'threat' => $de ? 'Versehentlicher Verlust außer Haus' : 'Accidental loss off-site',
                'description' => $de ? 'USB-Sticks, externe Festplatten, Smartphones.' : 'USB sticks, external drives, smartphones.'],
            ['title' => $de ? 'Unsachgemäße Entsorgung' : 'Improper disposal',
                'category' => $de ? 'Fahrlässigkeit' : 'Negligence',
                'threat' => $de ? 'Datenträger nicht sicher gelöscht' : 'Media not securely wiped',
                'description' => $de ? 'Recover-Tools rekonstruieren Daten aus Altgeräten.' : 'Recovery tools reconstruct data from old devices.'],
            ['title' => $de ? 'Fehlversand E-Mail / Brief' : 'Mail mis-delivery',
                'category' => $de ? 'Fahrlässigkeit' : 'Negligence',
                'threat' => $de ? 'Versand an falschen Empfänger' : 'Delivery to wrong recipient',
                'description' => $de ? 'Auto-Vervollständigung und Bcc-Fehler.' : 'Auto-complete and Bcc mistakes.'],

            // Technical failure
            ['title' => $de ? 'Hardware-Defekt' : 'Hardware failure',
                'category' => $de ? 'Technisches Versagen' : 'Technical failure',
                'threat' => $de ? 'Ausfall von Festplatte, Controller, Netzteil' : 'Failure of disk, controller, PSU',
                'description' => $de ? 'Mean Time Between Failures überschritten.' : 'Mean Time Between Failures exceeded.'],
            ['title' => $de ? 'Datenträger-Verlust' : 'Storage media loss',
                'category' => $de ? 'Technisches Versagen' : 'Technical failure',
                'threat' => $de ? 'Bit-Rot oder Tape-Degradation' : 'Bit-rot or tape degradation',
                'description' => $de ? 'Stille Datenkorruption ohne Prüfsummen.' : 'Silent data corruption without checksums.'],
            ['title' => $de ? 'Netzwerkausfall' : 'Network outage',
                'category' => $de ? 'Technisches Versagen' : 'Technical failure',
                'threat' => $de ? 'Verbindungsverlust intern oder zum Internet' : 'Loss of internal or internet connectivity',
                'description' => $de ? 'Routing-, DNS- oder Carrier-Problem.' : 'Routing, DNS, or carrier issue.'],

            // Software flaws
            ['title' => $de ? 'Software-Bug' : 'Software defect',
                'category' => $de ? 'Software-Fehler' : 'Software flaws',
                'threat' => $de ? 'Logikfehler in Anwendung oder OS' : 'Logic defect in application or OS',
                'description' => $de ? 'Fehlfunktion ohne Sicherheitsbezug.' : 'Malfunction without security impact.'],
            ['title' => $de ? 'Sicherheitslücke (CVE)' : 'Security vulnerability (CVE)',
                'category' => $de ? 'Software-Fehler' : 'Software flaws',
                'threat' => $de ? 'Ausnutzbare Schwachstelle in Komponente' : 'Exploitable weakness in component',
                'description' => $de ? 'Public Exploit oder 0day mit kritischer CVSS.' : 'Public exploit or 0day with critical CVSS.'],
            ['title' => $de ? 'Fehlende Patches' : 'Missing patches',
                'category' => $de ? 'Software-Fehler' : 'Software flaws',
                'threat' => $de ? 'Vendor-Updates nicht eingespielt' : 'Vendor updates not applied',
                'description' => $de ? 'Bekannte CVEs bleiben angreifbar.' : 'Known CVEs remain exploitable.'],
            ['title' => $de ? 'Veraltete Verschlüsselung' : 'Outdated cryptography',
                'category' => $de ? 'Software-Fehler' : 'Software flaws',
                'threat' => $de ? 'Schwache Algorithmen oder Schlüssellängen' : 'Weak algorithms or key lengths',
                'description' => $de ? 'MD5, SHA-1, RSA-1024, TLS 1.0/1.1.' : 'MD5, SHA-1, RSA-1024, TLS 1.0/1.1.'],

            // Compromise / Compliance
            ['title' => $de ? 'Verstoß gegen gesetzliche Auflagen' : 'Regulatory non-compliance',
                'category' => $de ? 'Compliance' : 'Compliance',
                'threat' => $de ? 'Nicht-Erfüllung normativer Pflichten' : 'Failure to meet normative duties',
                'description' => $de ? 'DSGVO, NIS2, DORA, branchenspezifische Vorgaben.' : 'GDPR, NIS2, DORA, sector-specific requirements.'],
            ['title' => $de ? 'Vertragsverletzung Dienstleister' : 'Supplier contract breach',
                'category' => $de ? 'Compliance' : 'Compliance',
                'threat' => $de ? 'SLA, AVV oder DPA nicht eingehalten' : 'SLA, DPA, or contract terms not met',
                'description' => $de ? 'Schadensersatz, Reputationsverlust, Sonderkündigung.' : 'Liability, reputation loss, special termination.'],
            ['title' => $de ? 'Spionage' : 'Espionage',
                'category' => $de ? 'Kompromittierung' : 'Compromise',
                'threat' => $de ? 'Gezieltes Abschöpfen vertraulicher Informationen' : 'Targeted exfiltration of confidential information',
                'description' => $de ? 'Industriespionage oder staatlich gestützte Akteure (APT).' : 'Industrial espionage or state-backed actors (APT).'],
            ['title' => $de ? 'Lauschangriff' : 'Eavesdropping',
                'category' => $de ? 'Kompromittierung' : 'Compromise',
                'threat' => $de ? 'Mitlesen unverschlüsselter Kommunikation' : 'Interception of unencrypted communication',
                'description' => $de ? 'Man-in-the-Middle in unsicheren Netzen.' : 'Man-in-the-middle in untrusted networks.'],

            // Malfunction / Other
            ['title' => $de ? 'Funktionsstörung Drittsystem' : 'Third-party system malfunction',
                'category' => $de ? 'Funktionsstörung' : 'Malfunction',
                'threat' => $de ? 'Ausfall einer abhängigen Cloud-API' : 'Failure of a dependent cloud API',
                'description' => $de ? 'Single-Point-of-Failure in SaaS-Abhängigkeit.' : 'Single point of failure in SaaS dependency.'],
            ['title' => $de ? 'Personalausfall Schlüsselrolle' : 'Key-person absence',
                'category' => $de ? 'Personell' : 'Personnel',
                'threat' => $de ? 'Wegfall einer kritischen Wissensperson' : 'Loss of critical knowledge holder',
                'description' => $de ? 'Krankheit, Kündigung oder Unfall ohne Stellvertreter.' : 'Illness, resignation, or accident without deputy.'],
        ];
    }
}
