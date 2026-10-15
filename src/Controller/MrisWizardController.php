<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * MRIS-Wizards — drei statische GET-only-Anleitungen zur Loesung der
 * Top-3-Junior-Blocker (siehe docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md):
 *
 *   1. „Reine Reibung" — 5-Schritt-Entscheidungsroutine.
 *   2. Reifegrad-Evidence — Audit-taugliche Belegliste pro MHC und
 *      Reifegrad-Stufe (Initial / Defined / Managed) fuer alle 13 MHCs.
 *   3. AI-Risiko-Klasse — Entscheidungsmatrix mit 12 typischen Tools nach
 *      EU AI Act (Art. 6 + Anhang III).
 *
 * Inhalte stammen primaer aus fixtures/mris/help-texts.yaml unter dem
 * Block `blocker_solutions`. Falls dieser Block (noch) nicht gepflegt
 * ist, faellt der Controller auf eine eingebettete statische Variante
 * zurueck, die aus den Top-3-Blocker-Beschreibungen der Junior-Request-
 * Dokumentation abgeleitet wurde. Die Texte dieser Variante liegen in der
 * Translation-Domain `mris` unter `mris.wizard.*` (de + en).
 *
 * Quelle Fachkonzept: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: Creative Commons Attribution 4.0
 * International (CC BY 4.0).
 */
#[IsGranted('ROLE_USER')]
final class MrisWizardController extends AbstractController
{
    private const string TEXT_PREFIX = 'mris.wizard.';
    private const string TEXT_DOMAIN = 'mris';

    /** Maturity levels with two evidence items each, in display order. */
    private const array MATURITY_LEVELS = ['initial', 'defined', 'managed'];

