<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\CompliancePolicyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI to edit compliance-policy runtime values
 * (four-eyes expiry, review min-lengths, portfolio badge thresholds,
 * reuse-estimation heuristic, import limits, etc.).
 *
 * Values are stored in `system_settings` (category = compliance). YAML
 * parameters act as fallback defaults.
 */
#[Route('/admin/compliance/settings', name: 'admin_compliance_policy_')]
#[IsGranted('ROLE_ADMIN')]
final class CompliancePolicyController extends AbstractController
{
    /**
     * Map of form key → [label, type, min, max, step, group].
     *
     * @return array<string, array{label:string, type:string, min?:float, max?:float, step?:float, group:string}>
     */
    private function fieldDefs(): array
    {
        return [
            CompliancePolicyService::KEY_INHERITANCE_ENABLED           => ['label' => 'Mapping-Inheritance aktiv (WS-1 dark-launch)', 'type' => 'bool', 'group' => 'inheritance'],
            CompliancePolicyService::KEY_MIN_COMMENT_LENGTH            => ['label' => 'Review-Kommentar Mindestlänge',                'type' => 'int',  'min' => 0, 'max' => 500, 'group' => 'inheritance'],
            CompliancePolicyService::KEY_MIN_OVERRIDE_REASON_LENGTH    => ['label' => 'Override-Begründung Mindestlänge',              'type' => 'int',  'min' => 0, 'max' => 500, 'group' => 'inheritance'],
            CompliancePolicyService::KEY_SIGNIFICANT_CHANGE_THRESHOLD  => ['label' => 'Signifikanter-Change-Schwellwert (%)',          'type' => 'int',  'min' => 0, 'max' => 100, 'group' => 'inheritance'],
            CompliancePolicyService::KEY_FOUR_EYES_EXPIRY_DAYS         => ['label' => '4-Augen-Anfrage Ablauf (Tage)',                 'type' => 'int',  'min' => 1, 'max' => 60,  'group' => 'four_eyes'],
            CompliancePolicyService::KEY_PORTFOLIO_GREEN               => ['label' => 'Portfolio Badge grün ab (%)',                  'type' => 'int',  'min' => 0, 'max' => 100, 'group' => 'portfolio'],
            CompliancePolicyService::KEY_PORTFOLIO_YELLOW              => ['label' => 'Portfolio Badge gelb ab (%)',                  'type' => 'int',  'min' => 0, 'max' => 100, 'group' => 'portfolio'],
            CompliancePolicyService::KEY_REUSE_DAYS_PER_REQUIREMENT    => ['label' => 'FTE-Tage-pro-Requirement (WS-8 Heuristik)',     'type' => 'float','min' => 0, 'max' => 10, 'step' => 0.05, 'group' => 'setup'],
            CompliancePolicyService::KEY_IMPORT_MAX_UPLOAD_MB          => ['label' => 'Import-Upload-Limit (MB)',                     'type' => 'int',  'min' => 1, 'max' => 500, 'group' => 'import'],
            CompliancePolicyService::KEY_IMPORT_FOUR_EYES_ROW_THRESHOLD=> ['label' => 'Import 4-Augen-Pflicht ab Zeilen',             'type' => 'int',  'min' => 0, 'max' => 10000,'group' => 'import'],
            CompliancePolicyService::KEY_INHERITANCE_BADGE_POLL        => ['label' => 'Navigations-Badge-Polling (Sekunden)',          'type' => 'int',  'min' => 10, 'max' => 3600, 'group' => 'ui'],
            CompliancePolicyService::KEY_QUICK_WIN_EFFORT_PERCENTILE   => ['label' => 'Quick-Win-Aufwands-Perzentil (%)',              'type' => 'int',  'min' => 1, 'max' => 100, 'group' => 'gap_report'],
            CompliancePolicyService::KEY_QUICK_WIN_MIN_GAP_PERCENT     => ['label' => 'Quick-Win minimaler Gap (%)',                   'type' => 'int',  'min' => 0, 'max' => 100, 'group' => 'gap_report'],
        ];
    }

    public function __construct(
        private readonly CompliancePolicyService $policy,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $defs = $this->fieldDefs();
        $current = $this->policy->all();
        $defaults = $this->policy->defaults();

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->csrf->isTokenValid(new CsrfToken('compliance_policy_update', $token))) {
                $this->addFlash('danger', 'Ungültiges CSRF-Token.');
                return $this->redirectToRoute('admin_compliance_policy_index');
            }

            /** @var User $user */
            $user = $this->getUser();
            $actor = $user->getEmail() ?? 'admin';
            $changed = 0;

            foreach ($defs as $key => $def) {
                $raw = $request->request->get(str_replace('.', '_', $key));
                if ($raw === null && $def['type'] !== 'bool') {
                    continue;
                }
                $value = match ($def['type']) {
                    'int' => (int) $raw,
                    'float' => (float) $raw,
                    'bool' => $request->request->getBoolean(str_replace('.', '_', $key)),
                    default => $raw,
                };

                if (isset($def['min']) && $value < $def['min']) {
                    $value = $def['min'];
                }
                if (isset($def['max']) && $value > $def['max']) {
                    $value = $def['max'];
                }

                if ($value !== ($current[$key] ?? null)) {
                    $this->policy->set($key, $value, $actor);
                    $changed++;
                }
            }

            $this->addFlash('success', sprintf('%d Einstellung(en) gespeichert.', $changed));
            return $this->redirectToRoute('admin_compliance_policy_index');
        }

        return $this->render('admin/compliance_policy/index.html.twig', [
            'defs' => $defs,
            'current' => $current,
            'defaults' => $defaults,
        ]);
    }

    #[Route('/reset/{key}', name: 'reset', methods: ['POST'], requirements: ['key' => '[a-z0-9_.]+'])]
    public function reset(string $key, Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('compliance_policy_reset_' . $key, $token))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('admin_compliance_policy_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $default = $this->policy->defaults()[$key] ?? null;
        $this->policy->set($key, $default, $user->getEmail() ?? 'admin');

        $this->addFlash('success', sprintf('Einstellung "%s" auf Default zurückgesetzt.', $key));
        return $this->redirectToRoute('admin_compliance_policy_index');
    }
}
