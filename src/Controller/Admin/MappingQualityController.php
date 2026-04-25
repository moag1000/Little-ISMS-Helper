<?php

namespace App\Controller\Admin;

use App\Repository\ComplianceMappingRepository;
use App\Service\MappingQualityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mapping-Quality-Dashboard.
 * Übersicht aller Cross-Framework-Mappings mit MQS-Score, Lifecycle-State,
 * Coverage und Confidence-Verteilung. Filter nach State, Score-Range, Framework.
 */
#[IsGranted('ROLE_ADMIN')]
class MappingQualityController extends AbstractController
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly MappingQualityScoreService $mqsService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/mapping-quality', name: 'admin_mapping_quality_index')]
    public function index(Request $request): Response
    {
        $stateFilter = $request->query->get('state');
        $minScore = (int) $request->query->get('min_score', 0);

        $qb = $this->mappingRepository->createQueryBuilder('m')
            ->orderBy('m.qualityScore', 'DESC');
        if ($stateFilter !== null && $stateFilter !== '') {
            $qb->andWhere('m.lifecycleState = :s')->setParameter('s', $stateFilter);
        }
        if ($minScore > 0) {
            $qb->andWhere('m.qualityScore >= :score')->setParameter('score', $minScore);
        }
        $mappings = $qb->setMaxResults(200)->getQuery()->getResult();

        // Aggregat-Statistiken
        $stats = $this->computeStats();

        return $this->render('admin/mapping_quality/index.html.twig', [
            'mappings' => $mappings,
            'stats' => $stats,
            'filterState' => $stateFilter,
            'filterMinScore' => $minScore,
            'lifecycleStates' => ['draft', 'review', 'approved', 'published', 'deprecated'],
        ]);
    }

    #[Route('/admin/mapping-quality/{id}', name: 'admin_mapping_quality_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $mapping = $this->mappingRepository->find($id);
        if (!$mapping) {
            throw $this->createNotFoundException();
        }
        // Live-Recompute beim Öffnen → Score reflektiert aktuellen DB-State
        $this->mqsService->compute($mapping);
        $this->entityManager->flush();

        return $this->render('admin/mapping_quality/show.html.twig', [
            'mapping' => $mapping,
        ]);
    }

    #[Route('/admin/mapping-quality/recompute', name: 'admin_mapping_quality_recompute', methods: ['POST'])]
    public function recompute(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mapping_quality_recompute', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_mapping_quality_index');
        }
        $count = 0;
        foreach ($this->mappingRepository->findAll() as $mapping) {
            $this->mqsService->compute($mapping);
            $count++;
        }
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%d Mapping-MQS-Scores neu berechnet.', $count));
        return $this->redirectToRoute('admin_mapping_quality_index');
    }

    /**
     * @return array{total: int, avg_mqs: float, by_state: array<string, int>, low_score: int}
     */
    private function computeStats(): array
    {
        $rows = $this->mappingRepository->createQueryBuilder('m')
            ->select('m.lifecycleState AS state, COUNT(m.id) AS c, AVG(m.qualityScore) AS avg_score')
            ->groupBy('m.lifecycleState')
            ->getQuery()->getArrayResult();

        $byState = [];
        $total = 0;
        $weightedAvg = 0.0;
        foreach ($rows as $r) {
            $byState[$r['state']] = (int) $r['c'];
            $total += (int) $r['c'];
            $weightedAvg += (float) $r['avg_score'] * (int) $r['c'];
        }
        $avgMqs = $total > 0 ? $weightedAvg / $total : 0.0;

        $lowScore = (int) $this->mappingRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.qualityScore < 60')
            ->andWhere("m.lifecycleState = 'published'")
            ->getQuery()->getSingleScalarResult();

        return [
            'total' => $total,
            'avg_mqs' => round($avgMqs, 1),
            'by_state' => $byState,
            'low_score_published' => $lowScore,
        ];
    }
}
