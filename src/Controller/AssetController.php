<?php

declare(strict_types=1);

namespace App\Controller;

use Exception;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\Asset;
use App\Enum\AssetStatus;
use App\Form\AssetQuickType;
use App\Form\AssetType;
use App\Repository\AssetDependencyRepository;
use App\Repository\AssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\RiskRepository;
use App\Service\AiAgentInventoryService;
use App\Service\AssetDependencyService;
use App\Service\AssetService;
use App\Service\AssetQrCodeService;
use App\Repository\CommentRepository;
use App\Service\InverseCoverageService;
use App\Service\ProtectionRequirementService;
use App\Service\TagFilterService;
use App\Service\TenantContext;
use App\Service\AuditLogger;
use App\Service\Clone\AssetCloner;
use App\Repository\UserRepository;
use App\Controller\Trait\BulkActionTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class AssetController extends AbstractController
{
    use LocalizedFlashTrait;
    use BulkActionTrait;
    use InPageFormTrait;

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetService $assetService,
        private readonly AssetQrCodeService $assetQrCodeService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ProtectionRequirementService $protectionRequirementService,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly RiskRepository $riskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly TagFilterService $tagFilterService,
        private readonly ?AssetDependencyService $assetDependencyService = null,
        private readonly ?AiAgentInventoryService $aiAgentInventoryService = null,
        private readonly ?InverseCoverageService $inverseCoverageService = null,
        private readonly ?CommentRepository $commentRepository = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?AssetDependencyRepository $assetDependencyRepository = null,
        private readonly ?AssetCloner $assetCloner = null,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'asset';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }
    #[Route('/asset', name: 'app_asset_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters
        $q = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type');
        $classification = $request->query->get('classification');
        $owner = $request->query->get('owner');
        $status = $request->query->get('status');
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Cross-tenant + orphan views are admin-only — silently coerce to
        // 'own' for non-admins so a hand-crafted ?view=all URL doesn't leak
        // foreign-tenant data.
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (in_array($view, ['orphaned', 'all'], true) && !$isAdmin) {
            $view = 'own';
        }

        // Get assets based on view filter
        if ($tenant) {
            // Determine which assets to load based on view parameter
            $assets = match ($view) {
                // Only own assets
                'own' => $this->assetRepository->findByTenant($tenant),
                // Own + from all subsidiaries (for parent companies)
                'subsidiaries' => $this->assetRepository->findByTenantIncludingSubsidiaries($tenant),
                // Tenant-less (orphan) assets — admin only
                'orphaned' => $this->assetRepository->findOrphaned(),
                // Cross-tenant overview — admin only
                'all' => $this->assetRepository->findAllAcrossTenants(),
                // Own + inherited from parents (default behavior)
                default => $this->assetService->getAssetsForTenant($tenant),
            };
            $inheritanceInfo = $this->assetService->getAssetInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
            $inheritanceInfo['isAdmin'] = $isAdmin;
        } else {
            $assets = $this->assetRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own',
                'isAdmin' => $isAdmin,
            ];
        }

        // Hide end-of-life assets from the default list. Users can still surface
        // them explicitly via ?status=retired / ?status=disposed.
        if ($status === null || $status === '') {
            $assets = array_filter(
                $assets,
                fn(Asset $asset): bool => !in_array($asset->getStatus(), [AssetStatus::Retired->value, AssetStatus::Disposed->value], true)
            );
        }

        if ($type) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getAssetType() === $type);
        }

        if ($classification) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getDataClassification() === $classification);
        }

        if ($owner) {
            $assets = array_filter($assets, fn(Asset $asset): bool => stripos((string) $asset->getOwner(), $owner) !== false);
        }

        if ($status) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getStatus() === $status);
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $assets = array_filter($assets, function (Asset $asset) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($asset->getName() ?? '')
                    . ' ' . ($asset->getDescription() ?? '')
                    . ' ' . ($asset->getAssetType() ?? '')
                    . ' ' . ($asset->getOwner() ?? '')
                    . ' ' . (string) $asset->getId()
                );
                return str_contains($haystack, $needle);
            });
        }

        // Re-index array after filtering to avoid gaps in keys
        $assets = array_values($assets);

        // WS-5: framework-tag filter via ?tag=NIS2
        $tagFilter = $request->query->get('tag');
        if (is_string($tagFilter) && $tagFilter !== '') {
            $assets = $this->tagFilterService->filterByTagName($assets, Asset::class, $tagFilter);
        }

        // DORA Phase 1: filter-chip "Nur DORA-relevant / DORA only" via ?dora_relevant=1
        if ($request->query->get('dora_relevant') === '1') {
            $assets = array_values(array_filter(
                $assets,
                fn(Asset $a): bool => $a->isDoraRelevant(),
            ));
        }

        $typeStats = $tenant
            ? $this->assetRepository->countByType($tenant)
            : [];

        // Calculate BCM-based suggestions and risk count for each asset
        $assetRecommendations = [];
        foreach ($assets as $asset) {
            $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
            $processes = $this->businessProcessRepository->findByAsset($asset->getId());
            $risks = $this->riskRepository->findBy(['asset' => $asset]);

            $assetRecommendations[$asset->getId()] = [
                'availability_analysis' => $analysis['availability'],
                'has_bcm_data' => $processes !== [],
                'process_count' => count($processes),
                'processes' => $processes,
                'risk_count' => count($risks),
            ];
        }

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($assets, $tenant);
        } else {
            $detailedStats = ['own' => count($assets), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($assets)];
        }

        return $this->render('asset/index.html.twig', [
            'assets' => $assets,
            'typeStats' => $typeStats,
            'recommendations' => $assetRecommendations,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }
    #[Route('/asset/new', name: 'app_asset_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $asset = new Asset();
        $asset->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(AssetType::class, $asset);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($asset);
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('asset.success.created');

            if ($this->isTurboFrameRequest($request)) {
                return $this->assetStreamSave($asset, isNew: true);
            }
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('asset/_form_modal.html.twig', [
                'asset' => $asset,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('asset/new.html.twig', [
            'asset' => $asset,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/asset/new/quick', name: 'app_asset_new_quick', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function newQuick(Request $request): Response
    {
        $asset = new Asset();
        $asset->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(AssetQuickType::class, $asset);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($asset);
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('asset.success.created');
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('asset/new_quick.html.twig', [
            'asset' => $asset,
            'form' => $form,
        ], new Response(status: $status));
    }
    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * Warns if an Asset has associated Risks or Incidents that reference it.
     */
    #[Route('/asset/bulk-delete-check', name: 'app_asset_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = array_filter((array) ($data['ids'] ?? []), 'is_int');
        if ($ids === []) {
            return new JsonResponse(['dependencies' => [], 'checked_count' => 0]);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $assets = $this->assetRepository->findBy(['id' => $ids, 'tenant' => $tenant]);

        return $this->checkBulkDependencies($assets, 'getName', [
            fn (Asset $asset): ?array => ($c = $asset->getRisks()->count()) > 0
                ? ['message' => sprintf('%d Risk(s) verknüpft', $c), 'icon' => 'shield-exclamation']
                : null,
            fn (Asset $asset): ?array => ($c = $asset->getIncidents()->count()) > 0
                ? ['message' => sprintf('%d Vorfall/Vorfälle verknüpft', $c), 'icon' => 'exclamation-triangle']
                : null,
            fn (Asset $asset): ?array => ($c = $asset->getProtectingControls()->count()) > 0
                ? ['message' => sprintf('%d Maßnahme(n) verknüpft', $c), 'icon' => 'check-circle']
                : null,
        ]);
    }

    #[Route('/asset/bulk-delete', name: 'app_asset_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_delete', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $asset = $this->assetRepository->find($id);

                if (!$asset) {
                    $errors[] = "Asset ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's assets
                if ($asset->getTenant() !== $tenant) {
                    $errors[] = "Asset ID $id does not belong to your organization";
                    continue;
                }

                // Check for dependencies (optional warning, but still allow delete)
                $risks = $asset->getRisks();
                if ($risks->count() > 0) {
                    // Log dependency info but continue
                }

                $this->entityManager->remove($asset);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting asset ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        $message = $deleted > 0
            ? $this->translator->trans('asset.bulk_delete.success', ['count' => $deleted], 'assets')
            : $this->translator->trans('asset.bulk_delete.no_items', [], 'assets');

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors,
                'message' => $message
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => $message
        ]);
    }
    #[Route('/asset/{id}', name: 'app_asset_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Request $request, Asset $asset): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // In-drawer → condensed read-only detail; direct URL → full page (fallback).
        // canEdit gating mirrors the full page: inherited assets are not editable.
        if ($this->isTurboFrameRequest($request)) {
            $canEdit = $tenant ? $this->assetService->canEditAsset($asset, $tenant) : true;

            return $this->render('asset/_detail_modal.html.twig', [
                'asset' => $asset,
                'canEdit' => $canEdit,
            ]);
        }

        $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
        $processes = $this->businessProcessRepository->findByAsset($asset->getId());

        // Get audit log history for this asset (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Asset', $asset->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        // Check if asset is inherited and can be edited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->assetService->isInheritedAsset($asset, $tenant);
            $canEdit = $this->assetService->canEditAsset($asset, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        $inheritedProtection = $this->assetDependencyService?->calculateInheritedProtectionNeed($asset);

        // Bucket-6 RT_05: enriched dependency edges (dependencyType +
        // criticalityImpact). Keyed by upstream-asset id so the show
        // template can decorate the legacy adjacency-list entries.
        $assetDependencyEdges = [];
        if ($this->assetDependencyRepository !== null) {
            foreach ($this->assetDependencyRepository->findOutgoingFor($asset) as $edge) {
                $targetId = $edge->getTargetAsset()?->getId();
                if ($targetId !== null) {
                    $assetDependencyEdges[$targetId] = $edge;
                }
            }
        }

        // Hochrisiko-AI-Agents: verknüpfte DPIAs für Audit-Sicht (Soft-Failure: null = Modul/Schema fehlt)
        $linkedDpias = ($asset->isAiAgent() && $this->aiAgentInventoryService !== null)
            ? $this->aiAgentInventoryService->findLinkedDpias($asset)
            : null;

        // V3 B6 / EF-4: Inverse-Coverage Impact-Analyse
        $impactCoverage = $this->inverseCoverageService?->forAsset($asset) ?? ['total' => 0, 'frameworks' => []];

        // V4 LB-4: Comment-Thread adoption — load thread for this Asset.
        $comments = [];
        $tenantCtx = $this->tenantContext->getCurrentTenant();
        if ($this->commentRepository !== null && $tenantCtx !== null && $asset->getId() !== null) {
            $comments = $this->commentRepository->findThread($tenantCtx, 'Asset', $asset->getId());
        }

        return $this->render('asset/show.html.twig', [
            'asset' => $asset,
            'analysis' => $analysis,
            'processes' => $processes,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
            'inheritedProtection' => $inheritedProtection,
            'assetDependencyEdges' => $assetDependencyEdges,
            'linkedDpias' => $linkedDpias,
            'impact_coverage' => $impactCoverage,
            'comments' => $comments,
        ]);
    }
    /**
     * Clone an Asset (C4-C1 — Klon-Funktionen). Open to ROLE_USER. Keeps
     * configuration (type/sub-type/location/CIA/AI metadata), resets
     * lifecycle to active, omits M2M cascades (risks/incidents/controls).
     */
    #[Route('/asset/{id}/clone', name: 'app_asset_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function clone(Request $request, Asset $asset): Response
    {
        if (!$this->isCsrfTokenValid('clone_asset_' . $asset->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($this->assetCloner === null) {
            throw $this->createNotFoundException('Asset clone service is not available.');
        }

        $clone = $this->assetCloner->clone(
            $asset,
            null,
            trim((string) $request->request->get('title_override', '')) ?: null,
        );
        $this->entityManager->flush();

        $this->auditLogger?->logCreate(
            entityType: 'Asset',
            entityId: $clone->getId(),
            newValues: ['cloned_from_id' => $asset->getId(), 'name' => $clone->getName()],
            description: 'Cloned from Asset #' . $asset->getId(),
        );

        $this->addFlash('success', $this->translator->trans('asset.clone.success', [], 'asset'));
        return $this->redirectToRoute('app_asset_edit', ['id' => $clone->getId()]);
    }

    #[Route('/asset/{id}/edit', name: 'app_asset_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Asset $asset): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if asset can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->assetService->canEditAsset($asset, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited', [], 'messages'));
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        $form = $this->createForm(AssetType::class, $asset);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('asset.success.updated');

            if ($this->isTurboFrameRequest($request)) {
                return $this->assetStreamSave($asset, isNew: false);
            }
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('asset/_form_modal.html.twig', [
                'asset' => $asset,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('asset/edit.html.twig', [
            'asset' => $asset,
            'form' => $form,
        ], new Response(status: $status));
    }

    /** Turbo Stream after a successful in-modal Asset save (row replace/append). */
    private function assetStreamSave(Asset $asset, bool $isNew): Response
    {
        return $this->render('asset/_stream_save.html.twig', [
            'asset' => $asset,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
    #[Route('/asset/{id}/delete', name: 'app_asset_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, int $id): Response
    {
        // Bypass the tenant filter so inherited-from-parent-tenant assets are
        // also visible to this action; the inheritance check below decides
        // whether to allow or block the deletion.
        $filters = $this->entityManager->getFilters();
        $tenantFilterEnabled = $filters->isEnabled('tenant_filter');
        if ($tenantFilterEnabled) {
            $filters->disable('tenant_filter');
        }
        try {
            $asset = $this->assetRepository->find($id);
        } finally {
            if ($tenantFilterEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        if (!$asset instanceof Asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if asset can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->assetService->canEditAsset($asset, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_delete_inherited', [], 'messages'));
            return $this->redirectToRoute('app_asset_index');
        }

        if ($this->isCsrfTokenValid('delete'.$asset->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($asset);
            $this->entityManager->flush();

            $this->flashSuccess('asset.success.deleted');
        }

        return $this->redirectToRoute('app_asset_index');
    }
    #[Route('/asset/{id}/bcm-insights', name: 'app_asset_bcm_insights', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bcmInsights(int $id): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $asset = $this->assetRepository->find($id);

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
        $processes = $this->businessProcessRepository->findByAsset($id);

        // Check if asset is inherited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->assetService->isInheritedAsset($asset, $tenant);
            $canEdit = $this->assetService->canEditAsset($asset, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        return $this->render('asset/bcm_insights.html.twig', [
            'asset' => $asset,
            'analysis' => $analysis,
            'processes' => $processes,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
    }
    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }

    /**
     * Print a single asset label with QR code
     */
    #[Route('/asset/{id}/qr-label', name: 'app_asset_qr_label', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function printQrLabel(Asset $asset, Request $request): Response
    {
        $locale = $request->getLocale();
        $labelData = $this->assetQrCodeService->generateLabelData($asset, $locale);

        return $this->render('asset/qr_label.html.twig', [
            'label' => $labelData,
            'printDate' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * Print multiple asset labels on a sheet (A4 format)
     */
    #[Route('/asset/qr-labels', name: 'app_asset_qr_labels_bulk', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function printQrLabelsBulk(Request $request): Response
    {
        $ids = $request->query->all('ids');
        $locale = $request->getLocale();

        // If no IDs provided, get all active assets
        if (empty($ids)) {
            $user = $this->security->getUser();
            $tenant = $user?->getTenant();

            if ($tenant) {
                $assets = $this->assetRepository->findBy([
                    'tenant' => $tenant,
                    'status' => AssetStatus::Active->value
                ]);
            } else {
                $assets = $this->assetRepository->findBy(['status' => AssetStatus::Active->value]);
            }
        } else {
            $assets = $this->assetRepository->findBy(['id' => $ids]);
        }

        $labels = $this->assetQrCodeService->generateBulkLabelData($assets, $locale);

        return $this->render('asset/qr_labels_sheet.html.twig', [
            'labels' => $labels,
            'printDate' => new \DateTimeImmutable(),
            'assetsPerPage' => 10, // 2 columns x 5 rows
        ]);
    }

    /**
     * Bulk CSV export of selected assets.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/asset/bulk-export', name: 'app_asset_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        /** @var \App\Entity\User|null $user */
        $user   = $this->security->getUser();
        $tenant = $user instanceof \App\Entity\User ? $user->getTenant() : null;

        $assets = [];
        foreach ($ids as $rawId) {
            $asset = $this->assetRepository->find((int) $rawId);
            if ($asset === null) {
                continue;
            }
            if ($tenant !== null && $asset->getTenant() !== $tenant) {
                continue;
            }
            $assets[] = $asset;
        }

        if ($assets === []) {
            return $this->json(['error' => 'No exportable assets'], 404);
        }

        $headers = ['ID', 'Name', 'Type', 'Status', 'Owner', 'Location', 'Data Classification'];

        return $this->streamCsvExport(
            $assets,
            $headers,
            static function (Asset $a): array {
                return [
                    (string) $a->getId(),
                    (string) $a->getName(),
                    (string) $a->getAssetType(),
                    (string) $a->getStatus(),
                    (string) ($a->getEffectiveOwner() ?? ''),
                    (string) ($a->getEffectiveLocation() ?? $a->getLocation() ?? ''),
                    (string) $a->getDataClassification(),
                ];
            },
            'assets-export',
            'Asset',
            $this->auditLogger,
        );
    }

    /**
     * Bulk assign selected assets to a user (sets ownerUser).
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/asset/bulk-assign', name: 'app_asset_bulk_assign', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkAssign(Request $request): Response
    {
        $data     = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids      = $data['ids'] ?? [];
        $assignId = (int) ($data['assignee_id'] ?? 0);

        if (!is_array($ids) || $ids === [] || $assignId === 0) {
            return $this->json(['error' => 'No items selected or no assignee'], 400);
        }

        /** @var \App\Entity\User|null $user */
        $user   = $this->security->getUser();
        $tenant = $user instanceof \App\Entity\User ? $user->getTenant() : null;

        $assignee = $this->userRepository?->find($assignId);
        if (!$assignee instanceof \App\Entity\User) {
            return $this->json(['error' => 'Assignee not found'], 404);
        }
        if ($tenant !== null && $assignee->getTenant() !== $tenant) {
            return $this->json(['error' => 'Assignee tenant mismatch'], 403);
        }

        $assets = [];
        foreach ($ids as $rawId) {
            $asset = $this->assetRepository->find((int) $rawId);
            if ($asset === null) {
                continue;
            }
            if ($tenant !== null && $asset->getTenant() !== $tenant) {
                continue;
            }
            $assets[] = $asset;
        }

        $result = $this->applyBulkAssign(
            $assets,
            static function (Asset $a, \App\Entity\User $u): void { $a->setOwnerUser($u); },
            $assignee,
            'Asset',
            $this->auditLogger,
        );

        if ($result['changed'] > 0) {
            $this->entityManager->flush();
        }

        return $this->json($result);
    }
}
