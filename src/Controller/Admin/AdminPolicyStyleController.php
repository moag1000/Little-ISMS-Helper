<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Entity\User;
use App\Form\Admin\TenantPolicyStyleType;
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
 * Per-tenant Policy-Doc Style Configurator (Sprint policy-style-admin).
 *
 * Lets ROLE_ADMIN / Konzern-CISO re-skin generated policy documents
 * (font, cover pattern, watermark, signature lines, TOC/history toggles,
 * page margin, cover logo size, optional custom-CSS override) without
 * touching code. Form persists to TenantBranding; Live-Preview-XHR
 * re-renders the `_fa_policy_doc.html.twig` macro with sample data.
 *
 * Tenant-isolation: every request is scoped to TenantContext->getCurrentTenant().
 * Cross-tenant data leak is structurally prevented because the preview
 * action also re-resolves the tenant from context (no tenant_id in
 * request payload).
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/policy-style')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPolicyStyleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly TenantBrandingRepository $brandingRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_policy_style_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $branding = $this->resolveBrandingFor($tenant);

        $oldSnapshot = $branding->getPolicyDocStyleConfig();

        $form = $this->createForm(TenantPolicyStyleType::class, $branding);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $branding->setUpdatedAt(new DateTimeImmutable());
            $user = $this->getUser();
            if ($user instanceof User) {
                $branding->setUpdatedByUser($user);
            }
            $this->em->persist($branding);
            $this->em->flush();

            $newSnapshot = $branding->getPolicyDocStyleConfig();
            $changedFields = $this->diffKeys($oldSnapshot, $newSnapshot);

            $this->auditLogger->logCustom(
                action: 'tenant.policy_style_updated',
                entityType: 'TenantBranding',
                entityId: $branding->getId(),
                oldValues: $oldSnapshot,
                newValues: $newSnapshot,
                description: sprintf(
                    'Policy-doc style updated (%d field%s changed): %s',
                    count($changedFields),
                    count($changedFields) === 1 ? '' : 's',
                    implode(', ', $changedFields) ?: 'none',
                ),
            );

            $this->addFlash('success', 'admin.policy_style.flash.saved');
            return $this->redirectToRoute('app_admin_policy_style_edit');
        }

        return $this->render('admin/policy_style/edit.html.twig', [
            'form' => $form,
            'tenant' => $tenant,
            'branding' => $branding,
            'style_config' => $branding->getPolicyDocStyleConfig(),
            'sample' => $this->buildSampleData($tenant),
        ]);
    }

    /**
     * XHR endpoint: re-renders the policy-doc macro with the latest
     * style-config posted from the form. The body of the form is
     * rendered server-side so we get the canonical macro output (no
     * client-side duplication of the template).
     *
     * Returns the rendered HTML fragment as JSON {html: "..."} for
     * easy injection by the Stimulus controller.
     */
    #[Route('/preview', name: 'app_admin_policy_style_preview', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_policy_style_preview', tokenKey: '_preview_token')]
    public function preview(Request $request): JsonResponse
    {
        $tenant = $this->requireTenant();
        // Always re-load the persistent branding so the preview falls
        // back to stored values if a particular field is missing in the
        // payload. We then layer the *unsaved* form values on top.
        $branding = $this->resolveBrandingFor($tenant);
        $base = $branding->getPolicyDocStyleConfig();

        $payload = $request->toArray();
        $patch = $this->normalisePreviewPayload($payload);
        $styleConfig = array_merge($base, $patch);

        $html = $this->renderView('admin/policy_style/_preview.html.twig', [
            'tenant' => $tenant,
            'style_config' => $styleConfig,
            'sample' => $this->buildSampleData($tenant),
        ]);

        return new JsonResponse(['html' => $html]);
    }

    /**
     * Reset to defaults. POST-only; CSRF-guarded. Wipes all 12
     * `policyDoc*` fields back to their defaults but keeps the rest of
     * TenantBranding (logo, header, primary color) untouched.
     */
    #[Route('/reset', name: 'app_admin_policy_style_reset', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_policy_style_reset')]
    public function reset(): Response
    {
        $tenant = $this->requireTenant();
        $branding = $this->resolveBrandingFor($tenant);

        $oldSnapshot = $branding->getPolicyDocStyleConfig();

        $branding
            ->setPolicyDocFontFamily('Inter')
            ->setPolicyDocCoverPattern('branded')
            ->setPolicyDocWatermarkEnabled(true)
            ->setPolicyDocWatermarkOpacity(0.08)
            ->setPolicyDocSignatureLines(3)
            ->setPolicyDocShowToc(true)
            ->setPolicyDocShowHistory(true)
            ->setPolicyDocShowAnnexARefs(true)
            ->setPolicyDocFooterText(null)
            ->setPolicyDocCoverLogoSize('medium')
            ->setPolicyDocPageMargin('standard')
            ->setPolicyDocCustomCss(null)
            ->setUpdatedAt(new DateTimeImmutable());

        $user = $this->getUser();
        if ($user instanceof User) {
            $branding->setUpdatedByUser($user);
        }

        $this->em->persist($branding);
        $this->em->flush();

        $this->auditLogger->logCustom(
            action: 'tenant.policy_style_updated',
            entityType: 'TenantBranding',
            entityId: $branding->getId(),
            oldValues: $oldSnapshot,
            newValues: $branding->getPolicyDocStyleConfig(),
            description: 'Policy-doc style reset to defaults.',
        );

        $this->addFlash('success', 'admin.policy_style.flash.reset');
        return $this->redirectToRoute('app_admin_policy_style_edit');
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
            'font_family' => 'string',
            'cover_pattern' => 'string',
            'watermark_enabled' => 'bool',
            'watermark_opacity' => 'float',
            'signature_lines' => 'int',
            'show_toc' => 'bool',
            'show_history' => 'bool',
            'show_annex_a_refs' => 'bool',
            'footer_text' => 'string_nullable',
            'cover_logo_size' => 'string',
            'page_margin' => 'string',
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
                'int' => max(1, min(6, (int) $value)),
                'float' => max(0.0, min(1.0, (float) $value)),
                'string' => is_string($value) ? substr($value, 0, 64) : '',
                'string_nullable' => is_string($value) && trim($value) !== '' ? substr($value, 0, 500) : null,
                default => $value,
            };
        }

        // Validate enum allow-lists.
        if (isset($patch['cover_pattern'])
            && !in_array($patch['cover_pattern'], TenantBranding::POLICY_DOC_COVER_PATTERNS, true)) {
            $patch['cover_pattern'] = 'branded';
        }
        if (isset($patch['cover_logo_size'])
            && !in_array($patch['cover_logo_size'], TenantBranding::POLICY_DOC_LOGO_SIZES, true)) {
            $patch['cover_logo_size'] = 'medium';
        }
        if (isset($patch['page_margin'])
            && !in_array($patch['page_margin'], TenantBranding::POLICY_DOC_PAGE_MARGINS, true)) {
            $patch['page_margin'] = 'standard';
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
            'document_title' => 'Information-Security-Policy',
            'document_code' => 'POL-ISMS-001',
            'version' => '2.4',
            'classification' => 'TLP:AMBER',
            'sections' => [
                ['number' => '1', 'title' => 'Geltungsbereich', 'preview' => 'Diese Richtlinie gilt für alle Mitarbeitenden, Auftragnehmer und Drittparteien.'],
                ['number' => '2', 'title' => 'Verantwortlichkeiten', 'preview' => 'Die Geschäftsführung trägt Gesamtverantwortung; der CISO operationalisiert.'],
                ['number' => '3', 'title' => 'Anforderungen', 'preview' => 'Es gelten die Vorgaben aus ISO 27001:2022 Annex A inkl. organisationsinterner Erweiterungen.'],
            ],
            'history' => [
                ['version' => '2.4', 'date' => '2026-05-10', 'author' => 'CISO', 'note' => 'Annex-A-Update aus 2022-Revision.'],
                ['version' => '2.3', 'date' => '2025-11-02', 'author' => 'ISB', 'note' => 'Klarstellung Lieferantenpflichten.'],
            ],
            'approvers' => [
                ['name' => 'Anna Beispiel', 'role' => 'Geschäftsführung', 'signed_at' => null],
                ['name' => 'Bert Muster', 'role' => 'CISO', 'signed_at' => null],
            ],
            'annex_a_refs' => ['A.5.1', 'A.6.1', 'A.8.1'],
        ];
    }
}
