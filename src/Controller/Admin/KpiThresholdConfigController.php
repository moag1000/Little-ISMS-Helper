<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\KpiThresholdConfig;
use App\Entity\Tenant;
use App\Form\KpiThresholdConfigType;
use App\Repository\KpiThresholdConfigRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase 4c role-scope migration: ROLE_ADMIN configures own tenant,
 * SUPER_ADMIN any. Tenant scope resolves via
 * {@see TenantContext::resolveAdminScope()} — replaces the inline
 * SUPER-vs-ADMIN branch in {@see self::denyIfWrongTenant()}.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/kpi-thresholds', name: 'admin_kpi_threshold_')]
class KpiThresholdConfigController extends AbstractController
{
    public function __construct(
        private readonly KpiThresholdConfigRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Pre-translate a flash message using the `admin` domain.
     *
     * The base.html.twig flash renderer prints messages verbatim (no |trans
     * filter), so controllers must translate keys before calling addFlash().
     * Bug-fix 2026-05-26: L2 sweep surfaced `kpi_threshold.flash.no_tenant`
     * appearing untranslated on `/de/admin/kpi-thresholds/new` for SUPER_ADMIN.
     */
    private function flashTrans(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // SUPER_ADMIN may request global view (tenant_id=global) and see all rows;
        // ROLE_ADMIN always falls back to their own tenant.
        $requested = $request->query->get('tenant_id');
        try {
            $tenant = $this->tenantContext->resolveAdminScope($requested);
        } catch (\Throwable) {
            $tenant = $this->tenantContext->getCurrentTenant();
        }
        $configs = $tenant instanceof Tenant
            ? $this->repository->findBy(['tenant' => $tenant], ['kpiKey' => 'ASC'])
            : $this->repository->findAll();

        return $this->render('admin/kpi_threshold/index.html.twig', [
            'configs' => $configs,
            'tenant' => $tenant,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // SUPER_ADMIN visiting /new without a tenant_id falls through here
        // (resolveAdminScope() returns null for global scope). They must pick
        // a specific tenant before creating a tenant-scoped threshold —
        // a `KpiThresholdConfig` row has NOT NULL `tenant_id`.
        // For ROLE_ADMIN this branch is unreachable: their current tenant is
        // always returned by resolveAdminScope().
        $tenant = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', $this->flashTrans('kpi_threshold.flash.no_tenant'));
            return $this->redirectToRoute('admin_kpi_threshold_index');
        }

        $config = new KpiThresholdConfig();
        $config->setTenant($tenant);

        $form = $this->createForm(KpiThresholdConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($config->getGoodThreshold() < $config->getWarningThreshold()) {
                $this->addFlash('error', $this->flashTrans('kpi_threshold.flash.good_below_warning'));
                $status = ($form->isSubmitted() && !$form->isValid())
                    ? Response::HTTP_UNPROCESSABLE_ENTITY
                    : Response::HTTP_OK;

                return $this->render('admin/kpi_threshold/new.html.twig', ['form' => $form], new Response(status: $status));
            }
            try {
                $this->entityManager->persist($config);
                $this->entityManager->flush();
                $this->addFlash('success', $this->flashTrans('kpi_threshold.flash.created'));
                return $this->redirectToRoute('admin_kpi_threshold_index');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', $this->flashTrans('kpi_threshold.flash.duplicate_key'));
            }
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/kpi_threshold/new.html.twig', ['form' => $form], new Response(status: $status));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, KpiThresholdConfig $config): Response
    {
        $this->denyIfWrongTenant($config);

        $form = $this->createForm(KpiThresholdConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($config->getGoodThreshold() < $config->getWarningThreshold()) {
                $this->addFlash('error', $this->flashTrans('kpi_threshold.flash.good_below_warning'));
                $status = ($form->isSubmitted() && !$form->isValid())
                    ? Response::HTTP_UNPROCESSABLE_ENTITY
                    : Response::HTTP_OK;

                return $this->render('admin/kpi_threshold/edit.html.twig', ['form' => $form, 'config' => $config], new Response(status: $status));
            }
            $config->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', $this->flashTrans('kpi_threshold.flash.updated'));
            return $this->redirectToRoute('admin_kpi_threshold_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/kpi_threshold/edit.html.twig', [
            'form' => $form,
            'config' => $config,
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, KpiThresholdConfig $config): Response
    {
        $this->denyIfWrongTenant($config);

        if (!$this->isCsrfTokenValid('delete_kth_' . $config->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($config);
        $this->entityManager->flush();
        $this->addFlash('success', $this->flashTrans('kpi_threshold.flash.deleted'));

        return $this->redirectToRoute('admin_kpi_threshold_index');
    }

    private function denyIfWrongTenant(KpiThresholdConfig $config): void
    {
        // resolveAdminScope throws AccessDeniedException for cross-tenant
        // attempts; SUPER_ADMIN is allowed on any tenant.
        $this->tenantContext->resolveAdminScope($config->getTenant()?->getId());
    }
}
