<?php

namespace App\Command;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Service\MappingQualityAnalysisService;
use App\Service\AutomatedGapAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(
    name: 'app:analyze-mapping-quality',
    description: 'Analyze compliance mapping quality using automated text analysis and similarity algorithms'
)]
class AnalyzeMappingQualityCommand extends Command
{
    private const BATCH_SIZE = 50;
    private const LOW_CONFIDENCE_THRESHOLD = 70;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceMappingRepository $mappingRepository,
        private MappingQualityAnalysisService $qualityAnalysisService,
        private AutomatedGapAnalysisService $gapAnalysisService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of mappings to analyze')
            ->addOption('reanalyze', 'r', InputOption::VALUE_NONE, 'Re-analyze all mappings (including already analyzed)')
            ->addOption('framework', 'f', InputOption::VALUE_OPTIONAL, 'Analyze only mappings for specific framework code')
            ->addOption('low-quality', null, InputOption::VALUE_NONE, 'Only analyze mappings with current low quality scores')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run analysis without saving results');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Compliance Mapping Quality Analysis');

        $limit = $input->getOption('limit');
        $reanalyze = $input->getOption('reanalyze');
        $frameworkCode = $input->getOption('framework');
        $lowQualityOnly = $input->getOption('low-quality');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode - no changes will be saved');
        }

        // Get mappings to analyze
        $mappings = $this->getMappingsToAnalyze($reanalyze, $frameworkCode, $lowQualityOnly, $limit);
        $totalMappings = count($mappings);

        if ($totalMappings === 0) {
            $io->success('No mappings found to analyze.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Analyzing %d compliance mappings', $totalMappings));

        $progressBar = new ProgressBar($output, $totalMappings);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $statistics = [
            'analyzed' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'requires_review' => 0,
            'gaps_identified' => 0,
            'total_gap_items' => 0,
            'improved' => 0,
            'degraded' => 0,
            'errors' => 0,
        ];

        $batchCount = 0;

        foreach ($mappings as $mapping) {
            try {
                $oldPercentage = $mapping->getCalculatedPercentage();
                $oldConfidence = $mapping->getAnalysisConfidence();

                // Analyze quality
                $analysisResults = $this->qualityAnalysisService->analyzeMappingQuality($mapping);

                // Apply results to mapping
                $mapping->setCalculatedPercentage($analysisResults['calculated_percentage']);
                $mapping->setTextualSimilarity($analysisResults['textual_similarity']);
                $mapping->setKeywordOverlap($analysisResults['keyword_overlap']);
                $mapping->setStructuralSimilarity($analysisResults['structural_similarity']);
                $mapping->setAnalysisConfidence($analysisResults['analysis_confidence']);
                $mapping->setQualityScore($analysisResults['quality_score']);
                $mapping->setAnalysisAlgorithmVersion($analysisResults['algorithm_version']);
                $mapping->setRequiresReview($analysisResults['requires_review']);

                // Remove old gap items if reanalyzing
                if ($reanalyze) {
                    foreach ($mapping->getGapItems() as $oldGap) {
                        $this->entityManager->remove($oldGap);
                    }
                    $this->entityManager->flush();
                }

                // Analyze gaps
                $gapItems = $this->gapAnalysisService->analyzeGaps($mapping, $analysisResults);

                if (!$dryRun) {
                    foreach ($gapItems as $gapItem) {
                        $mapping->addGapItem($gapItem);
                        $this->entityManager->persist($gapItem);
                    }

                    $this->entityManager->persist($mapping);
                }

                // Update statistics
                $statistics['analyzed']++;
                $statistics['total_gap_items'] += count($gapItems);

                if (count($gapItems) > 0) {
                    $statistics['gaps_identified']++;
                }

                $confidence = $analysisResults['analysis_confidence'];
                if ($confidence >= 80) {
                    $statistics['high_confidence']++;
                } elseif ($confidence >= 60) {
                    $statistics['medium_confidence']++;
                } else {
                    $statistics['low_confidence']++;
                }

                if ($analysisResults['requires_review']) {
                    $statistics['requires_review']++;
                }

                // Track improvements/degradations
                if ($oldPercentage !== null) {
                    if ($analysisResults['calculated_percentage'] > $oldPercentage) {
                        $statistics['improved']++;
                    } elseif ($analysisResults['calculated_percentage'] < $oldPercentage) {
                        $statistics['degraded']++;
                    }
                }

                // Flush in batches
                $batchCount++;
                if ($batchCount % self::BATCH_SIZE === 0 && !$dryRun) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }

            } catch (\Exception $e) {
                $statistics['errors']++;
                $io->error(sprintf(
                    'Error analyzing mapping %d: %s',
                    $mapping->getId(),
                    $e->getMessage()
                ));
            }

            $progressBar->advance();
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Display results
        $this->displayResults($io, $statistics, $dryRun);

        // Display recommendations
        $this->displayRecommendations($io, $statistics, $totalMappings);

        return Command::SUCCESS;
    }

    /**
     * Get mappings to analyze based on options
     */
    private function getMappingsToAnalyze(
        bool $reanalyze,
        ?string $frameworkCode,
        bool $lowQualityOnly,
        ?int $limit
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('cm')
            ->from(ComplianceMapping::class, 'cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr');

        // Filter by framework if specified
        if ($frameworkCode) {
            $qb->join('sr.framework', 'sf')
                ->andWhere('sf.code = :frameworkCode')
                ->setParameter('frameworkCode', $frameworkCode);
        }

        // Filter by analysis status
        if (!$reanalyze) {
            $qb->andWhere('cm.calculatedPercentage IS NULL OR cm.analysisConfidence IS NULL');
        }

        // Filter by quality
        if ($lowQualityOnly) {
            $qb->andWhere('cm.qualityScore < 60 OR cm.qualityScore IS NULL');
        }

        // Apply limit
        if ($limit) {
            $qb->setMaxResults((int) $limit);
        }

        // Order by priority (unanalyzed first, then low quality)
        $qb->orderBy('cm.analysisConfidence', 'ASC')
            ->addOrderBy('cm.qualityScore', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Display analysis results
     */
    private function displayResults(SymfonyStyle $io, array $statistics, bool $dryRun): void
    {
        $io->section('Analysis Results');

        $rows = [
            ['Total Analyzed', $statistics['analyzed']],
            ['Mappings with Gaps', sprintf('%d (%.1f%%)',
                $statistics['gaps_identified'],
                $statistics['analyzed'] > 0 ? ($statistics['gaps_identified'] / $statistics['analyzed'] * 100) : 0
            )],
            ['Total Gap Items Created', $statistics['total_gap_items']],
            ['', ''],
            ['High Confidence (≥80)', sprintf('%d (%.1f%%)',
                $statistics['high_confidence'],
                $statistics['analyzed'] > 0 ? ($statistics['high_confidence'] / $statistics['analyzed'] * 100) : 0
            )],
            ['Medium Confidence (60-79)', sprintf('%d (%.1f%%)',
                $statistics['medium_confidence'],
                $statistics['analyzed'] > 0 ? ($statistics['medium_confidence'] / $statistics['analyzed'] * 100) : 0
            )],
            ['Low Confidence (<60)', sprintf('%d (%.1f%%)',
                $statistics['low_confidence'],
                $statistics['analyzed'] > 0 ? ($statistics['low_confidence'] / $statistics['analyzed'] * 100) : 0
            )],
            ['Requires Manual Review', sprintf('%d (%.1f%%)',
                $statistics['requires_review'],
                $statistics['analyzed'] > 0 ? ($statistics['requires_review'] / $statistics['analyzed'] * 100) : 0
            )],
            ['', ''],
            ['Improved Percentages', $statistics['improved']],
            ['Degraded Percentages', $statistics['degraded']],
            ['Errors', $statistics['errors']],
        ];

        $io->table(['Metric', 'Value'], $rows);

        if ($dryRun) {
            $io->warning('DRY-RUN mode: No changes were saved to the database');
        } else {
            $io->success('Analysis complete! Results have been saved to the database.');
        }
    }

    /**
     * Display recommendations based on results
     */
    private function displayRecommendations(SymfonyStyle $io, array $statistics, int $totalMappings): void
    {
        $io->section('Recommendations');

        $recommendations = [];

        // Low confidence mappings
        if ($statistics['low_confidence'] > 0) {
            $recommendations[] = sprintf(
                '⚠️  %d mappings have low confidence (<60). These should be manually reviewed.',
                $statistics['low_confidence']
            );
        }

        // Requires review
        if ($statistics['requires_review'] > 0) {
            $recommendations[] = sprintf(
                '📋 %d mappings are flagged for manual review. Use the review interface to validate these.',
                $statistics['requires_review']
            );
        }

        // Gaps identified
        if ($statistics['gaps_identified'] > 0) {
            $avgGapsPerMapping = $statistics['total_gap_items'] / $statistics['gaps_identified'];
            $recommendations[] = sprintf(
                '🔍 %d mappings have identified gaps (avg %.1f gaps per mapping). Review gap details for remediation actions.',
                $statistics['gaps_identified'],
                $avgGapsPerMapping
            );
        }

        // High error rate
        if ($statistics['errors'] > 0) {
            $errorRate = ($statistics['errors'] / $totalMappings) * 100;
            if ($errorRate > 5) {
                $recommendations[] = sprintf(
                    '❌ High error rate detected (%.1f%%). Check logs for details.',
                    $errorRate
                );
            }
        }

        // Positive feedback
        if ($statistics['high_confidence'] > ($statistics['analyzed'] * 0.6)) {
            $recommendations[] = sprintf(
                '✅ Good quality! %d%% of mappings have high confidence.',
                (int) (($statistics['high_confidence'] / $statistics['analyzed']) * 100)
            );
        }

        if (empty($recommendations)) {
            $io->success('All mappings analyzed successfully with no major issues!');
        } else {
            foreach ($recommendations as $recommendation) {
                $io->writeln($recommendation);
            }
        }

        $io->newLine();
        $io->note([
            'Next steps:',
            '1. Review low-confidence mappings in the web interface',
            '2. Address identified gaps with highest priority first',
            '3. Run this command periodically to keep analysis up-to-date',
        ]);
    }
}
