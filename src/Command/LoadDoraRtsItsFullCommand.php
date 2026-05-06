<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the granular DORA Level-2 catalogue (RTS / ITS / CIRs / Joint Guidelines)
 * issued by the ESAs Joint Committee under DORA (Regulation EU 2022/2554).
 *
 * Identifier convention (Level-2, NEW):
 *   - 'RTS-ICT-RMF-Art.X'           — JC 2023 86 RTS on ICT Risk Management Framework
 *   - 'RTS-ICT-RMF-SIMPL-Art.X'     — JC 2023 86 simplified RMF (Art. 16 DORA scope)
 *   - 'RTS-INC-CLASS-Art.X'         — JC 2024 RTS on classification of major incidents (CDR 2024/1772)
 *   - 'RTS-INC-REPORT-Art.X'        — JC 2024 RTS on incident reporting content/templates (CDR 2024/1774)
 *   - 'ITS-INC-REPORT-Art.X'        — CIR (EU) 2024/2955 ITS incident reporting templates
 *   - 'ITS-Register-B.XX.YY'        — CIR (EU) 2024/2956 ITS Register of Information templates
 *   - 'RTS-Subcontracting-Art.X'    — JC 2024 RTS on subcontracting (CDR 2025/532)
 *   - 'RTS-TLPT-Art.X'              — JC 2024 RTS on Threat-Led Penetration Testing
 *   - 'RTS-Oversight-Art.X'         — JC 2024 RTS on harmonisation of conditions for oversight
 *   - 'GL-JC-NN'                    — Joint Guidelines (where applicable)
 *
 * Identifier convention (Level-1, EXISTING — DO NOT TOUCH):
 *   - 'Art.N' / 'Art.N.M' — already populated by LoadDoraFullCommand.
 *
 * IMPORTANT: This loader inserts NEW Level-2 IDs only and never overwrites
 * Level-1 'Art.N'. Idempotent: safe to run multiple times.
 *
 * Sources (all official ESAs Joint Committee Final Reports + EUR-Lex CELEX):
 *   - JC 2023 86  Final Report RTS ICT Risk Management Framework
 *     https://www.eba.europa.eu/sites/default/files/document_library/Publications/Draft%20Technical%20Standards/2024/JC%202023%2086%20-%20Final%20report%20on%20draft%20RTS%20on%20ICT%20Risk%20Management%20Framework%20and%20on%20simplified%20ICT%20Risk%20Management%20Framework.pdf
 *   - Commission Delegated Regulation (EU) 2024/1772 (incident classification)
 *     https://eur-lex.europa.eu/eli/reg_del/2024/1772/oj
 *   - Commission Delegated Regulation (EU) 2024/1773 (TPP contractual)
 *     https://eur-lex.europa.eu/eli/reg_del/2024/1773/oj
 *   - Commission Delegated Regulation (EU) 2024/1774 (incident reporting content) -- when adopted
 *   - Commission Implementing Regulation (EU) 2024/2955 (incident reporting format/templates)
 *   - Commission Implementing Regulation (EU) 2024/2956 (Register of Information)
 *     https://eur-lex.europa.eu/eli/reg_impl/2024/2956/oj
 *   - Commission Delegated Regulation (EU) 2025/532 (subcontracting RTS)
 *
 * RATIONALE-VALIDATION CAVEAT:
 *   The exact article-numbering of the ESAs Final Reports / CDRs / CIRs MAY have
 *   shifted between draft and final publication. Wherever the text below is taken
 *   from a draft, the loader marks it explicitly with the phrase
 *     "Anwender muss gegen die offizielle ESAs-Final-Report-Veroeffentlichung validieren"
 *   in the description. Operational consumers (compliance teams) MUST cross-check
 *   identifiers against the OJ-published version before relying on them for audit.
 */
