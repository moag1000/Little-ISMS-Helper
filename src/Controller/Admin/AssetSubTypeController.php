<?php

// @em-write-allowed: Tenant-scoped admin CRUD for a simple master-data entity
// (AssetSubType) — wrapping persist/flush in a separate service would be
// over-engineering for a 6-field config-grade entity with no domain logic.
// Mirrors the QuickCreateController pattern (S14 Cluster A).

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AssetSubType;
use App\Entity\Tenant;
use App\Form\Admin\AssetSubTypeType;
use App\Repository\AssetSubTypeRepository;
use App\Service\AssetSubTypeSeeder;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use App\Util\ListPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin CRUD for tenant-configurable AssetSubType layer.
 *
 * Provides:
 *  - CRUD index/new/edit/delete under `/admin/asset-sub-types`
 *  - Seed-preset application via POST (BSI / TISAX / production-DE-Mittelstand)
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/asset-sub-types', name: 'admin_asset_sub_type_')]
#[IsGranted('ROLE_ADMIN')]
final class AssetSubTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetSubTypeRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly AssetSubTypeSeeder $seeder,
        private readonly AuditLogger $audit,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', $this->translator->trans(
                'asset_sub_type.flash.tenant_required',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('app_dashboard');
        }

        $subTypes = $this->repository->findAllByTenant($tenant);
        $counts = $this->repository->countByTopType($tenant);

        return $this->render('admin/asset_sub_type/index.html.twig', [
            'page' => ListPage::fromRequest(
                $subTypes,
                $request,
                static fn(AssetSubType $st): array => [$st->getName(), $st->getDescription(), $st->getTopType()],
            ),
            'counts_by_top_type' => $counts,
            'top_types' => AssetSubType::TOP_TYPES,
            'presets' => $this->seeder->availablePresets(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', $this->translator->trans(
                'asset_sub_type.flash.tenant_required',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $subType = new AssetSubType();
        $subType->setTenant($tenant);
        $subType->setSource(AssetSubType::SOURCE_CUSTOM);

        $form = $this->createForm(AssetSubTypeType::class, $subType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($subType);
            $this->em->flush();

            $this->audit->logCustom('asset_sub_type.create', 'AssetSubType', $subType->getId(), null, [
                'top_type' => $subType->getTopType(),
                'name' => $subType->getName(),
            ]);

            $this->addFlash('success', $this->translator->trans(
                'asset_sub_type.flash.created',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/asset_sub_type/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(AssetSubType $subType, Request $request): Response
    {
        $this->assertOwnedByCurrentTenant($subType);

        $form = $this->createForm(AssetSubTypeType::class, $subType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->audit->logCustom('asset_sub_type.update', 'AssetSubType', $subType->getId(), null, [
                'top_type' => $subType->getTopType(),
                'name' => $subType->getName(),
            ]);

            $this->addFlash('success', $this->translator->trans(
                'asset_sub_type.flash.updated',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/asset_sub_type/edit.html.twig', [
            'form' => $form->createView(),
            'sub_type' => $subType,
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(AssetSubType $subType, Request $request): Response
    {
        $this->assertOwnedByCurrentTenant($subType);

        if (!$this->isCsrfTokenValid('asset_sub_type_delete_' . $subType->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans(
                'asset_sub_type.flash.csrf_invalid',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $id = $subType->getId();
        $name = $subType->getName();
        $topType = $subType->getTopType();

        $this->em->remove($subType);
        $this->em->flush();

        $this->audit->logCustom('asset_sub_type.delete', 'AssetSubType', $id, null, [
            'top_type' => $topType,
            'name' => $name,
        ]);

        $this->addFlash('success', $this->translator->trans(
            'asset_sub_type.flash.deleted',
            [],
            'asset_sub_type',
        ));
        return $this->redirectToRoute('admin_asset_sub_type_index');
    }

    /**
     * Apply industry-preset seed. Synchronous (small dataset ≤ 30 rows — no async needed).
     */
    #[Route('/seed', name: 'seed', methods: ['POST'])]
    #[IsCsrfTokenValid('asset_sub_type_seed')]
    public function seed(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', $this->translator->trans(
                'asset_sub_type.flash.tenant_required',
                [],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $preset = (string) $request->request->get('preset', '');
        if (!in_array($preset, AssetSubTypeSeeder::PRESETS, true)) {
            $this->addFlash('danger', $this->translator->trans(
                'asset_sub_type.flash.preset_invalid',
                ['%preset%' => $preset],
                'asset_sub_type',
            ));
            return $this->redirectToRoute('admin_asset_sub_type_index');
        }

        $result = $this->seeder->applyPreset($tenant, $preset);

        $this->audit->logCustom('asset_sub_type.seed', 'AssetSubType', null, null, [
            'preset' => $result['preset'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'total' => $result['total'],
        ]);

        $this->addFlash('success', $this->translator->trans(
            'asset_sub_type.flash.seeded',
            [
                '%preset%' => $result['preset'],
                '%created%' => $result['created'],
                '%skipped%' => $result['skipped'],
                '%total%' => $result['total'],
            ],
            'asset_sub_type',
        ));

        return $this->redirectToRoute('admin_asset_sub_type_index');
    }

    private function assertOwnedByCurrentTenant(AssetSubType $subType): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $subType->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Sub-Type belongs to a different tenant.');
        }
    }
}
