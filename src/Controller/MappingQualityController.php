<?php

namespace App\Controller;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Repository\MappingGapItemRepository;
use App\Service\MappingQualityAnalysisService;
use App\Service\AutomatedGapAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance/mapping-quality')]
#[IsGranted('ROLE_USER')]
class MappingQualityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceMappingRepository $mappingRepository,
        private MappingGapItemRepository $gapItemRepository,
        private MappingQualityAnalysisService $qualityAnalysisService,
        private AutomatedGapAnalysisService $gapAnalysisService
    ) {}

    /**
     * Dashboard showing mapping quality overview
     */
    #[Route('/', name: 'app_mapping_quality_dashboard')]
    public function dashboard(): Response
    {
        $qualityStats = $this->mappingRepository->getQualityStatistics();
        $qualityDistribution = $this->mappingRepository->getQualityDistribution();
        $similarityDistribution = $this->mappingRepository->getSimilarityDistribution();
        $gapStats = $this->gapItemRepository->getGapStatisticsByPriority();
        $frameworkComparison = $this->mappingRepository->getFrameworkQualityComparison();

        return $this->render('compliance/mapping_quality/dashboard.html.twig', [
            'quality_stats' => $qualityStats,
            'quality_distribution' => $qualityDistribution,
            'similarity_distribution' => $similarityDistribution,
            'gap_stats' => $gapStats,
            'framework_comparison' => $frameworkComparison,
        ]);
    }

    /**
     * List mappings requiring review
     */
    #[Route('/review-queue', name: 'app_mapping_quality_review_queue')]
    public function reviewQueue(): Response
    {
        $mappingsRequiringReview = $this->mappingRepository->findMappingsRequiringReview();
        $lowConfidenceMappings = $this->mappingRepository->findLowConfidenceMappings(70);
        $discrepancies = $this->mappingRepository->findMappingsWithDiscrepancies(20);

        return $this->render('compliance/mapping_quality/review_queue.html.twig', [
            'mappings_requiring_review' => $mappingsRequiringReview,
            'low_confidence_mappings' => $lowConfidenceMappings,
            'discrepancies' => $discrepancies,
        ]);
    }

    /**
     * Review a specific mapping
     */
    #[Route('/review/{id}', name: 'app_mapping_quality_review', requirements: ['id' => '\d+'])]
    public function review(int $id): Response
    {
        $mapping = $this->mappingRepository->find($id);

        if (!$mapping) {
            throw $this->createNotFoundException('Mapping not found');
        }

        $gapItems = $this->gapItemRepository->findByMapping($mapping);

        // Calculate gap summary
        $gapSummary = $this->gapAnalysisService->getGapSummary($gapItems);

        return $this->render('compliance/mapping_quality/review.html.twig', [
            'mapping' => $mapping,
            'gap_items' => $gapItems,
            'gap_summary' => $gapSummary,
        ]);
    }

    /**
     * Update mapping review status and percentage
     */
    #[Route('/review/{id}/update', name: 'app_mapping_quality_review_update', methods: ['POST'])]
    public function updateReview(int $id, Request $request): JsonResponse
    {
        $mapping = $this->mappingRepository->find($id);

        if (!$mapping) {
            return $this->json(['error' => 'Mapping not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Update review status
        if (isset($data['review_status'])) {
            $mapping->setReviewStatus($data['review_status']);
        }

        // Update manual percentage override
        if (isset($data['manual_percentage'])) {
            $manualPercentage = (int) $data['manual_percentage'];
            $mapping->setManualPercentage($manualPercentage);
            $mapping->setMappingPercentage($manualPercentage); // Update actual percentage
        }

        // Update review notes
        if (isset($data['review_notes'])) {
            $mapping->setReviewNotes($data['review_notes']);
        }

        // Mark as reviewed
        $mapping->setReviewedBy($this->getUser()->getUserIdentifier());
        $mapping->setReviewedAt(new \DateTimeImmutable());
        $mapping->setUpdatedAt(new \DateTimeImmutable());

        // If approved, mark as no longer requiring review
        if ($data['review_status'] === 'approved') {
            $mapping->setRequiresReview(false);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'mapping' => [
                'id' => $mapping->getId(),
                'review_status' => $mapping->getReviewStatus(),
                'final_percentage' => $mapping->getFinalPercentage(),
                'reviewed_by' => $mapping->getReviewedBy(),
            ],
        ]);
    }

    /**
     * Re-analyze a specific mapping
     */
    #[Route('/analyze/{id}', name: 'app_mapping_quality_analyze', methods: ['POST'])]
    public function analyze(int $id): JsonResponse
    {
        $mapping = $this->mappingRepository->find($id);

        if (!$mapping) {
            return $this->json(['error' => 'Mapping not found'], 404);
        }

        try {
            // Run quality analysis
            $analysisResults = $this->qualityAnalysisService->analyzeMappingQuality($mapping);

            // Apply results
            $mapping->setCalculatedPercentage($analysisResults['calculated_percentage']);
            $mapping->setTextualSimilarity($analysisResults['textual_similarity']);
            $mapping->setKeywordOverlap($analysisResults['keyword_overlap']);
            $mapping->setStructuralSimilarity($analysisResults['structural_similarity']);
            $mapping->setAnalysisConfidence($analysisResults['analysis_confidence']);
            $mapping->setQualityScore($analysisResults['quality_score']);
            $mapping->setAnalysisAlgorithmVersion($analysisResults['algorithm_version']);
            $mapping->setRequiresReview($analysisResults['requires_review']);

            // Remove old gap items
            foreach ($mapping->getGapItems() as $oldGap) {
                $this->entityManager->remove($oldGap);
            }
            $this->entityManager->flush();

            // Generate new gap items
            $gapItems = $this->gapAnalysisService->analyzeGaps($mapping, $analysisResults);

            foreach ($gapItems as $gapItem) {
                $mapping->addGapItem($gapItem);
                $this->entityManager->persist($gapItem);
            }

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'analysis' => [
                    'calculated_percentage' => $analysisResults['calculated_percentage'],
                    'confidence' => $analysisResults['analysis_confidence'],
                    'quality_score' => $analysisResults['quality_score'],
                    'requires_review' => $analysisResults['requires_review'],
                    'gap_count' => count($gapItems),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all gaps
     */
    #[Route('/gaps', name: 'app_mapping_quality_gaps')]
    public function gaps(): Response
    {
        $highPriorityGaps = $this->gapItemRepository->findHighPriorityGaps();
        $lowConfidenceGaps = $this->gapItemRepository->findLowConfidenceGaps(60);
        $gapStatsByType = $this->gapItemRepository->getGapStatisticsByType();
        $gapStatsByPriority = $this->gapItemRepository->getGapStatisticsByPriority();
        $remediationEffort = $this->gapItemRepository->calculateTotalRemediationEffort();

        return $this->render('compliance/mapping_quality/gaps.html.twig', [
            'high_priority_gaps' => $highPriorityGaps,
            'low_confidence_gaps' => $lowConfidenceGaps,
            'gap_stats_by_type' => $gapStatsByType,
            'gap_stats_by_priority' => $gapStatsByPriority,
            'remediation_effort' => $remediationEffort,
        ]);
    }

    /**
     * Update gap item status
     */
    #[Route('/gap/{id}/update', name: 'app_mapping_quality_gap_update', methods: ['POST'])]
    public function updateGap(int $id, Request $request): JsonResponse
    {
        $gap = $this->gapItemRepository->find($id);

        if (!$gap) {
            return $this->json(['error' => 'Gap item not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['status'])) {
            $gap->setStatus($data['status']);
        }

        if (isset($data['priority'])) {
            $gap->setPriority($data['priority']);
        }

        if (isset($data['estimated_effort'])) {
            $gap->setEstimatedEffort((int) $data['estimated_effort']);
        }

        $gap->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'gap' => [
                'id' => $gap->getId(),
                'status' => $gap->getStatus(),
                'priority' => $gap->getPriority(),
            ],
        ]);
    }

    /**
     * Export quality report
     */
    #[Route('/export', name: 'app_mapping_quality_export')]
    public function export(): Response
    {
        $qualityStats = $this->mappingRepository->getQualityStatistics();
        $mappingsRequiringReview = $this->mappingRepository->findMappingsRequiringReview();
        $highPriorityGaps = $this->gapItemRepository->findHighPriorityGaps();

        // For now, return JSON (can be extended to PDF/Excel later)
        return $this->json([
            'quality_statistics' => $qualityStats,
            'mappings_requiring_review_count' => count($mappingsRequiringReview),
            'high_priority_gaps_count' => count($highPriorityGaps),
            'export_date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
