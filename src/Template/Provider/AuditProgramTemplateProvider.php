<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\InternalAudit;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;
use DateTimeImmutable;

/**
 * Audit-Program starter templates (3 entries, EN + DE = 6 templates).
 *
 * C5-05 (Cluster E · Audit-Finding-Polish, S14). ISO 19011 Cl. 5.4 expects
 * audit programs to be planned with documented scope, criteria, method and
 * resource allocation. Junior implementers without ISO 19011 background
 * routinely produce blank-field internal audits that lack auditable scope
 * statements — which becomes a Stage-1 finding the moment an external
 * auditor reads them.
 *
 * The three shipped templates cover the three German-SMB-typical audit
 * programs:
 *  - `iso27001_standard_audit`   — annual ISMS audit per Cl. 9.2.2
 *  - `dsgvo_audit`               — annual GDPR audit per DSGVO Art. 32
 *  - `bcm_audit`                 — annual BCM audit per ISO 22301 Cl. 9.2
 *
 * Each template prefills scope, scopeType, objectives (acts as the
 * audit-criteria list since `InternalAudit` has no separate criteria field
 * yet), plannedDate (today +30 days as a safe default the user can adjust),
 * and the standard audit-number placeholder. Lead auditor and team are
 * intentionally left blank — those are tenant-specific and the form
 * validator enforces at least one slot.
 */
