<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\ComplianceMapping;
use Exception;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ComplianceMappingAdminController
 *
 * Handles automated cross-framework mapping creation operations:
 * - Create comparison mappings between two selected frameworks
 * - Create all cross-framework mappings via ISO 27001 transitive strategy
 *
 * Extracted from ComplianceController god-class (was 2629 LOC).
 */
class ComplianceMappingAdminController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingRepository $complianceMappingRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'compliance';
    }

    protected function getTranslator(): TranslatorInterface
    {
        if ($this->translator === null) {
            throw new \RuntimeException('TranslatorInterface not injected — flash methods unavailable.');
        }
        return $this->translator;
    }

    #[Route('/compliance/frameworks/create-comparison-mappings', name: 'app_compliance_create_comparison_mappings', methods: ['POST'])]
    public function createComparisonMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $framework1Id = $data['framework1_id'] ?? null;
            $framework2Id = $data['framework2_id'] ?? null;

            if (!$framework1Id || !$framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Beide Framework IDs müssen angegeben werden!'
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($framework1Id === $framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Die beiden Frameworks müssen unterschiedlich sein!'
                ], Response::HTTP_BAD_REQUEST);
            }

            $em = $this->complianceFrameworkRepository->getEntityManager();

            // Load frameworks
            $framework1 = $this->complianceFrameworkRepository->find($framework1Id);
            $framework2 = $this->complianceFrameworkRepository->find($framework2Id);

            if (!$framework1 || !$framework2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Ein oder beide Frameworks wurden nicht gefunden!'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if ISO 27001 exists (needed for transitive mappings)
            $iso27001 = $this->complianceFrameworkRepository->findOneBy(['code' => 'ISO27001']);

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->complianceMappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = [];

            // Get requirements for both frameworks
            $requirements1 = $this->complianceRequirementRepository->findBy(['framework' => $framework1]);
            $requirements2 = $this->complianceRequirementRepository->findBy(['framework' => $framework2]);

            // Strategy 1: Direct mapping via ISO controls if available
            if ($iso27001) {
                // Build a map of ISO control IDs to requirements for both frameworks
                $framework1IsoMap = [];
                $framework2IsoMap = [];

                foreach ($requirements1 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $isoControl) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);
                            if (!isset($framework1IsoMap[$normalizedId])) {
                                $framework1IsoMap[$normalizedId] = [];
                            }
                            $framework1IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                foreach ($requirements2 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $isoControl) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);
                            if (!isset($framework2IsoMap[$normalizedId])) {
                                $framework2IsoMap[$normalizedId] = [];
                            }
                            $framework2IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                // Create mappings for requirements sharing the same ISO control
                foreach ($framework1IsoMap as $isoControl => $reqs1) {
                    if (isset($framework2IsoMap[$isoControl])) {
                        $reqs2 = $framework2IsoMap[$isoControl];

                        foreach ($reqs1 as $req1) {
                            foreach ($reqs2 as $req2) {
                                $pairKey = $req1->getId() . '-' . $req2->getId();
                                $reversePairKey = $req2->getId() . '-' . $req1->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($req1)
                                        ->setTargetRequirement($req2)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($req2)
                                        ->setTargetRequirement($req1)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($reverseMapping);
                                    $mappingsCreated++;
                                    $createdPairs[$reversePairKey] = true;
                                } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                                    $mappingsSkipped += 2;
                                }
                            }
                        }
                    }
                }

                // Strategy 2: Direct mapping when one framework IS ISO 27001
                $isFramework1Iso = $framework1->getCode() === 'ISO27001';
                $isFramework2Iso = $framework2->getCode() === 'ISO27001';

                if ($isFramework1Iso || $isFramework2Iso) {
                    // Determine which is ISO and which has iso_controls
                    $isoFramework = $isFramework1Iso ? $framework1 : $framework2;
                    $otherFramework = $isFramework1Iso ? $framework2 : $framework1;
                    $isoRequirements = $isFramework1Iso ? $requirements1 : $requirements2;
                    $otherRequirements = $isFramework1Iso ? $requirements2 : $requirements1;

                    // Build map of other framework's requirements by ISO control
                    $otherByIsoControl = [];
                    foreach ($otherRequirements as $otherRequirement) {
                        $dataSourceMapping = $otherRequirement->getDataSourceMapping();
                        if (!empty($dataSourceMapping['iso_controls'])) {
                            $isoControls = is_array($dataSourceMapping['iso_controls'])
                                ? $dataSourceMapping['iso_controls']
                                : [$dataSourceMapping['iso_controls']];

                            foreach ($isoControls as $isoControl) {
                                $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);
                                if (!isset($otherByIsoControl[$normalizedId])) {
                                    $otherByIsoControl[$normalizedId] = [];
                                }
                                $otherByIsoControl[$normalizedId][] = $otherRequirement;
                            }
                        }
                    }

                    // Map ISO requirements directly to other framework requirements
                    foreach ($isoRequirements as $isoRequirement) {
                        $isoControlId = $isoRequirement->getRequirementId(); // e.g., 'A.5.1'

                        if (isset($otherByIsoControl[$isoControlId])) {
                            $otherReqs = $otherByIsoControl[$isoControlId];

                            foreach ($otherReqs as $otherReq) {
                                $pairKey = $isoRequirement->getId() . '-' . $otherReq->getId();
                                $reversePairKey = $otherReq->getId() . '-' . $isoRequirement->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping: ISO → Other
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($isoRequirement)
                                        ->setTargetRequirement($otherReq)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: ISO 27001 %s to %s requirement',
                                            $isoControlId,
                                            $otherFramework->getName()
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping: Other → ISO
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($otherReq)
                                        ->setTargetRequirement($isoRequirement)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: %s requirement to ISO 27001 %s',
                                            $otherFramework->getName(),
                                            $isoControlId
                                        ));

                                    $em->persist($reverseMapping);
                                    $mappingsCreated++;
                                    $createdPairs[$reversePairKey] = true;
                                } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                                    $mappingsSkipped += 2;
                                }
                            }
                        }
                    }
                }
            }

            $em->flush();

            $message = $this->getTranslator()->trans('compliance.auto_mapping.success', [
                '%count%' => $mappingsCreated,
                '%framework1%' => $framework1->getName(),
                '%framework2%' => $framework2->getName(),
            ], 'compliance');
            if ($mappingsSkipped > 0) {
                $message .= ' ' . $this->getTranslator()->trans('compliance.auto_mapping.skipped_suffix', [
                    '%count%' => $mappingsSkipped,
                ], 'compliance');
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'framework1' => $framework1->getName(),
                'framework2' => $framework2->getName(),
            ]);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->getTranslator()->trans('compliance.auto_mapping.error', [
                    '%reason%' => $e->getMessage(),
                ], 'compliance'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/compliance/frameworks/create-mappings', name: 'app_compliance_create_mappings', methods: ['POST'])]
    public function createCrossFrameworkMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            // Get batch parameters for chunking
            $data = json_decode($request->getContent(), true) ?? [];
            $currentBatch = $data['batch'] ?? 0;
            $batchSize = $data['batch_size'] ?? 50; // Process 50 mappings per batch

            $em = $this->complianceFrameworkRepository->getEntityManager();

            // Check if ISO 27001 exists
            $iso27001 = $this->complianceFrameworkRepository->findOneBy(['code' => 'ISO27001']);
            if (!$iso27001) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'ISO 27001 Framework muss zuerst geladen werden!'
                ]);
            }

            // Get all frameworks
            $frameworks = $this->complianceFrameworkRepository->findAll();
            if (count($frameworks) < 2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Mindestens 2 Frameworks müssen geladen sein!'
                ]);
            }

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->complianceMappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = []; // Track created mapping pairs to avoid duplicates
            $potentialMappings = []; // Collect all potential mappings first

            // 1. Collect potential mappings FROM other frameworks TO ISO 27001
            foreach ($frameworks as $framework) {
                if ($framework->getCode() === 'ISO27001') {
                    continue;
                }

                $requirements = $this->complianceRequirementRepository->findBy(['framework' => $framework]);

                foreach ($requirements as $requirement) {
                    $dataSourceMapping = $requirement->getDataSourceMapping();
                    if (empty($dataSourceMapping)) {
                        continue;
                    }
                    if (empty($dataSourceMapping['iso_controls'])) {
                        continue;
                    }

                    $isoControls = $dataSourceMapping['iso_controls'];
                    if (!is_array($isoControls)) {
                        $isoControls = [$isoControls];
                    }

                    foreach ($isoControls as $isoControl) {
                        $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);

                        $isoRequirement = $this->complianceRequirementRepository->findOneBy([
                            'framework' => $iso27001,
                            'requirementId' => $normalizedId
                        ]);

                        if ($isoRequirement) {
                            $pairKey = $requirement->getId() . '-' . $isoRequirement->getId();
                            $reversePairKey = $isoRequirement->getId() . '-' . $requirement->getId();

                            if (!isset($existingPairs[$pairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'forward',
                                    'source' => $requirement,
                                    'target' => $isoRequirement,
                                    'pairKey' => $pairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }

                            if (!isset($existingPairs[$reversePairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'reverse',
                                    'source' => $isoRequirement,
                                    'target' => $requirement,
                                    'pairKey' => $reversePairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }
                        }
                    }
                }
            }

            // 2. Collect transitive mappings between non-ISO frameworks
            // If Framework A → ISO Control X and Framework B → ISO Control X, then A ↔ B
            $isoRequirements = $this->complianceRequirementRepository->findBy(['framework' => $iso27001]);

            foreach ($isoRequirements as $isoRequirement) {
                // Find all frameworks that map to this ISO requirement
                $mappedToThisISO = [];

                foreach ($frameworks as $framework) {
                    if ($framework->getCode() === 'ISO27001') {
                        continue;
                    }

                    $requirements = $this->complianceRequirementRepository->findBy(['framework' => $framework]);

                    foreach ($requirements as $requirement) {
                        $dataSourceMapping = $requirement->getDataSourceMapping();
                        if (empty($dataSourceMapping)) {
                            continue;
                        }
                        if (empty($dataSourceMapping['iso_controls'])) {
                            continue;
                        }

                        $isoControls = $dataSourceMapping['iso_controls'];
                        if (!is_array($isoControls)) {
                            $isoControls = [$isoControls];
                        }

                        foreach ($isoControls as $isoControl) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);

                            if ($normalizedId === $isoRequirement->getRequirementId()) {
                                $mappedToThisISO[] = $requirement;
                            }
                        }
                    }
                }
                // Collect cross-mappings between all requirements that map to same ISO control
                $counter = count($mappedToThisISO);

                // Collect cross-mappings between all requirements that map to same ISO control
                for ($i = 0; $i < $counter; $i++) {
                    for ($j = $i + 1; $j < count($mappedToThisISO); $j++) {
                        $req1 = $mappedToThisISO[$i];
                        $req2 = $mappedToThisISO[$j];

                        $pairKey = $req1->getId() . '-' . $req2->getId();
                        $reversePairKey = $req2->getId() . '-' . $req1->getId();

                        if (!isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {
                            $potentialMappings[] = [
                                'type' => 'transitive_forward',
                                'source' => $req1,
                                'target' => $req2,
                                'pairKey' => $pairKey,
                                'isoControl' => $isoRequirement->getRequirementId()
                            ];

                            $potentialMappings[] = [
                                'type' => 'transitive_reverse',
                                'source' => $req2,
                                'target' => $req1,
                                'pairKey' => $reversePairKey,
                                'isoControl' => $isoRequirement->getRequirementId()
                            ];
                        }
                    }
                }
            }

            // 3. Process mappings in batches to avoid timeouts
            $totalPotentialMappings = count($potentialMappings);
            $startIndex = $currentBatch * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalPotentialMappings);
            $hasMore = $endIndex < $totalPotentialMappings;

            // Process only the current batch
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $mappingData = $potentialMappings[$i];

                // Skip if already created in this session
                if (isset($createdPairs[$mappingData['pairKey']])) {
                    $mappingsSkipped++;
                    continue;
                }

                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($mappingData['source'])
                    ->setTargetRequirement($mappingData['target'])
                    ->setBidirectional(true);

                // Set type-specific properties
                if (str_starts_with($mappingData['type'], 'transitive')) {
                    $mapping->setMappingPercentage(75)
                        ->setMappingType('partial')
                        ->setConfidence('medium')
                        ->setMappingRationale(sprintf(
                            'Transitive mapping via ISO 27001 %s',
                            $mappingData['isoControl']
                        ));
                } else {
                    $mapping->setMappingPercentage(85)
                        ->setMappingType('partial')
                        ->setConfidence('high')
                        ->setMappingRationale(sprintf(
                            '%s requirement mapped to ISO 27001 %s',
                            $mappingData['framework']->getCode(),
                            $mappingData['controlId']
                        ));
                }

                $em->persist($mapping);
                $mappingsCreated++;
                $createdPairs[$mappingData['pairKey']] = true;
            }

            $em->flush();
            $em->clear(); // Clear entity manager to free memory

            // Debug info
            $frameworkCounts = [];
            foreach ($frameworks as $framework) {
                $reqCount = count($this->complianceRequirementRepository->findBy(['framework' => $framework]));
                $frameworkCounts[$framework->getCode()] = $reqCount;
            }

            $processedSoFar = min($endIndex, $totalPotentialMappings);
            $remaining = max(0, $totalPotentialMappings - $processedSoFar);
            $progressPercent = $totalPotentialMappings > 0
                ? round(($processedSoFar / $totalPotentialMappings) * 100, 1)
                : 100;

            $message = sprintf(
                'Batch %d: %d Mappings erstellt',
                $currentBatch + 1,
                $mappingsCreated
            );
            if ($hasMore) {
                $message .= sprintf(' (%d%% - %d/%d verarbeitet, %d verbleibend)',
                    $progressPercent,
                    $processedSoFar,
                    $totalPotentialMappings,
                    $remaining
                );
            } else {
                $message .= ' - Alle Mappings erstellt!';
            }

            // Additional debug info to help diagnose mapping issues
            $debugInfo = [
                'frameworks_loaded' => count($frameworks),
                'framework_details' => $frameworkCounts,
                'batch_info' => [
                    'current_batch' => $currentBatch,
                    'batch_size' => $batchSize,
                    'total_potential_mappings' => $totalPotentialMappings,
                    'processed_so_far' => $processedSoFar,
                    'remaining' => $remaining,
                    'progress_percent' => $progressPercent,
                    'has_more' => $hasMore,
                    'next_batch' => $hasMore ? $currentBatch + 1 : null,
                ],
            ];

            // Check if TISAX exists and has ISO controls
            $tisax = $this->complianceFrameworkRepository->findOneBy(['code' => 'TISAX']);
            if ($tisax) {
                $tisaxReqs = $this->complianceRequirementRepository->findBy(['framework' => $tisax]);
                $tisaxWithIsoControls = 0;
                $sampleIsoControls = [];

                foreach ($tisaxReqs as $tisaxReq) {
                    $dataSourceMapping = $tisaxReq->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $tisaxWithIsoControls++;
                        if (count($sampleIsoControls) < 5) {
                            $sampleIsoControls[] = [
                                'requirement_id' => $tisaxReq->getRequirementId(),
                                'iso_controls' => $dataSourceMapping['iso_controls']
                            ];
                        }
                    }
                }

                $debugInfo['tisax'] = [
                    'total_requirements' => count($tisaxReqs),
                    'with_iso_controls' => $tisaxWithIsoControls,
                    'sample_iso_controls' => $sampleIsoControls,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'mappings_total' => $mappingsCreated + $mappingsSkipped,
                'has_more' => $hasMore,
                'next_batch' => $hasMore ? $currentBatch + 1 : null,
                'progress' => [
                    'total' => $totalPotentialMappings,
                    'processed' => $processedSoFar,
                    'remaining' => $remaining,
                    'percent' => $progressPercent,
                ],
                'debug' => $debugInfo
            ]);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen der Mappings: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
