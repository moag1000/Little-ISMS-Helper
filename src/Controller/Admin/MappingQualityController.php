<?php

namespace App\Controller\Admin;

use App\Entity\ComplianceFramework;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\MappingLifecycleService;
use App\Service\MappingQualityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly MappingQualityScoreService $mqsService,
        private readonly MappingLifecycleService $lifecycleService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
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
            'allowedTransitions' => $this->lifecycleService->allowedNextStates($mapping->getLifecycleState()),
        ]);
    }

    #[Route('/admin/mapping-quality/{id}/transition', name: 'admin_mapping_quality_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('mapping_lifecycle_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_mapping_quality_show', ['id' => $id]);
        }
        $mapping = $this->mappingRepository->find($id);
        if (!$mapping) {
            throw $this->createNotFoundException();
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $newState = (string) $request->request->get('to');
        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $this->lifecycleService->transition($mapping, $newState, $actor, $reason !== '' ? $reason : null);
            $this->addFlash('success', $this->translator->trans(
                'admin.mapping_quality.transition_success',
                ['%state%' => $newState],
                'admin',
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_mapping_quality_show', ['id' => $id]);
    }

    #[Route('/admin/mapping-quality/recompute', name: 'admin_mapping_quality_recompute', methods: ['POST'])]
    #[IsCsrfTokenValid('mapping_quality_recompute', tokenKey: '_token')]
    public function recompute(): Response
    {
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

    /**
     * CISO-Coverage-Übersicht: Aggregat-Tabelle pro Framework-Paar mit
     * Coverage % und Confidence-Verteilung. Beantwortet die Board-Frage:
     * "Wie viel von Framework Y ist durch Framework X abgedeckt — und wie
     * sicher sind wir uns dabei?"
     */
    #[Route('/admin/mapping-quality/coverage/all', name: 'admin_mapping_quality_coverage')]
    public function coverage(): Response
    {
        $frameworks = $this->frameworkRepository->findAll();
        $rows = [];

        foreach ($frameworks as $source) {
            foreach ($frameworks as $target) {
                if ($source === $target) {
                    continue;
                }
                $stats = $this->mappingRepository->coverageBetweenFrameworks($source, $target);
                if ($stats['source_with_mapping'] === 0) {
                    continue;  // Keine Mappings → nicht in Liste
                }
                $confidenceCounts = $this->confidenceDistribution($source, $target);
                $sourceTotal = (int) $stats['source_total'];
                $covered = (int) $stats['source_with_mapping'];
                $coveragePct = $sourceTotal > 0 ? ($covered / $sourceTotal) * 100 : 0;

                $rows[] = [
                    'source' => $source,
                    'target' => $target,
                    'source_total' => $sourceTotal,
                    'covered' => $covered,
                    'unmapped' => $sourceTotal - $covered,
                    'coverage_pct' => round($coveragePct, 1),
                    'high' => $confidenceCounts['high'],
                    'medium' => $confidenceCounts['medium'],
                    'low' => $confidenceCounts['low'],
                ];
            }
        }
        usort($rows, static fn(array $a, array $b) => $b['coverage_pct'] <=> $a['coverage_pct']);

        return $this->render('admin/mapping_quality/coverage.html.twig', ['rows' => $rows]);
    }

    /**
     * @return array{high: int, medium: int, low: int}
     */
    private function confidenceDistribution(ComplianceFramework $source, ComplianceFramework $target): array
    {
        $rows = $this->mappingRepository->createQueryBuilder('m')
            ->select('m.confidence AS c, COUNT(m.id) AS n')
            ->join('m.sourceRequirement', 'sr')
            ->join('m.targetRequirement', 'tr')
            ->where('sr.complianceFramework = :s AND tr.complianceFramework = :t')
            ->andWhere("m.lifecycleState != 'deprecated'")
            ->groupBy('m.confidence')
            ->setParameter('s', $source)
            ->setParameter('t', $target)
            ->getQuery()->getArrayResult();

        $out = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($rows as $r) {
            $key = (string) $r['c'];
            if (isset($out[$key])) {
                $out[$key] = (int) $r['n'];
            }
        }
        return $out;
    }
}