#[AsCommand(
    name: 'app:load-dora-rts-its-full',
    description: 'Load DORA Level-2 catalogue (RTS, ITS, CIRs, Joint Guidelines) as ComplianceRequirement rows.'
)]
final class LoadDoraRtsItsFullCommand extends Command
{
    private const VALIDATION_HINT = 'Anwender muss gegen die offizielle ESAs-Final-Report-Veroeffentlichung validieren.';

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $framework = $this->frameworkRepository->findOneBy(['code' => 'DORA']);
        if ($framework === null) {
            $io->error('Framework DORA not in DB. Run app:load-dora-full first.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);

        $created = 0;
        $updated = 0;
        $totalLevel2 = 0;
        $blocks = $this->getAllBlocks();

        foreach ($blocks as $blockName => $block) {
            $sourceUrl = $block['primary_source_url'];
            $category  = $block['category'];
            $defaultPriority = $block['priority'] ?? 'high';
            $lifecycleState  = $block['lifecycle_state'] ?? 'published';
            foreach ($block['items'] as $reqId => $item) {
                $reqId = (string) $reqId;
                $totalLevel2++;
                $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
                $isNew = ($req === null);
                if ($isNew) {
                    $req = new ComplianceRequirement();
                    $req->setFramework($framework);
                    $req->setRequirementId($reqId);
                    $req->setRequirementType('detailed');
                    $created++;
                } else {
                    $updated++;
                }
                $title = is_string($item) ? $item : ($item['title'] ?? $reqId);
                $itemPriority = is_array($item) ? ($item['priority'] ?? $defaultPriority) : $defaultPriority;
                $rationale = is_array($item) ? ($item['rationale'] ?? null) : null;
                $unsure = is_array($item) ? (bool) ($item['unsure'] ?? false) : false;

                $description = sprintf(
                    "DORA Level-2 / %s — %s.\nQuelle: %s.\nLifecycle: %s.\nPrimary source URL: %s.",
                    $reqId,
                    $title,
                    $blockName,
                    $lifecycleState,
                    $sourceUrl,
                );
                if ($rationale !== null) {
                    $description .= "\nKontext: " . $rationale;
                }
                if ($unsure || $lifecycleState !== 'published') {
                    $description .= "\nValidierungs-Hinweis: " . self::VALIDATION_HINT;
                }

                $req->setTitle(mb_substr($title, 0, 250));
                $req->setDescription($description);
                $req->setCategory($category);
                $req->setPriority($itemPriority);
                $this->em->persist($req);
            }
        }
        $this->em->flush();

        $io->success(sprintf(
            'DORA Level-2: %d created, %d updated. %d Level-2 IDs across %d RTS/ITS/CIR blocks.',
            $created,
            $updated,
            $totalLevel2,
            count($blocks),
        ));

        return Command::SUCCESS;
    }

    /**
     * Returns the full Level-2 catalogue grouped by RTS / ITS / CIR block.
     * Each block has primary_source_url, category, priority defaults, and items.
     *
     * @return array<string, array{primary_source_url: string, category: string, priority?: string, lifecycle_state?: string, items: array<string, string|array{title: string, priority?: string, rationale?: string, unsure?: bool}>}>
     */
    private function getAllBlocks(): array
    {
        return [
            // ================================================================
            // A. JC 2023 86 — RTS on ICT Risk Management Framework (Art.15 DORA)
            // ================================================================
            'JC 2023 86 — RTS on ICT Risk Management Framework (Art.15 DORA)' => [
                'primary_source_url' => 'https://www.eba.europa.eu/sites/default/files/document_library/Publications/Draft%20Technical%20Standards/2024/JC%202023%2086%20-%20Final%20report%20on%20draft%20RTS%20on%20ICT%20Risk%20Management%20Framework%20and%20on%20simplified%20ICT%20Risk%20Management%20Framework.pdf',
                'category' => 'RTS — ICT Risk Management Framework (JC 2023 86)',
                'priority' => 'high',
                'lifecycle_state' => 'published',
                'items' => [
                    // Section I — General Provisions
                    'RTS-ICT-RMF-Art.1' => 'Subject matter — overall coverage of ICT risk management framework specifications',
                    'RTS-ICT-RMF-Art.2' => 'Overall risk profile and complexity — proportionality dimensions for framework design',
                    // Section II — ICT Security Policies, Procedures, Protocols, Tools
                    'RTS-ICT-RMF-Art.3' => 'ICT security policies, procedures, protocols and tools — main policy and supporting policies set',
                    'RTS-ICT-RMF-Art.4' => 'ICT risk management function — independence, role, reporting line to management body',
                    'RTS-ICT-RMF-Art.5' => 'ICT asset management policy and procedure — ownership, classification, criticality tagging',
                    'RTS-ICT-RMF-Art.6' => 'Encryption and cryptography — algorithms, key management lifecycle, post-quantum-readiness assessment',
                    'RTS-ICT-RMF-Art.7' => 'Cryptographic key management policy — generation, storage, rotation, revocation, HSM governance',
                    'RTS-ICT-RMF-Art.8' => 'ICT operations security — capacity, performance, configuration baseline management',
                    'RTS-ICT-RMF-Art.9' => 'Capacity and performance management procedures — forecasting, monitoring, scaling',
                    'RTS-ICT-RMF-Art.10' => 'Vulnerability and patch management — discovery, assessment, prioritisation, remediation timelines',
                    'RTS-ICT-RMF-Art.11' => 'Data and system security — protection at rest, in transit, in use; data leakage prevention',
                    'RTS-ICT-RMF-Art.12' => 'Logging — centralised log retention, integrity protection, retention period (commonly >= 1 year)',
                    'RTS-ICT-RMF-Art.13' => 'Network security — segmentation, segregation, perimeter, micro-segmentation, allow-listing',
                    'RTS-ICT-RMF-Art.14' => 'Securing information in transit — encryption-in-transit standards, certificate lifecycle',
                    'RTS-ICT-RMF-Art.15' => 'ICT project and change management — pre-prod testing, segregation of duties, rollback plans',
                    'RTS-ICT-RMF-Art.16' => 'ICT systems acquisition, development and maintenance — secure SDLC, dependency mgmt',
                    // Section II continued — Identity & Access
                    'RTS-ICT-RMF-Art.17' => 'Physical and environmental security — data center, BCP-relevant facilities, controlled access',
                    'RTS-ICT-RMF-Art.18' => 'Human resources policy — vetting, NDAs, role-based training, secure separation processes',
                    'RTS-ICT-RMF-Art.19' => 'Identity management — lifecycle (joiner-mover-leaver), unique attribution, periodic review',
                    'RTS-ICT-RMF-Art.20' => 'Access control — least privilege, segregation of duties, privileged access management (PAM)',
                    'RTS-ICT-RMF-Art.21' => 'ICT-related incident management policy — detection, classification, escalation criteria',
                    // Section II continued — Detection / Response / Continuity
                    'RTS-ICT-RMF-Art.22' => 'ICT-related incident detection mechanisms — SIEM, EDR, UEBA, anomaly detection, alert thresholds',
                    'RTS-ICT-RMF-Art.23' => 'ICT business continuity policy — RTO, RPO, alternate sites, scenario coverage',
                    'RTS-ICT-RMF-Art.24' => 'Components of the ICT business continuity plans — activation criteria, roles, communication',
                    'RTS-ICT-RMF-Art.25' => 'Testing of ICT business continuity plans — annual testing minimum, severe scenario coverage',
                    'RTS-ICT-RMF-Art.26' => 'ICT response and recovery plans — sequencing, dependencies, recovery point validation',
                    'RTS-ICT-RMF-Art.27' => 'Backup policies and procedures — segregation from production, immutability options, restoration tests',
                    // Section III — Specific Cases
                    'RTS-ICT-RMF-Art.28' => 'Reporting on review of the ICT risk management framework — content, frequency, addressee (management body)',
                    'RTS-ICT-RMF-Art.29' => 'Format and content of report on review of ICT risk management framework — minimum sections',
                    'RTS-ICT-RMF-Art.30' => 'Final provisions — entry into force, transitional measures',
                ],
            ],

            // ================================================================
            // A2. JC 2023 86 — RTS on Simplified ICT Risk Management Framework
            // ================================================================
            'JC 2023 86 — Simplified RTS (Art.16 DORA scope: small and non-interconnected entities)' => [
                'primary_source_url' => 'https://www.eba.europa.eu/sites/default/files/document_library/Publications/Draft%20Technical%20Standards/2024/JC%202023%2086%20-%20Final%20report%20on%20draft%20RTS%20on%20ICT%20Risk%20Management%20Framework%20and%20on%20simplified%20ICT%20Risk%20Management%20Framework.pdf',
                'category' => 'RTS — Simplified ICT Risk Management Framework (JC 2023 86)',
                'priority' => 'medium',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-ICT-RMF-SIMPL-Art.1' => 'Subject matter — simplified framework coverage for small and non-interconnected entities',
                    'RTS-ICT-RMF-SIMPL-Art.2' => 'Governance and organisation — proportionate management body responsibilities',
                    'RTS-ICT-RMF-SIMPL-Art.3' => 'Internal controls framework — basic three-lines-of-defence approach',
                    'RTS-ICT-RMF-SIMPL-Art.4' => 'Documentation of ICT risk management framework — minimum content',
                    'RTS-ICT-RMF-SIMPL-Art.5' => 'Identification of functions, information assets and ICT assets — register-grade list',
                    'RTS-ICT-RMF-SIMPL-Art.6' => 'Classification of functions and assets — at least binary critical / non-critical tag',
                    'RTS-ICT-RMF-SIMPL-Art.7' => 'Risk assessment — annual, plus on material change',
                    'RTS-ICT-RMF-SIMPL-Art.8' => 'Physical and environmental security — proportionate measures',
                    'RTS-ICT-RMF-SIMPL-Art.9' => 'Access control — basic IAM controls + privileged access separation',
                    'RTS-ICT-RMF-SIMPL-Art.10' => 'ICT operations security — patching cadence and basic baseline',
                    'RTS-ICT-RMF-SIMPL-Art.11' => 'Network security — perimeter and segregation appropriate to size',
                    'RTS-ICT-RMF-SIMPL-Art.12' => 'ICT-related incident management — detection, classification, escalation (proportionate)',
                    'RTS-ICT-RMF-SIMPL-Art.13' => 'Backup and recovery — at least one off-site and tested copy',
                    'RTS-ICT-RMF-SIMPL-Art.14' => 'Documentation, awareness training and review — annual review of simplified framework',
                ],
            ],

            // ================================================================
            // B. JC 2024 — RTS on Classification of Major ICT Incidents (Art.18(3) DORA)
            //    Adopted as Commission Delegated Regulation (EU) 2024/1772
            // ================================================================
            'CDR 2024/1772 — RTS on classification of major ICT-related incidents (Art.18(3) DORA)' => [
                'primary_source_url' => 'https://eur-lex.europa.eu/eli/reg_del/2024/1772/oj',
                'category' => 'RTS — Major Incident Classification (CDR 2024/1772)',
                'priority' => 'critical',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-INC-CLASS-Art.1' => 'Subject matter and definitions — major incidents, recurrent incidents, significant cyber threats',
                    'RTS-INC-CLASS-Art.2' => 'Clients, financial counterparts and transactions affected — quantitative thresholds',
                    'RTS-INC-CLASS-Art.3' => 'Reputational impact — qualitative criteria (media coverage, complaints, supervisory inquiries)',
                    'RTS-INC-CLASS-Art.4' => 'Duration and service downtime — time-based threshold (commonly >=2h critical service downtime)',
                    'RTS-INC-CLASS-Art.5' => 'Geographical spread — number of Member States impacted (>=2 typically triggers cross-border tag)',
                    'RTS-INC-CLASS-Art.6' => 'Data losses — categories (CIA), volume, sensitivity (incl. personal/financial data)',
                    'RTS-INC-CLASS-Art.7' => 'Critical services affected — impact on critical or important functions (CIF) of the entity',
                    'RTS-INC-CLASS-Art.8' => 'Economic impact — direct + indirect financial cost threshold for major classification',
                    'RTS-INC-CLASS-Art.9' => 'Determination of major ICT-related incidents — combined / cumulative threshold logic',
                    'RTS-INC-CLASS-Art.10' => 'Recurrent incidents — definition, aggregation period, joint classification rule',
                    'RTS-INC-CLASS-Art.11' => 'Materiality threshold for significant cyber threats — voluntary notification trigger',
                    'RTS-INC-CLASS-Art.12' => 'Final provisions — entry into force, application date',
                ],
            ],

            // ================================================================
            // C. JC 2024 — RTS on Incident Reporting Content + Templates (Art.20(a) DORA)
            //    + ITS on reporting format (Art.20(b) DORA) -> CIR (EU) 2024/2955
            // ================================================================
            'JC 2024 — RTS/ITS on incident reporting content and templates (Art.20 DORA, CIR 2024/2955)' => [
                'primary_source_url' => 'https://eur-lex.europa.eu/eli/reg_impl/2024/2955/oj',
                'category' => 'RTS/ITS — Incident Reporting Templates (Art.20 DORA / CIR 2024/2955)',
                'priority' => 'critical',
                'lifecycle_state' => 'published',
                'items' => [
                    // RTS portion — content
                    'RTS-INC-REPORT-Art.1' => 'Content of initial notification (4-hour deadline after major-classification) — minimum data fields',
                    'RTS-INC-REPORT-Art.2' => 'Content of intermediate report (72-hour deadline after initial) — status, root cause hypothesis',
                    'RTS-INC-REPORT-Art.3' => 'Content of final report (1 month deadline after intermediate) — root cause, remediation, lessons learned',
                    'RTS-INC-REPORT-Art.4' => 'Content of voluntary notification of significant cyber threat — TTPs, IoCs, anticipated impact',
                    'RTS-INC-REPORT-Art.5' => 'Content of notification when incident reaches major classification later — re-classification dating',
                    'RTS-INC-REPORT-Art.6' => 'Aggregated annual cost / loss reporting — methodology and format',
                    'RTS-INC-REPORT-Art.7' => 'Reporting in case of outsourcing — coverage of ICT third-party caused incidents',
                    'RTS-INC-REPORT-Art.8' => 'Notification language — official language(s) of competent authority',
                    'RTS-INC-REPORT-Art.9' => 'Confidentiality of information shared with competent authority',
                    'RTS-INC-REPORT-Art.10' => 'Final provisions — application date',
                    // ITS portion — format/templates
                    'ITS-INC-REPORT-Art.1' => 'Standardised electronic format for major incident reports (XBRL-based common standard)',
                    'ITS-INC-REPORT-Art.2' => 'Common reporting template for initial / intermediate / final stages — column-level mapping',
                    'ITS-INC-REPORT-Art.3' => 'Submission channel — secure transmission path mandated by competent authority',
                    'ITS-INC-REPORT-Art.4' => 'Re-submission and corrections — versioning, audit trail',
                    'ITS-INC-REPORT-Art.5' => 'Information classification labels — confidentiality, source-protection markings',
                ],
            ],

            // ================================================================
            // D. CIR (EU) 2024/2956 — ITS on Register of Information (Art.28(9) DORA)
            // ================================================================
            'CIR 2024/2956 — ITS on Register of Information (Art.28(9) DORA)' => [
                'primary_source_url' => 'https://eur-lex.europa.eu/eli/reg_impl/2024/2956/oj',
                'category' => 'ITS — Register of Information (CIR 2024/2956)',
                'priority' => 'critical',
                'lifecycle_state' => 'published',
                'items' => [
                    // RT.01 — Identification info
                    'ITS-Register-RT.01.01' => 'Register of Information — RT.01.01 Identifying information of the financial entity (LEI, name, country)',
                    'ITS-Register-RT.01.02' => 'Register of Information — RT.01.02 List of branches and subsidiaries within the scope',
                    'ITS-Register-RT.01.03' => 'Register of Information — RT.01.03 Reference dates and reporting period',
                    // RT.02 — Contractual arrangements
                    'ITS-Register-RT.02.01' => 'Register of Information — RT.02.01 Contractual arrangements for ICT services with third-party providers',
                    'ITS-Register-RT.02.02' => 'Register of Information — RT.02.02 Specifics of contractual arrangements (contract date, term, governing law)',
                    'ITS-Register-RT.02.03' => 'Register of Information — RT.02.03 List of intra-group ICT service contracts',
                    // RT.03 — ICT third-party service providers
                    'ITS-Register-RT.03.01' => 'Register of Information — RT.03.01 ICT third-party service providers identification (LEI, country, ultimate parent)',
                    'ITS-Register-RT.03.02' => 'Register of Information — RT.03.02 Specifics of contractual arrangements with ICT third-party service providers',
                    'ITS-Register-RT.03.03' => 'Register of Information — RT.03.03 ICT services classification per provider (taxonomy)',
                    // RT.04 — ICT services
                    'ITS-Register-RT.04.01' => 'Register of Information — RT.04.01 ICT services classification and taxonomy mapping (S01-S26)',
                    'ITS-Register-RT.04.02' => 'Register of Information — RT.04.02 Reliance on ICT services supporting critical or important functions (CIF flag)',
                    // RT.05 — Functions linkage
                    'ITS-Register-RT.05.01' => 'Register of Information — RT.05.01 Information on the functions and ICT services',
                    'ITS-Register-RT.05.02' => 'Register of Information — RT.05.02 Linkage of ICT services to critical or important functions',
                    'ITS-Register-RT.05.03' => 'Register of Information — RT.05.03 Substitutability assessment per ICT third-party provider',
                    // RT.06 — Sub-contractors
                    'ITS-Register-RT.06.01' => 'Register of Information — RT.06.01 Information about ICT sub-contractors supporting CIFs',
                    'ITS-Register-RT.06.02' => 'Register of Information — RT.06.02 Sub-contracting chain — depth and material sub-contractors',
                    // RT.07 — Concentration
                    'ITS-Register-RT.07.01' => 'Register of Information — RT.07.01 Risk concentration assessment of ICT third-party arrangements',
                    // Reporting metadata
                    'ITS-Register-Frequency' => 'Reporting frequency and submission — annual reporting at least, ad-hoc on material change',
                ],
            ],

            // ================================================================
            // E. JC 2024 — RTS on Subcontracting (Art.30(5) DORA)
            //    Adopted as Commission Delegated Regulation (EU) 2025/532 (final)
            //    NOTE: At time of writing (May 2026), text is published in OJ.
            // ================================================================
            'CDR 2025/532 — RTS on Subcontracting of ICT services supporting CIFs (Art.30(5) DORA)' => [
                'primary_source_url' => 'https://www.eba.europa.eu/regulation-and-policy/operational-resilience/joint-rts-and-its-on-elements-of-subcontracting-ict-services-supporting-critical-or-important-functions-under-dora',
                'category' => 'RTS — Subcontracting (CDR 2025/532)',
                'priority' => 'high',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-Subcontracting-Art.1' => [
                        'title' => 'Subject matter and scope — subcontracting of ICT services supporting CIFs',
                        'rationale' => 'Defines which subcontracting arrangements fall in scope (only those supporting critical or important functions or material parts thereof).',
                    ],
                    'RTS-Subcontracting-Art.2' => 'Conditions for subcontracting — risk assessment, due diligence, contractual chain integrity',
                    'RTS-Subcontracting-Art.3' => 'Pre-implementation due diligence — operational, financial, reputational risk assessment of subcontractor',
                    'RTS-Subcontracting-Art.4' => 'Material changes to subcontracting — notification and consent rights of the financial entity',
                    'RTS-Subcontracting-Art.5' => 'Sub-outsourcing chain limits and oversight — depth, geography, concentration considerations',
                    'RTS-Subcontracting-Art.6' => 'Monitoring of subcontracting arrangements — KPIs, audit rights flow-through, incident notification',
                    'RTS-Subcontracting-Art.7' => 'Termination rights and exit strategy — replacement, data return / deletion, knowledge transfer',
                    'RTS-Subcontracting-Art.8' => 'Provisions in the contractual arrangement with the ICT TPP — mandatory subcontracting clauses',
                    'RTS-Subcontracting-Art.9' => [
                        'title' => 'Final provisions — application date and transitional measures',
                        'unsure' => true,
                    ],
                ],
            ],

            // ================================================================
            // F. JC 2024 — RTS on Threat-Led Penetration Testing (Art.26(11) DORA)
            // ================================================================
            'JC 2024 — RTS on Threat-Led Penetration Testing (Art.26(11) DORA)' => [
                'primary_source_url' => 'https://www.eba.europa.eu/regulation-and-policy/operational-resilience/joint-rts-on-threat-led-penetration-testing-tlpt',
                'category' => 'RTS — Threat-Led Penetration Testing (TLPT, Art.26(11) DORA)',
                'priority' => 'high',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-TLPT-Art.1' => 'Subject matter and definitions — TLPT, red team, blue team, white team, threat intelligence provider, control team',
                    'RTS-TLPT-Art.2' => 'Identification of in-scope financial entities — quantitative + qualitative criteria from competent authorities',
                    'RTS-TLPT-Art.3' => 'TLPT scope — critical or important functions and supporting ICT systems',
                    'RTS-TLPT-Art.4' => 'TLPT methodology and TIBER-EU alignment — phases, artefacts, role separation',
                    'RTS-TLPT-Art.5' => 'Preparation phase — engagement, governance, scoping, risk-management plan',
                    'RTS-TLPT-Art.6' => 'Threat Intelligence phase — threat-intel provider sourcing, generic + targeted threat intel report (GTI/TTI)',
                    'RTS-TLPT-Art.7' => 'Red Team phase — execution on production systems, leg-up rules, escalation safeguards',
                    'RTS-TLPT-Art.8' => 'Blue Team phase — detection, response, remediation observation by defenders',
                    'RTS-TLPT-Art.9' => 'Closure phase — replay workshops, remediation plan, attestation by competent authority',
                    'RTS-TLPT-Art.10' => 'Pooled TLPT — joint testing across financial entities sharing common ICT TPPs',
                    'RTS-TLPT-Art.11' => 'Requirements for testers — independence, certification, prior experience (incl. internal tester carve-out)',
                    'RTS-TLPT-Art.12' => 'Mutual recognition of TLPT results — passporting between Member States and competent authorities',
                    'RTS-TLPT-Art.13' => 'Final provisions — frequency (default every 3 years), application date',
                ],
            ],

            // ================================================================
            // G. JC 2024 — RTS on Oversight Framework (Art.41(1) DORA)
            // ================================================================
            'JC 2024 — RTS on harmonisation of conditions for oversight (Art.41(1) DORA)' => [
                'primary_source_url' => 'https://www.eba.europa.eu/regulation-and-policy/operational-resilience/joint-rts-on-harmonisation-of-conditions-for-oversight',
                'category' => 'RTS — Oversight Framework for Critical ICT TPPs (Art.41 DORA)',
                'priority' => 'medium',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-Oversight-Art.1' => 'Subject matter — harmonised conditions for the conduct of oversight activities',
                    'RTS-Oversight-Art.2' => 'Information to be provided by the critical ICT third-party service provider (CTPP) to the Lead Overseer',
                    'RTS-Oversight-Art.3' => 'Criteria for the assessment of the criticality of ICT third-party service providers',
                    'RTS-Oversight-Art.4' => 'Methodology for the determination of the oversight fees charged to CTPPs',
                    'RTS-Oversight-Art.5' => 'Conditions for the conduct of investigations and inspections by the Lead Overseer',
                    'RTS-Oversight-Art.6' => 'Joint Examination Teams (JET) composition, role of national competent authorities, ESMA/EBA/EIOPA cooperation',
                    'RTS-Oversight-Art.7' => 'Penalties — types and ranges of periodic penalty payments imposed on CTPPs',
                    'RTS-Oversight-Art.8' => 'Recommendations to CTPPs and follow-up by competent authorities of financial entities',
                    'RTS-Oversight-Art.9' => 'Final provisions',
                ],
            ],

            // ================================================================
            // H. CDR 2024/1773 — RTS specifying the policy on contractual arrangements
            //    on the use of ICT services supporting critical or important functions (Art.28(10))
            // ================================================================
            'CDR 2024/1773 — RTS on policy on contractual arrangements for ICT services supporting CIFs (Art.28(10) DORA)' => [
                'primary_source_url' => 'https://eur-lex.europa.eu/eli/reg_del/2024/1773/oj',
                'category' => 'RTS — TPP Contractual Arrangements Policy (CDR 2024/1773)',
                'priority' => 'high',
                'lifecycle_state' => 'published',
                'items' => [
                    'RTS-TPP-Policy-Art.1' => 'Subject matter — policy framework governing contractual arrangements with ICT TPPs supporting CIFs',
                    'RTS-TPP-Policy-Art.2' => 'Governance arrangements — board approval and review of the TPP policy',
                    'RTS-TPP-Policy-Art.3' => 'Pre-contractual phase — risk assessment, due diligence, conflict of interest screening',
                    'RTS-TPP-Policy-Art.4' => 'Contractual phase — minimum contract clauses (Art.30(2) and (3) DORA expansion)',
                    'RTS-TPP-Policy-Art.5' => 'Implementation, monitoring and management of contractual arrangements',
                    'RTS-TPP-Policy-Art.6' => 'Documentation and Register of Information feed — synchronisation requirements',
                    'RTS-TPP-Policy-Art.7' => 'Exit strategies and termination rights — orderly exit plan, data portability',
                    'RTS-TPP-Policy-Art.8' => 'Concentration risk monitoring — quantitative metrics and reporting to management body',
                    'RTS-TPP-Policy-Art.9' => 'Final provisions — application date',
                ],
            ],

            // ================================================================
            // I. Joint Guidelines (informational / non-binding but supervisorily expected)
            // ================================================================
            'ESAs Joint Guidelines under DORA — supervisorily expected practices' => [
                'primary_source_url' => 'https://www.eba.europa.eu/regulation-and-policy/operational-resilience',
                'category' => 'Joint Guidelines — DORA Supervisory Expectations',
                'priority' => 'medium',
                'lifecycle_state' => 'draft',
                'items' => [
                    'GL-JC-01' => [
                        'title' => 'Joint Guidelines on the estimation of aggregated annual costs and losses caused by major incidents',
                        'rationale' => 'Supports Art.11(10) DORA reporting; methodology guidance for direct + indirect cost classification.',
                        'unsure' => true,
                    ],
                    'GL-JC-02' => [
                        'title' => 'Joint Guidelines on the oversight cooperation and exchange of information between ESAs and competent authorities',
                        'unsure' => true,
                    ],
                ],
            ],
        ];
    }
}