final class AuditProgramTemplateProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        $programs = [
            [
                'key' => 'iso27001_standard_audit',
                'name_de' => 'ISO 27001 — Standard-Internes-Audit',
                'name_en' => 'ISO 27001 — Standard Internal Audit',
                'description_de' => 'Jahres-Audit gemäss ISO 27001 Cl. 9.2.2 (kompetent, unparteiisch, geplante Abstaende). Deckt Cl. 4-10 + Annex-A Kontrollen ab.',
                'description_en' => 'Annual audit per ISO 27001 Cl. 9.2.2 (competent, impartial, planned intervals). Covers Cl. 4-10 + Annex-A controls.',
                'title_de' => 'ISO 27001:2022 Internes Audit',
                'title_en' => 'ISO 27001:2022 Internal Audit',
                'scope_de' => 'Vollständiges ISMS gemäss Anwendungsbereich (Statement of Applicability, SoA). Alle Annex-A-Kontrollen, die als anwendbar deklariert sind, sowie die Klauseln 4 bis 10 der Norm.',
                'scope_en' => 'Full ISMS scope per Statement of Applicability (SoA). All Annex-A controls declared applicable plus clauses 4 through 10 of the standard.',
                'objectives_de' => "Audit-Kriterien:\n• ISO/IEC 27001:2022 Cl. 4-10\n• Statement of Applicability (SoA)\n• Tenant-spezifische ISMS-Richtlinien und Verfahren\n• Risiko-Behandlungsplan\n• Vorjahres-Findings (Follow-up)\n\nMethoden: Interviews, Dokumentenpruefung, Stichproben, Walkthroughs.",
                'objectives_en' => "Audit criteria:\n• ISO/IEC 27001:2022 Cl. 4-10\n• Statement of Applicability (SoA)\n• Tenant-specific ISMS policies and procedures\n• Risk treatment plan\n• Previous-year findings (follow-up)\n\nMethods: interviews, document review, sampling, walkthroughs.",
                'scope_type' => 'full_isms',
            ],
            [
                'key' => 'dsgvo_audit',
                'name_de' => 'DSGVO — Datenschutz-Audit',
                'name_en' => 'GDPR — Data Protection Audit',
                'description_de' => 'Jaehrliches Datenschutz-Audit gemaess DSGVO Art. 32 i.V.m. Art. 24/25/30. Pruefung der TOM-Wirksamkeit und VVT-Vollstaendigkeit.',
                'description_en' => 'Annual data protection audit per GDPR Art. 32 in connection with Art. 24/25/30. Verifies effectiveness of TOMs and completeness of records of processing.',
                'title_de' => 'DSGVO Datenschutz-Audit',
                'title_en' => 'GDPR Data Protection Audit',
                'scope_de' => 'Alle Verarbeitungstaetigkeiten gemaess VVT (DSGVO Art. 30), TOM-Wirksamkeit (Art. 32), DSFA-Pflichten (Art. 35), Betroffenenrechte (Art. 12-23), Datenpannen-Prozess (Art. 33/34), Auftragsverarbeitung (Art. 28).',
                'scope_en' => 'All processing activities per RoPA (GDPR Art. 30), TOM effectiveness (Art. 32), DPIA obligations (Art. 35), data subject rights (Art. 12-23), breach-notification process (Art. 33/34), processor agreements (Art. 28).',
                'objectives_de' => "Audit-Kriterien:\n• EU-DSGVO Art. 5-32 (Grundsaetze + TOMs)\n• BDSG (deutsche Spezialregelungen)\n• VVT-Vollstaendigkeit + Aktualitaet\n• Auftragsverarbeitungs-Vertraege\n• DSFA-Liste\n• Konzern-DSB-Bestellung (sofern relevant)\n\nMethoden: Stichprobe an VVT-Eintraegen, Pruefung der TOM-Implementierung, Interview mit DSB.",
                'objectives_en' => "Audit criteria:\n• EU GDPR Art. 5-32 (principles + TOMs)\n• BDSG (German national specifics)\n• RoPA completeness + currency\n• Processor agreements\n• DPIA inventory\n• DPO appointment evidence (where required)\n\nMethods: sampling of RoPA entries, TOM implementation review, DPO interview.",
                'scope_type' => 'compliance_framework',
            ],
            [
                'key' => 'bcm_audit',
                'name_de' => 'ISO 22301 — BCM-Audit',
                'name_en' => 'ISO 22301 — BCM Audit',
                'description_de' => 'Jaehrliches BCM-Audit gemaess ISO 22301 Cl. 9.2 (Interne Audits). Prueft BIA, BC-Plaene, RTO/RPO-Konsistenz und Uebungs-Wirksamkeit.',
                'description_en' => 'Annual BCM audit per ISO 22301 Cl. 9.2 (internal audits). Verifies BIA, BC plans, RTO/RPO consistency and exercise effectiveness.',
                'title_de' => 'ISO 22301:2019 BCM-Audit',
                'title_en' => 'ISO 22301:2019 BCM Audit',
                'scope_de' => 'BCM-System gemaess ISO 22301: Business-Impact-Analyse, BC-Strategien, BC-Plaene, Notfallteams, Uebungen, Krisen-Kommunikation, Lieferanten-Resilienz.',
                'scope_en' => 'BCM system per ISO 22301: business impact analysis, BC strategies, BC plans, crisis teams, exercises, crisis communication, supplier resilience.',
                'objectives_de' => "Audit-Kriterien:\n• ISO 22301:2019 Cl. 4-10\n• ISO 22313:2020 (Guidance)\n• BSI 200-4 (Notfallmanagement)\n• RTO/RPO/MTPD-Konsistenz (Plan vs. Uebungs-Ist)\n• Aktuelle BC-Plaene (innerhalb 12-Monats-Review-Zyklus)\n• Krisen-Team-Bestellungen + Erreichbarkeit\n\nMethoden: BIA-Stichprobe, BC-Plan-Walkthrough, Uebungs-Auswertung (letzte 12 Monate), Lieferanten-Resilienz-Review.",
                'objectives_en' => "Audit criteria:\n• ISO 22301:2019 Cl. 4-10\n• ISO 22313:2020 (guidance)\n• BSI 200-4 (emergency management)\n• RTO/RPO/MTPD consistency (plan vs. exercise actuals)\n• BC plans currency (within 12-month review cycle)\n• Crisis team appointments + reachability\n\nMethods: BIA sampling, BC-plan walkthrough, exercise review (last 12 months), supplier-resilience review.",
                'scope_type' => 'full_isms',
            ],
        ];

        // Planned date: today + 30 days. Junior implementers should adjust this
        // to match the audit-program calendar, but a non-null default lets the
        // form pass validation immediately and avoids the "blank required
        // field" trap when the user clicks "Apply template".
        $defaultPlannedDate = (new DateTimeImmutable('today'))->modify('+30 days');

        foreach (['de', 'en'] as $lang) {
            foreach ($programs as $program) {
                $de = $lang === 'de';
                yield new SystemTemplate(
                    key: 'audit.program.' . $program['key'] . '.' . $lang,
                    entityClass: InternalAudit::class,
                    module: null,
                    language: $lang,
                    name: $de ? $program['name_de'] : $program['name_en'],
                    description: $de ? $program['description_de'] : $program['description_en'],
                    prefill: [
                        'title' => $de ? $program['title_de'] : $program['title_en'],
                        'scope' => $de ? $program['scope_de'] : $program['scope_en'],
                        'objectives' => $de ? $program['objectives_de'] : $program['objectives_en'],
                        'scopeType' => $program['scope_type'],
                        'status' => 'planned',
                        'plannedDate' => $defaultPlannedDate,
                    ],
                );
            }
        }
    }
}
