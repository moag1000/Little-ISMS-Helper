<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ISM plan Finding #6: seed ready-to-use BC-Plan draft templates for a tenant.
 *
 * Usage:
 *   php bin/console app:seed-bc-plan-templates 42
 *   php bin/console app:seed-bc-plan-templates 42 --overwrite
 */
#[AsCommand(
    name: 'app:seed-bc-plan-templates',
    description: 'Seed 5 standard BC-Plan drafts (IT outage, pandemic, data breach, building, supply chain) for a tenant'
)]
class SeedBcPlanTemplatesCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(
        #[Argument(description: 'Target tenant ID')] int $tenantId,
        #[Option(description: 'Overwrite existing draft templates with the same name')] bool $overwrite = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $tenant = $this->entityManager->getRepository(Tenant::class)->find($tenantId);
        if (!$tenant instanceof Tenant) {
            $symfonyStyle?->error("Tenant #{$tenantId} not found.");
            return Command::FAILURE;
        }

        $symfonyStyle?->title('Seeding BC-Plan templates for tenant: ' . ($tenant->getName() ?? 'n/a'));

        // Pick an arbitrary business process to attach the drafts to (required FK).
        $businessProcess = $this->entityManager->getRepository(BusinessProcess::class)
            ->findOneBy(['tenant' => $tenant]);
        if (!$businessProcess instanceof BusinessProcess) {
            $symfonyStyle?->error('Tenant has no BusinessProcess — create one first (BC-Plans require a process link).');
            return Command::FAILURE;
        }

        $templates = $this->getTemplates();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($templates as $data) {
            $existing = $this->entityManager->getRepository(BusinessContinuityPlan::class)
                ->findOneBy(['tenant' => $tenant, 'name' => $data['name']]);

            if ($existing instanceof BusinessContinuityPlan && !$overwrite) {
                $stats['skipped']++;
                continue;
            }

            $plan = $existing instanceof BusinessContinuityPlan ? $existing : new BusinessContinuityPlan();
            if (!$existing) {
                $plan->setTenant($tenant);
                $plan->setBusinessProcess($businessProcess);
                $plan->setName($data['name']);
            }
            $plan->setDescription($data['description']);
            $plan->setPlanOwner($data['plan_owner']);
            $plan->setBcTeam($data['bc_team']);
            $plan->setStatus('draft');
            $plan->setActivationCriteria($data['activation_criteria']);
            $plan->setRolesAndResponsibilities($data['roles']);
            $plan->setRecoveryProcedures($data['recovery']);
            $plan->setCommunicationPlan($data['communication']);
            $plan->setInternalCommunication($data['internal_communication']);
            $plan->setExternalCommunication($data['external_communication']);
            $plan->setBackupProcedures($data['backup']);
            $plan->setRestoreProcedures($data['restore']);
            $plan->setVersion('1.0 (template)');

            if ($existing) {
                $stats['updated']++;
            } else {
                $this->entityManager->persist($plan);
                $stats['created']++;
            }
        }

        $this->entityManager->flush();
        $symfonyStyle?->success(sprintf(
            'BC-Plan templates: %d created, %d updated, %d skipped (existing).',
            $stats['created'], $stats['updated'], $stats['skipped']
        ));
        return Command::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function getTemplates(): array
    {
        return [
            [
                'name' => 'IT-Ausfall (Template)',
                'description' => 'Kompletter oder partieller Ausfall zentraler IT-Systeme (Server, Netzwerk, SaaS). Wiederherstellung geschäftskritischer Services.',
                'plan_owner' => 'CIO / IT-Leitung',
                'bc_team' => 'IT-Operations, Infrastruktur, Security, Kommunikation',
                'activation_criteria' => "- Ausfall eines kritischen Systems > 1 Stunde\n- Bekannte Ursache verhindert Selbstwiederherstellung\n- Mehr als 50% der Mitarbeiter betroffen",
                'roles' => "- IT-Leitung: Gesamtkoordination, Entscheidung über Fallback\n- Infrastruktur: Hardware-/Netzwerk-Diagnose\n- Security: Ausschluss Cyberangriff\n- Kommunikation: Statusmeldungen intern/extern",
                'recovery' => "1. Incident-Ticket anlegen, Severity festlegen\n2. Betroffene Systeme eindeutig identifizieren (Monitoring)\n3. Fallback-Systeme aktivieren (DR-Site, Cloud-Failover)\n4. Datenintegrität nach Wiederherstellung prüfen\n5. Rollback-Plan falls Wiederherstellung scheitert\n6. Post-Mortem innerhalb 5 Werktagen",
                'communication' => 'Eskalation an CIO und Vorstand bei > 4h Ausfall. Externe Kommunikation nur durch Pressestelle.',
                'internal_communication' => 'Status-E-Mail alle 2 Stunden; Teams-Channel #incident-response; Dashboard-Update.',
                'external_communication' => 'Kunden informieren wenn SLA berührt. Aufsichtsbehörde bei NIS2-relevantem Incident innerhalb 24h.',
                'backup' => 'Tägliches Backup geschäftskritischer Systeme; wöchentliche Wiederherstellungstests.',
                'restore' => 'RTO ≤ 4h / RPO ≤ 1h. Backup-Restore via dokumentierte Runbooks pro System.',
            ],
            [
                'name' => 'Pandemie / Großflächiger Personalausfall (Template)',
                'description' => 'Plan für Szenarien mit Ausfall eines Großteils der Belegschaft (Epidemie, Pandemie, lokaler Katastrophenfall).',
                'plan_owner' => 'COO / HR-Leitung',
                'bc_team' => 'HR, IT, Facility-Management, Kommunikation, Arbeitsschutz',
                'activation_criteria' => "- Offizielle Pandemie-Erklärung durch WHO / RKI\n- Krankenstand > 25% der Belegschaft\n- Behördlich angeordnete Schließungen",
                'roles' => "- HR-Leitung: Abwesenheits-Tracking, Arbeitsschutz\n- IT: Remote-Work-Infrastruktur skalieren\n- Facility-Management: Hygienekonzept, Raumbelegung\n- Kommunikation: Mitarbeiterinformation",
                'recovery' => "1. Remote-Work für alle nicht-vor-Ort-Tätigkeiten umstellen\n2. Kritische Präsenz-Rollen identifizieren, Schichtmodell\n3. Backup-Personal aus Partnerunternehmen klären\n4. Hygienemaßnahmen gemäß Behördenvorgabe umsetzen\n5. Psychosoziale Unterstützung aktivieren",
                'communication' => 'Tägliches Stand-up mit Key-Personen. Transparente Statusseite für Mitarbeiter.',
                'internal_communication' => 'Intranet-Ticker, Team-Huddles (Remote), wöchentliches All-Hands-Webinar.',
                'external_communication' => 'Kundenmitteilung zu Service-Einschränkungen; Behördenmeldungen gemäß IfSG.',
                'backup' => 'Dokumentiertes Know-How (Wiki/Runbooks) für alle kritischen Rollen; Cross-Training.',
                'restore' => 'Schrittweise Rückkehr zum Normalbetrieb nach Behörden-Entwarnung; Evaluation nach 90 Tagen.',
            ],
            [
                'name' => 'Datenschutzverletzung / Data Breach (Template)',
                'description' => 'DSGVO Art. 33/34 Datenschutzverletzung mit personenbezogenen Daten. 72-Stunden-Meldefrist an Aufsichtsbehörde.',
                'plan_owner' => 'DSB / DPO',
                'bc_team' => 'DSB, CISO, Rechtsabteilung, Kommunikation, IT-Security',
                'activation_criteria' => "- Bekannte oder vermutete Offenlegung personenbezogener Daten\n- Ransomware-Angriff auf Systeme mit PII\n- Verlust unverschlüsselter Datenträger\n- Fehlkonfiguration mit öffentlichem Zugriff",
                'roles' => "- DSB: Gesamtkoordination, DSGVO-Bewertung, Behördenmeldung\n- CISO: Technische Eindämmung, Forensik\n- Recht: Haftungsbewertung, Vertragsrisiken\n- Kommunikation: Betroffenenbenachrichtigung bei hohem Risiko",
                'recovery' => "1. Incident erfassen (DataBreach-Entity), detectedAt exakt setzen\n2. Schaden eindämmen (Zugänge sperren, Leak schließen)\n3. Forensik: Umfang, betroffene Datenkategorien, Anzahl Betroffene\n4. Meldepflicht-Entscheidung (Art. 33 Abs. 1 DSGVO, <72h)\n5. Ggf. Benachrichtigung Betroffener (Art. 34)\n6. Dokumentation für Rechenschaftspflicht (Art. 5 Abs. 2)",
                'communication' => 'Eskalation an Geschäftsleitung sofort. Externe Kommunikation ausschließlich koordiniert über DSB + Rechtsabteilung.',
                'internal_communication' => 'Vertrauliche Lagebesprechung im Kernteam; Info an betroffene Bereichsleiter auf Need-to-know-Basis.',
                'external_communication' => 'Aufsichtsbehörde: innerhalb 72h (Art. 33). Betroffene: unverzüglich bei hohem Risiko (Art. 34).',
                'backup' => 'Backups mit Nachweis der Integrität für forensische Analyse.',
                'restore' => 'Restore nur nach Freigabe durch Forensik; saubere Wiederherstellung auf unkompromittierter Infrastruktur.',
            ],
            [
                'name' => 'Gebäude-/Standort-Ausfall (Template)',
                'description' => 'Vollständiger oder teilweiser Ausfall eines Standorts (Feuer, Wasserschaden, Stromausfall, behördliche Sperrung).',
                'plan_owner' => 'Facility Manager / COO',
                'bc_team' => 'Facility-Management, HR, IT, Arbeitsschutz, Kommunikation',
                'activation_criteria' => "- Zutrittsverbot zum Gebäude > 4 Stunden\n- Schadensereignis mit Auswirkung auf Arbeitsfähigkeit\n- Ausfall Strom/Wasser/Netz > 2 Stunden",
                'roles' => "- Facility-Manager: Schadensbewertung, Kontakt zu Dienstleistern/Versicherung\n- HR: Alternative Arbeitsplätze, Home-Office-Anordnung\n- IT: Remote-Zugang, Standort-Failover\n- Arbeitsschutz: Evakuierung, Personenschäden vermeiden",
                'recovery' => "1. Evakuierung nach Brandschutzordnung durchführen\n2. Personenschaden ausschließen, ggf. Erste Hilfe\n3. Alternativstandort aktivieren (Home-Office, Ausweichgebäude)\n4. IT-Verfügbarkeit sichern (VPN, Cloud)\n5. Behördliche Freigabe für Wiederzutritt einholen\n6. Schadenserfassung für Versicherung",
                'communication' => 'Notfall-Kommunikationskette (siehe separate Liste). Behörden nach Schwere einbinden.',
                'internal_communication' => 'SMS-Alarmierungssystem für alle Mitarbeiter; Intranet-Status.',
                'external_communication' => 'Kunden/Lieferanten über Ausweichadresse informieren; ggf. Pressestatement.',
                'backup' => 'Dokumentation Alternativstandort, vertragliche Vereinbarungen über Ausweichflächen.',
                'restore' => 'Schrittweise Wiederinbetriebnahme nach Freigabe; Technik-Check vor Regelbetrieb.',
            ],
            [
                'name' => 'Lieferkette / Kritischer Lieferant-Ausfall (Template)',
                'description' => 'Ausfall eines kritischen Lieferanten (ICT-Dienstleister, Rechenzentrum, Cloud-Provider). DORA Art. 28 Exit-Strategie.',
                'plan_owner' => 'Procurement / CISO',
                'bc_team' => 'Einkauf, CISO, Rechtsabteilung, IT-Operations, Business-Owner',
                'activation_criteria' => "- Service-Ausfall eines als kritisch klassifizierten Lieferanten > 4 Stunden\n- Insolvenz-Meldung eines Lieferanten\n- Schwerwiegender Compliance-Verstoß (z.B. Datenschutz)\n- Kündigung durch Lieferant ohne ausreichende Frist",
                'roles' => "- Einkauf: Vertragsstatus, Eskalation beim Lieferanten\n- CISO: Bewertung Sicherheitsauswirkung\n- Recht: Vertragsklauseln, SLA-Durchsetzung\n- IT-Operations: Technischer Workaround, Failover",
                'recovery' => "1. Incident-Ticket, Kritikalität bewerten (DORA-Kategorie prüfen)\n2. Lieferant kontaktieren (SLA-Eskalationspfad)\n3. Aktivierung Ausweich-Lieferant (pre-qualified alternative)\n4. Bei längerfristigem Ausfall: Exit-Strategie aktivieren (DORA Art. 28.8)\n5. Datenherausgabe-Prozess starten (wenn Daten beim Lieferanten)\n6. Langfristige Lieferantenstrategie neu bewerten",
                'communication' => 'Einkauf steuert Lieferantenkommunikation; CISO informiert Vorstand/Aufsicht.',
                'internal_communication' => 'Betroffene Fachbereiche und Key-User informieren; Runbook für Workaround verteilen.',
                'external_communication' => 'Aufsichtsbehörde bei DORA-kritischem ICT-Dienst; ggf. Kunden über Service-Einschränkungen.',
                'backup' => 'Dokumentation der Daten-/Konfigurations-Exportprozesse jedes kritischen Lieferanten.',
                'restore' => 'Migration zu Ausweich-Lieferant gemäß dokumentierter Exit-Strategie; Tests vor Produktiv-Cutover.',
            ],
        ];
    }
}
