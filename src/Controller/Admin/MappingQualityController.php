<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ComplianceMapping;
use App\Entity\User;
use App\Exception\AppException;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\Compliance\MappingAccessGuard;
use App\Service\Compliance\MappingCoverageMatrix;
use App\Service\MappingLifecycleService;
use App\Service\MappingQualityScoreService;
use App\Util\ListPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mapping-Quality-Dashboard.
 * Übersicht aller Cross-Framework-Mappings mit MQS-Score, Lifecycle-State,
 * Coverage und Confidence-Verteilung. Filter nach State, Score-Range, Framework.
 *
 *  - Class-level `ADMIN_OWN_TENANT` lets tenant-admins inspect mappings.
 *  - {@see self::recompute()} bumps to `ADMIN_GLOBAL_OP` because it
 *    iterates EVERY mapping row in the DB (cross-tenant), which only
 *    SUPER_ADMIN is allowed to do.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class MappingQualityController extends AbstractController
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly MappingQualityScoreService $mqsService,
        private readonly MappingLifecycleService $lifecycleService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly MappingAccessGuard $accessGuard,
        private readonly MappingCoverageMatrix $coverageMatrix,
    ) {
    }

    #[Route('/admin/mapping-quality', name: 'admin_mapping_quality_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $stateFilter = $request->query->get('state');
        $minScore = (int) $request->query->get('min_score', 0);

        $qb = $this->mappingRepository->createQueryBuilder('m')
            ->orderBy('m.qualityScore', \SortDirection::Descending);
        if ($stateFilter !== null && $stateFilter !== '') {
            $qb->andWhere('m.lifecycleState = :s')->setParameter('s', $stateFilter);
        }
        if ($minScore > 0) {
            $qb->andWhere('m.qualityScore >= :score')->setParameter('score', $minScore);
        }
        $this->accessGuard->scopeQuery($qb, 'm');
        $mappings = $qb->setMaxResults(200)->getQuery()->getResult();

        return $this->render('admin/mapping_quality/index.html.twig', [
            'page' => ListPage::fromRequest(
                $mappings,
                $request,
                static fn(ComplianceMapping $m): array => [
                    $m->getSourceRequirement()?->getRequirementId(),
                    $m->getTargetRequirement()?->getRequirementId(),
                    $m->getSourceRequirement()?->getFramework()?->getName(),
                    $m->getTargetRequirement()?->getFramework()?->getName(),
                ],
            ),
            'low_score_count' => count(array_filter($mappings, static fn(ComplianceMapping $m): bool => ($m->getQualityScore() ?? 0) < 60)),
            'published_count' => count(array_filter($mappings, static fn(ComplianceMapping $m): bool => $m->getLifecycleState() === 'published')),
            'filterState' => $stateFilter,
            'filterMinScore' => $minScore,
            'lifecycleStates' => ['draft', 'review', 'approved', 'published', 'deprecated'],
        ]);
    }

    #[Route('/admin/mapping-quality/{id}', name: 'admin_mapping_quality_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $mapping = $this->mappingRepository->find($id);
        if (!$mapping || !$this->accessGuard->isVisible($mapping)) {
            throw $this->createNotFoundException();
        }
        // Live score for display only: GET never persists (recompute() does).
        $this->mqsService->compute($mapping);
        $response = $this->render('admin/mapping_quality/show.html.twig', [
            'mapping' => $mapping,
            'allowedTransitions' => $this->lifecycleService->allowedNextStates($mapping->getLifecycleState()),
        ]);
        $this->entityManager->refresh($mapping); // drop the unsaved display values

        return $response;
    }

    #[Route('/admin/mapping-quality/{id}/transition', name: 'admin_mapping_quality_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(
        Request $request,
        int $id,
        #[CurrentUser] User $actor,
    ): Response {
        if (!$this->isCsrfTokenValid('mapping_lifecycle_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'common.csrf_invalid');
            return $this->redirectToRoute('admin_mapping_quality_show', ['id' => $id]);
        }
        $mapping = $this->mappingRepository->find($id);
        if (!$mapping || !$this->accessGuard->isVisible($mapping)) {
            throw $this->createNotFoundException();
        }

        $newState = (string) $request->request->get('to');
        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $this->lifecycleService->transition($mapping, $newState, $actor, $reason !== '' ? $reason : null);
            $this->addFlash('success', $this->translator->trans(
                'admin.mapping_quality.transition_success',
                ['%state%' => $newState],
                'admin',
            ));
        } catch (AppException $e) {
            $this->addFlash('error', $this->translator->trans(
                'admin.mapping_quality.transition_failed',
                ['%message%' => $e->getMessage()],
                'admin',
            ));
        }

        return $this->redirectToRoute('admin_mapping_quality_show', ['id' => $id]);
    }

    #[Route('/admin/mapping-quality/recompute', name: 'admin_mapping_quality_recompute', methods: ['POST'])]
    #[IsCsrfTokenValid('mapping_quality_recompute', tokenKey: '_token')]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
    public function recompute(): Response
    {
        $count = 0;
        // findAllGlobal() is intentional: recomputing MQS is a global admin
        // operation that must cover all mappings across tenants (E-6).
        foreach ($this->mappingRepository->findAllGlobal() as $mapping) {
            $this->mqsService->compute($mapping);
            $count++;
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('admin.mapping_quality.recomputed', ['%count%' => $count], 'admin'));
        return $this->redirectToRoute('admin_mapping_quality_index');
    }

    /**
     * CISO-Coverage-Übersicht: Aggregat-Tabelle pro Framework-Paar mit
     * Coverage % und Confidence-Verteilung. Beantwortet die Board-Frage:
     * "Wie viel von Framework Y ist durch Framework X abgedeckt — und wie
     * sicher sind wir uns dabei?"
     */
    #[Route('/admin/mapping-quality/coverage/all', name: 'admin_mapping_quality_coverage', methods: ['GET'])]
    public function coverage(): Response
    {
        $rows = $this->coverageMatrix->rows(array_values($this->frameworkRepository->findAll()));

        return $this->render('admin/mapping_quality/coverage.html.twig', ['rows' => $rows]);
    }
}