    /** EU AI Act class per fallback tool, in display order (tool_01 … tool_12). */
    private const array FALLBACK_TOOL_CLASSES = [
        'minimal', 'minimal', 'minimal', 'minimal', 'high', 'high',
        'high', 'high', 'high', 'minimal', 'limited', 'high',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Liest fixtures/mris/help-texts.yaml und gibt den Block
     * `blocker_solutions` zurueck. Liefert ein leeres Array, falls der
     * Block fehlt (Aufrufer entscheidet ueber Fallback).
     *
     * @return array<string, mixed>
     */
    private function loadBlockerSolutions(): array
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $yamlPath = $projectDir . '/fixtures/mris/help-texts.yaml';

        if (!is_file($yamlPath) || !is_readable($yamlPath)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($yamlPath) ?? [];
        $block = $data['blocker_solutions'] ?? null;

        return is_array($block) ? $block : [];
    }

    /**
     * Translates one text of the embedded fallback content
     * (key relative to `mris.wizard.`, domain `mris`).
     */
    private function wizardText(string $key): string
    {
        return $this->translator->trans(self::TEXT_PREFIX . $key, [], self::TEXT_DOMAIN);
    }

    /**
     * @return list<string>
     */
    private function wizardNormRefs(string $section, int $count): array
    {
        $refs = [];
        for ($i = 1; $i <= $count; $i++) {
            $refs[] = $this->wizardText(sprintf('%s.norm_ref_%d', $section, $i));
        }

        return $refs;
    }

    #[Route('/mris/wizard/pure-friction', name: 'app_mris_wizard_pure_friction', methods: ['GET'])]
    public function pureFriction(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $routine = $solutions['pure_friction_routine'] ?? null;

        if (!is_array($routine) || empty($routine['steps']) || !is_array($routine['steps'])) {
            // Fallback: 5-Schritt-Routine aus docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md
            // Top-Blocker 1 + help-texts.yaml Beispiel "90-Tage-Passwortwechsel".
            $steps = [];
            for ($no = 1; $no <= 5; $no++) {
                $steps[] = [
                    'no' => $no,
                    'title' => $this->wizardText(sprintf('pure_friction.step_%d.title', $no)),
                    'detail' => $this->wizardText(sprintf('pure_friction.step_%d.detail', $no)),
                    'evidence' => $this->wizardText(sprintf('pure_friction.step_%d.evidence', $no)),
                ];
            }

            $routine = [
                'title' => $this->wizardText('pure_friction.title'),
                'intro' => $this->wizardText('pure_friction.intro'),
                'steps' => $steps,
                'example' => $this->wizardText('pure_friction.example'),
                'norm_refs' => $this->wizardNormRefs('pure_friction', 3),
            ];
        }

        return $this->render('mris/wizard/pure_friction.html.twig', [
            'routine' => $routine,
            'fallback_used' => empty($solutions),
        ]);
    }

    #[Route('/mris/wizard/maturity-evidence', name: 'app_mris_wizard_maturity_evidence', methods: ['GET'])]
    public function maturityEvidence(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $matrix = $solutions['maturity_evidence_per_mhc'] ?? null;

        if (!is_array($matrix) || empty($matrix['mhcs']) || !is_array($matrix['mhcs'])) {
            // Fallback: 13 MHCs aus help-texts.yaml Item 5 + Reifegrad-Stufen
            // aus Item 6. Evidence-Beispiele audit-tauglich nach CMMI-Logik.
            $matrix = [
                'title' => $this->wizardText('maturity.title'),
                'intro' => $this->wizardText('maturity.intro'),
                'mhcs' => $this->buildDefaultMhcEvidence(),
            ];
        }

        return $this->render('mris/wizard/maturity_evidence.html.twig', [
            'matrix' => $matrix,
            'fallback_used' => empty($solutions),
        ]);
    }

    #[Route('/mris/wizard/ai-risk-class', name: 'app_mris_wizard_ai_risk_class', methods: ['GET'])]
    public function aiRiskClass(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $matrix = $solutions['ai_risk_decision_matrix'] ?? null;

        if (!is_array($matrix) || empty($matrix['tools']) || !is_array($matrix['tools'])) {
            // Fallback: 12 typische Tools + 4-Step-Decision-Flow nach EU AI Act
            // (Art. 6 + Anhang III). Quellen: AI-Act-Erwaegungsgruende + ENISA-Guidance.
            $flow = [];
            for ($no = 1; $no <= 4; $no++) {
                $flow[] = [
                    'no' => $no,
                    'title' => $this->wizardText(sprintf('ai_risk.flow_%d.title', $no)),
                    'detail' => $this->wizardText(sprintf('ai_risk.flow_%d.detail', $no)),
                ];
            }

            $tools = [];
            foreach (self::FALLBACK_TOOL_CLASSES as $index => $class) {
                $prefix = sprintf('ai_risk.tool_%02d.', $index + 1);
                $tools[] = [
                    'tool' => $this->wizardText($prefix . 'tool'),
                    'class' => $class,
                    'class_label' => $this->wizardText(sprintf('ai_risk.class.%s', $class)),
                    'ai_act_ref' => $this->wizardText($prefix . 'ai_act_ref'),
                    'rationale' => $this->wizardText($prefix . 'rationale'),
                ];
            }

            $matrix = [
                'title' => $this->wizardText('ai_risk.title'),
                'intro' => $this->wizardText('ai_risk.intro'),
                'decision_flow' => $flow,
                'tools' => $tools,
                'norm_refs' => $this->wizardNormRefs('ai_risk', 4),
            ];
        }

        return $this->render('mris/wizard/ai_risk_class.html.twig', [
            'matrix' => $matrix,
            'fallback_used' => empty($solutions),
        ]);
    }

    /**
     * Default-Evidence-Liste fuer alle 13 MHCs, je 3 Reifegrade mit je zwei
     * Belegen. Quelle: help-texts.yaml MHC-Codes-Item 5 + Reifegrad-Item 6.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultMhcEvidence(): array
    {
        $mhcs = [];
        for ($i = 1; $i <= 13; $i++) {
            $prefix = sprintf('maturity.mhc_%02d.', $i);
            $levels = [];
            foreach (self::MATURITY_LEVELS as $level) {
                $levels[$level] = [
                    $this->wizardText($prefix . $level . '_1'),
                    $this->wizardText($prefix . $level . '_2'),
                ];
            }

            $mhcs[] = [
                'code' => sprintf('MHC-%02d', $i),
                'name' => $this->wizardText($prefix . 'name'),
                'levels' => $levels,
            ];
        }

        return $mhcs;
    }
}
