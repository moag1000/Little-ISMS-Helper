<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Entity\User;
use App\Form\Admin\TenantReportStyleType;
use App\Repository\TenantBrandingRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-tenant Report-Doc Style Configurator (Sprint report-style-admin).
 *
 * Lets ROLE_ADMIN re-skin generated report documents (cover pattern,
 * default audience, watermark, exec-summary/appendix/distribution-list
 * toggles, font, page orientation, chart color scheme, footer
 * disclaimer, optional custom-CSS override) without touching code.
 * Form persists to TenantBranding; Live-Preview-XHR re-renders the
 * `_fa_report_doc.html.twig` macro with sample data.
 *
 * Sister controller `AdminPolicyStyleController` handles the
 * `policyDoc*` fields; this one is independent and only touches
 * `reportDoc*` fields, so the two configurators don't clash.
 *
 * Tenant-isolation: every request is scoped to TenantContext->getCurrentTenant().
 * Cross-tenant data leak is structurally prevented because the preview
 * action also re-resolves the tenant from context (no tenant_id in
 * request payload).
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/report-style')]
#[IsGranted('ROLE_ADMIN')]
final class AdminReportStyleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly TenantBrandingRepository $brandingRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_report_style_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $branding = $this->resolveBrandingFor($tenant);

        $oldSnapshot = $branding->getReportDocStyleConfig();

        $form = $this->createForm(TenantReportStyleType::class, $branding);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $branding->setUpdatedAt(new DateTimeImmutable());
            $user = $this->getUser();
            if ($user instanceof User) {
                $branding->setUpdatedByUser($user);
            }
            $this->em->persist($branding);
            $this->em->flush();

            $newSnapshot = $branding->getReportDocStyleConfig();
            $changedFields = $this->diffKeys($oldSnapshot, $newSnapshot);

            $this->auditLogger->logCustom(
                action: 'tenant.report_style_updated',
                entityType: 'TenantBranding',
                entityId: $branding->getId(),
                oldValues: $oldSnapshot,
                newValues: $newSnapshot,
                description: sprintf(
                    'Report-doc style updated (%d field%s changed): %s',
                    count($changedFields),
                    count($changedFields) === 1 ? '' : 's',
                    implode(', ', $changedFields) ?: 'none',
                ),
            );

            $this->addFlash('success', 'admin.report_style.flash.saved');
            return $this->redirectToRoute('app_admin_report_style_edit', ['_locale' => $request->getLocale()]);
        }

        return $this->render('admin/report_style/edit.html.twig', [
            'form' => $form,
            'tenant' => $tenant,
            'branding' => $branding,
            'style_config' => $branding->getReportDocStyleConfig(),
            'sample' => $this->buildSampleData($tenant),
        ]);
    }

    /**
     * XHR endpoint: re-renders the report-doc preview with the latest
     * style-config posted from the form. The body of the form is
     * rendered server-side so we get the canonical macro output (no
     * client-side duplication of the template).
     *
     * Returns the rendered HTML fragment as JSON {html: "..."} for
     * easy injection by the Stimulus controller.
     */
    #[Route('/preview', name: 'app_admin_report_style_preview', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_report_style_preview', tokenKey: '_preview_token')]
    public function preview(Request $request): JsonResponse
    {
        $tenant = $this->requireTenant();
        // Always re-load the persistent branding so the preview falls
        // back to stored values if a particular field is missing in the
        // payload. We then layer the *unsaved* form values on top.
        $branding = $this->resolveBrandingFor($tenant);
        $base = $branding->getReportDocStyleConfig();

        $payload = $request->toArray();
        $patch = $this->normalisePreviewPayload($payload);
        $styleConfig = array_merge($base, $patch);

        $html = $this->renderView('admin/report_style/_preview.html.twig', [
            'tenant' => $tenant,
            'style_config' => $styleConfig,
            'sample' => $this->buildSampleData($tenant),
        ]);

        return new JsonResponse(['html' => $html]);
    }

    /**
     * Reset to defaults. POST-only; CSRF-guarded. Wipes all 12
     * `reportDoc*` fields back to their defaults but keeps the rest of
     * TenantBranding (logo, header, primary color, policyDoc*) intact.
     */
    #[Route('/reset', name: 'app_admin_report_style_reset', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_report_style_reset')]
    public function reset(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $branding = $this->resolveBrandingFor($tenant);

        $oldSnapshot = $branding->getReportDocStyleConfig();

        $branding
            ->setReportDocCoverPattern('branded')
            ->setReportDocDefaultAudience('internal')
            ->setReportDocWatermarkEnabled(true)
            ->setReportDocWatermarkOpacity(0.08)
            ->setReportDocShowExecSummary(true)
            ->setReportDocShowAppendix(true)
            ->setReportDocShowDistributionList(true)
            ->setReportDocFontFamily('Inter')
            ->setReportDocPageOrientation('auto')
            ->setReportDocChartColorScheme('aurora')
            ->setReportDocFooterDisclaimer(null)
            ->setReportDocCustomCss(null)
            ->setUpdatedAt(new DateTimeImmutable());

        $user = $this->getUser();
        if ($user instanceof User) {
            $branding->setUpdatedByUser($user);
        }

        $this->em->persist($branding);
        $this->em->flush();

        $this->auditLogger->logCustom(
            action: 'tenant.report_style_updated',
            entityType: 'TenantBranding',
            entityId: $branding->getId(),
            oldValues: $oldSnapshot,
            newValues: $branding->getReportDocStyleConfig(),
            description: 'Report-doc style reset to defaults.',
        );

        $this->addFlash('success', 'admin.report_style.flash.reset');
        return $this->redirectToRoute('app_admin_report_style_edit', ['_locale' => $request->getLocale()]);
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant in context.');
        }
        return $tenant;
    }

    private function resolveBrandingFor(Tenant $tenant): TenantBranding
    {
        $branding = $this->brandingRepository->findOneByTenant($tenant);
        if ($branding instanceof TenantBranding) {
            return $branding;
        }

        $branding = new TenantBranding();
        $branding->setTenant($tenant);
        $user = $this->getUser();
        if ($user instanceof User) {
            $branding->setUpdatedByUser($user);
        }
        return $branding;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    private function diffKeys(array $old, array $new): array
    {
        $changed = [];
        foreach ($new as $key => $value) {
            $previous = $old[$key] ?? null;
            if ($previous !== $value) {
                $changed[] = $key;
            }
        }
        return $changed;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalisePreviewPayload(array $payload): array
    {
        $allowed = [
            'cover_pattern' => 'string',
            'default_audience' => 'string',
            'watermark_enabled' => 'bool',
            'watermark_opacity' => 'float',
            'show_exec_summary' => 'bool',
            'show_appendix' => 'bool',
            'show_distribution_list' => 'bool',
            'font_family' => 'string',
            'page_orientation' => 'string',
            'chart_color_scheme' => 'string',
            'footer_disclaimer' => 'string_nullable',
            // custom_css NOT accepted via XHR — preview always uses the
            // stored value to avoid arbitrary CSS injection from non-admin
            // users with a forged payload.
        ];

        $patch = [];
        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            $patch[$key] = match ($type) {
                'bool' => (bool) $value,
                'float' => max(0.0, min(1.0, (float) $value)),
                'string' => is_string($value) ? substr($value, 0, 64) : '',
                'string_nullable' => is_string($value) && trim($value) !== '' ? substr($value, 0, 1000) : null,
                default => $value,
            };
        }

        // Validate enum allow-lists. Unknown values fall back to defaults
        // so the preview never renders broken markup from a forged payload.
        if (isset($patch['cover_pattern'])
            && !in_array($patch['cover_pattern'], TenantBranding::REPORT_DOC_COVER_PATTERNS, true)) {
            $patch['cover_pattern'] = 'branded';
        }
        if (isset($patch['default_audience'])
            && !in_array($patch['default_audience'], TenantBranding::REPORT_DOC_AUDIENCES, true)) {
            $patch['default_audience'] = 'internal';
        }
        if (isset($patch['page_orientation'])
            && !in_array($patch['page_orientation'], TenantBranding::REPORT_DOC_PAGE_ORIENTATIONS, true)) {
            $patch['page_orientation'] = 'auto';
        }
        if (isset($patch['chart_color_scheme'])
            && !in_array($patch['chart_color_scheme'], TenantBranding::REPORT_DOC_CHART_COLOR_SCHEMES, true)) {
            $patch['chart_color_scheme'] = 'aurora';
        }

        return $patch;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSampleData(Tenant $tenant): array
    {
        return [
            'tenant_name' => $tenant->getName() ?? 'Sample Tenant',
            'report_title' => 'ISMS-Management-Review Q2/2026',
            'report_code' => 'RPT-MR-2026-Q2',
            'report_period' => '2026-04-01 — 2026-06-30',
            'classification' => 'TLP:AMBER',
            'distribution' => [
                ['name' => 'Geschäftsführung', 'role' => 'Empfänger'],
                ['name' => 'CISO', 'role' => 'Autor'],
                ['name' => 'Interne Revision', 'role' => 'In Kopie'],
            ],
            'exec_summary' => 'Im Berichtszeitraum wurden 12 Risiken neu identifiziert, '
                . '7 Maßnahmen abgeschlossen und 2 Vorfälle bearbeitet. Die Wirksamkeit '
                . 'des ISMS bleibt insgesamt angemessen; Empfehlungen siehe Anhang A.',
            'sections' => [
                ['number' => '1', 'title' => 'Risikolage', 'preview' => 'Verteilung über Bereiche und Trend zum Vorquartal.'],
                ['number' => '2', 'title' => 'Maßnahmen-Status', 'preview' => 'Anteil offen/in Umsetzung/abgeschlossen pro Domäne.'],
                ['number' => '3', 'title' => 'Vorfälle und Lessons Learned', 'preview' => 'Top-3 Vorfälle, Ursachen und Wirksamkeit der Reaktion.'],
            ],
            'kpis' => [
                ['label' => 'Offene Risiken', 'value' => 47],
                ['label' => 'Maßnahmen-Quote', 'value' => '78%'],
                ['label' => 'MTTR (h)', 'value' => 4.2],
            ],
            'appendix' => [
                ['code' => 'A', 'title' => 'Empfehlungen aus Reviews'],
                ['code' => 'B', 'title' => 'KPI-Detail-Tabellen'],
            ],
        ];
    }
}
