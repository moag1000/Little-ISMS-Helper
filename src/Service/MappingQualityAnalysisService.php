<?php

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;

/**
 * Service for analyzing compliance mapping quality using text analysis and similarity algorithms
 *
 * This service calculates:
 * - Textual similarity (Jaccard, Cosine)
 * - Keyword overlap
 * - Structural similarity
 * - Overall quality score
 * - Confidence levels
 */
class MappingQualityAnalysisService
{
    private const ALGORITHM_VERSION = '1.0.0';

    // Security and compliance keywords by category
    private const SECURITY_KEYWORDS = [
        'access_control' => ['access', 'authentication', 'authorization', 'identity', 'login', 'password', 'credential', 'privileged', 'least privilege', 'role', 'rbac', 'permission'],
        'encryption' => ['encryption', 'encrypted', 'cryptographic', 'cipher', 'key management', 'tls', 'ssl', 'hash', 'secure communication'],
        'audit' => ['audit', 'logging', 'log', 'monitoring', 'review', 'trail', 'tracking', 'surveillance', 'recording'],
        'data_protection' => ['data protection', 'privacy', 'personal data', 'sensitive', 'confidential', 'classification', 'gdpr', 'pii', 'retention', 'disposal'],
        'network' => ['network', 'firewall', 'segmentation', 'boundary', 'perimeter', 'dmz', 'intrusion', 'ids', 'ips'],
        'incident' => ['incident', 'breach', 'response', 'recovery', 'contingency', 'emergency', 'crisis'],
        'vulnerability' => ['vulnerability', 'patch', 'update', 'scanning', 'assessment', 'penetration test', 'security testing'],
        'backup' => ['backup', 'restore', 'recovery', 'redundancy', 'replication', 'disaster recovery', 'business continuity'],
        'physical' => ['physical', 'facility', 'premises', 'badge', 'cctv', 'surveillance', 'visitor'],
        'policy' => ['policy', 'procedure', 'standard', 'guideline', 'documentation', 'governance'],
        'risk' => ['risk', 'assessment', 'analysis', 'mitigation', 'treatment', 'acceptance', 'threat'],
        'compliance' => ['compliance', 'regulation', 'requirement', 'legal', 'statutory', 'mandatory'],
        'training' => ['training', 'awareness', 'education', 'competence', 'qualification'],
        'supplier' => ['supplier', 'vendor', 'third party', 'outsourcing', 'contractor', 'service provider'],
        'change' => ['change management', 'change control', 'version control', 'configuration'],
    ];

    /**
     * Analyze a compliance mapping and calculate all quality metrics
     *
     * @param ComplianceMapping $mapping The mapping to analyze
     * @return array Analysis results with all metrics
     */
    public function analyzeMappingQuality(ComplianceMapping $mapping): array
    {
        $source = $mapping->getSourceRequirement();
        $target = $mapping->getTargetRequirement();

        // Extract and preprocess text
        $sourceText = $this->extractRequirementText($source);
        $targetText = $this->extractRequirementText($target);

        // Calculate similarity metrics
        $textualSimilarity = $this->calculateTextualSimilarity($sourceText, $targetText);
        $keywordOverlap = $this->calculateKeywordOverlap($sourceText, $targetText);
        $structuralSimilarity = $this->calculateStructuralSimilarity($source, $target);

        // Calculate overall percentage based on combined metrics
        $calculatedPercentage = $this->calculateCombinedPercentage(
            $textualSimilarity,
            $keywordOverlap,
            $structuralSimilarity,
            $source,
            $target
        );

        // Calculate confidence score
        $confidence = $this->calculateConfidenceScore(
            $textualSimilarity,
            $keywordOverlap,
            $structuralSimilarity,
            $sourceText,
            $targetText
        );

        // Calculate overall quality score
        $qualityScore = $this->calculateQualityScore(
            $calculatedPercentage,
            $confidence,
            $mapping
        );

        return [
            'calculated_percentage' => $calculatedPercentage,
            'textual_similarity' => round($textualSimilarity, 4),
            'keyword_overlap' => round($keywordOverlap, 4),
            'structural_similarity' => round($structuralSimilarity, 4),
            'analysis_confidence' => $confidence,
            'quality_score' => $qualityScore,
            'requires_review' => $confidence < 70,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'extracted_keywords' => [
                'source' => $this->extractKeywords($sourceText),
                'target' => $this->extractKeywords($targetText),
            ],
        ];
    }

    /**
     * Extract combined text from requirement (title + description)
     */
    private function extractRequirementText(ComplianceRequirement $requirement): string
    {
        $parts = [];

        if ($title = $requirement->getTitle()) {
            $parts[] = $title;
        }

        if ($description = $requirement->getDescription()) {
            $parts[] = $description;
        }

        return implode(' ', $parts);
    }

    /**
     * Calculate textual similarity using Jaccard and Cosine similarity
     *
     * @param string $text1 Source text
     * @param string $text2 Target text
     * @return float Similarity score (0-1)
     */
    private function calculateTextualSimilarity(string $text1, string $text2): float
    {
        // Normalize texts
        $text1 = $this->normalizeText($text1);
        $text2 = $this->normalizeText($text2);

        // Get word sets for Jaccard similarity
        $words1 = $this->tokenize($text1);
        $words2 = $this->tokenize($text2);

        // Calculate Jaccard similarity
        $jaccardSimilarity = $this->jaccardSimilarity($words1, $words2);

        // Calculate Cosine similarity
        $cosineSimilarity = $this->cosineSimilarity($text1, $text2);

        // Weighted average (Jaccard 40%, Cosine 60%)
        return ($jaccardSimilarity * 0.4) + ($cosineSimilarity * 0.6);
    }

    /**
     * Calculate keyword overlap focusing on security-relevant terms
     *
     * @param string $text1 Source text
     * @param string $text2 Target text
     * @return float Overlap score (0-1)
     */
    private function calculateKeywordOverlap(string $text1, string $text2): float
    {
        $keywords1 = $this->extractKeywords($text1);
        $keywords2 = $this->extractKeywords($text2);

        if (empty($keywords1) || empty($keywords2)) {
            return 0.0;
        }

        // Calculate weighted overlap
        $commonKeywords = array_intersect($keywords1, $keywords2);
        $totalKeywords = array_unique(array_merge($keywords1, $keywords2));

        $overlapRatio = count($commonKeywords) / count($totalKeywords);

        // Bonus for category alignment
        $categoryBonus = $this->calculateCategoryAlignment($keywords1, $keywords2);

        return min(1.0, $overlapRatio + ($categoryBonus * 0.2));
    }

    /**
     * Calculate structural similarity (category, priority, scope alignment)
     *
     * @param ComplianceRequirement $source
     * @param ComplianceRequirement $target
     * @return float Structural similarity (0-1)
     */
    private function calculateStructuralSimilarity(
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): float {
        $score = 0.0;
        $factors = 0;

        // Category alignment (40% weight)
        if ($source->getCategory() && $target->getCategory()) {
            $factors++;
            if ($this->categoriesMatch($source->getCategory(), $target->getCategory())) {
                $score += 0.4;
            }
        }

        // Priority alignment (30% weight)
        if ($source->getPriority() && $target->getPriority()) {
            $factors++;
            $priorityScore = $this->prioritySimilarity($source->getPriority(), $target->getPriority());
            $score += $priorityScore * 0.3;
        }

        // Data source mapping quality (30% weight)
        $dataSourceMapping = $source->getDataSourceMapping();
        if (!empty($dataSourceMapping) && isset($dataSourceMapping['iso_controls'])) {
            $factors++;
            $isoControls = is_array($dataSourceMapping['iso_controls'])
                ? $dataSourceMapping['iso_controls']
                : [$dataSourceMapping['iso_controls']];

            // More ISO controls = better structured mapping
            $controlScore = min(1.0, count($isoControls) / 3);
            $score += $controlScore * 0.3;
        }

        return $factors > 0 ? $score : 0.5; // Default to 0.5 if no factors available
    }

    /**
     * Calculate combined mapping percentage from all similarity metrics
     *
     * @param float $textualSimilarity
     * @param float $keywordOverlap
     * @param float $structuralSimilarity
     * @param ComplianceRequirement $source
     * @param ComplianceRequirement $target
     * @return int Percentage (0-150)
     */
    private function calculateCombinedPercentage(
        float $textualSimilarity,
        float $keywordOverlap,
        float $structuralSimilarity,
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): int {
        // Weighted formula:
        // - Keyword overlap: 40% (most important for compliance)
        // - Textual similarity: 35% (overall content alignment)
        // - Structural similarity: 25% (category/priority alignment)
        $baseScore = (
            ($keywordOverlap * 0.40) +
            ($textualSimilarity * 0.35) +
            ($structuralSimilarity * 0.25)
        ) * 100;

        // Apply bonuses/penalties
        $baseScore = $this->applyModifiers($baseScore, $source, $target);

        // Clamp to 0-150 range
        return max(0, min(150, (int) round($baseScore)));
    }

    /**
     * Apply modifiers based on additional factors
     */
    private function applyModifiers(
        float $baseScore,
        ComplianceRequirement $source,
        ComplianceRequirement $target
    ): float {
        // Bonus if both are from similar framework families
        $sourceFramework = $source->getFramework()->getCode();
        $targetFramework = $target->getFramework()->getCode();

        // ISO family bonus
        if (str_starts_with($sourceFramework, 'ISO') && str_starts_with($targetFramework, 'ISO')) {
            $baseScore += 5;
        }

        // EU regulation family bonus
        if (in_array($sourceFramework, ['GDPR', 'NIS2', 'DORA']) &&
            in_array($targetFramework, ['GDPR', 'NIS2', 'DORA'])) {
            $baseScore += 5;
        }

        // Penalty for very different scopes
        $dataSourceMapping = $source->getDataSourceMapping();
        if (!empty($dataSourceMapping) && isset($dataSourceMapping['iso_controls'])) {
            $isoControls = is_array($dataSourceMapping['iso_controls'])
                ? $dataSourceMapping['iso_controls']
                : [$dataSourceMapping['iso_controls']];

            // Penalty if mapping to many controls (indicates broad/vague mapping)
            if (count($isoControls) > 10) {
                $baseScore -= 10;
            }
        }

        return $baseScore;
    }

    /**
     * Calculate confidence score for the analysis
     *
     * @return int Confidence (0-100)
     */
    private function calculateConfidenceScore(
        float $textualSimilarity,
        float $keywordOverlap,
        float $structuralSimilarity,
        string $sourceText,
        string $targetText
    ): int {
        $confidence = 50; // Base confidence

        // Higher confidence if all metrics agree
        $metrics = [$textualSimilarity, $keywordOverlap, $structuralSimilarity];
        $variance = $this->variance($metrics);

        // Low variance = high confidence
        if ($variance < 0.05) {
            $confidence += 30;
        } elseif ($variance < 0.10) {
            $confidence += 20;
        } elseif ($variance < 0.15) {
            $confidence += 10;
        }

        // Higher confidence with more text content
        $sourceWords = count($this->tokenize($sourceText));
        $targetWords = count($this->tokenize($targetText));
        $minWords = min($sourceWords, $targetWords);

        if ($minWords > 100) {
            $confidence += 15;
        } elseif ($minWords > 50) {
            $confidence += 10;
        } elseif ($minWords > 20) {
            $confidence += 5;
        } else {
            $confidence -= 10; // Penalty for short texts
        }

        // Higher confidence with strong keyword overlap
        if ($keywordOverlap > 0.7) {
            $confidence += 10;
        }

        return max(0, min(100, $confidence));
    }

    /**
     * Calculate overall quality score
     *
     * @return int Quality score (0-100)
     */
    private function calculateQualityScore(
        int $calculatedPercentage,
        int $confidence,
        ComplianceMapping $mapping
    ): int {
        // Quality is based on:
        // - Confidence (40%)
        // - Mapping strength (30%)
        // - Verification status (30%)

        $confidenceScore = $confidence * 0.4;
        $strengthScore = min(100, $calculatedPercentage) * 0.3;

        $verificationScore = 0;
        if ($mapping->getVerifiedBy() !== null) {
            $verificationScore = 30; // Full verification points
        } elseif ($mapping->getReviewedBy() !== null) {
            $verificationScore = 20; // Partial points for review
        }

        $qualityScore = $confidenceScore + $strengthScore + $verificationScore;

        return max(0, min(100, (int) round($qualityScore)));
    }

    /**
     * Extract security-relevant keywords from text
     *
     * @param string $text
     * @return array Keywords found
     */
    private function extractKeywords(string $text): array
    {
        $text = $this->normalizeText($text);
        $keywords = [];

        foreach (self::SECURITY_KEYWORDS as $category => $categoryKeywords) {
            foreach ($categoryKeywords as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    $keywords[] = $keyword;
                }
            }
        }

        return array_unique($keywords);
    }

    /**
     * Calculate category alignment bonus
     */
    private function calculateCategoryAlignment(array $keywords1, array $keywords2): float
    {
        $categories1 = $this->getKeywordCategories($keywords1);
        $categories2 = $this->getKeywordCategories($keywords2);

        $commonCategories = array_intersect($categories1, $categories2);
        $totalCategories = array_unique(array_merge($categories1, $categories2));

        return empty($totalCategories) ? 0.0 : count($commonCategories) / count($totalCategories);
    }

    /**
     * Get categories for keywords
     */
    private function getKeywordCategories(array $keywords): array
    {
        $categories = [];

        foreach ($keywords as $keyword) {
            foreach (self::SECURITY_KEYWORDS as $category => $categoryKeywords) {
                if (in_array($keyword, $categoryKeywords, true)) {
                    $categories[] = $category;
                }
            }
        }

        return array_unique($categories);
    }

    /**
     * Check if categories match (exact or related)
     */
    private function categoriesMatch(string $cat1, string $cat2): bool
    {
        $cat1 = strtolower($cat1);
        $cat2 = strtolower($cat2);

        // Exact match
        if ($cat1 === $cat2) {
            return true;
        }

        // Related categories
        $relatedCategories = [
            ['access control', 'authentication', 'authorization', 'identity management'],
            ['encryption', 'cryptography', 'data protection'],
            ['network security', 'network', 'firewall', 'perimeter security'],
            ['incident response', 'incident management', 'business continuity'],
            ['audit', 'logging', 'monitoring'],
            ['risk management', 'risk assessment'],
        ];

        foreach ($relatedCategories as $group) {
            if (in_array($cat1, $group, true) && in_array($cat2, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate priority similarity
     */
    private function prioritySimilarity(string $priority1, string $priority2): float
    {
        $priorityLevels = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        $level1 = $priorityLevels[strtolower($priority1)] ?? 2;
        $level2 = $priorityLevels[strtolower($priority2)] ?? 2;

        $diff = abs($level1 - $level2);

        // Perfect match = 1.0, 1 level diff = 0.7, 2 level diff = 0.4, 3 level diff = 0.1
        return match($diff) {
            0 => 1.0,
            1 => 0.7,
            2 => 0.4,
            default => 0.1,
        };
    }

    /**
     * Normalize text for analysis
     */
    private function normalizeText(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text);

        // Remove special characters but keep spaces
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Tokenize text into words
     */
    private function tokenize(string $text): array
    {
        $words = explode(' ', $text);

        // Remove stopwords and short words
        $stopwords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'shall'];

        $words = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords, true);
        });

        return array_values($words);
    }

    /**
     * Calculate Jaccard similarity
     */
    private function jaccardSimilarity(array $set1, array $set2): float
    {
        if (empty($set1) && empty($set2)) {
            return 1.0;
        }

        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($set1, $set2));
        $union = count(array_unique(array_merge($set1, $set2)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Calculate Cosine similarity
     */
    private function cosineSimilarity(string $text1, string $text2): float
    {
        $words1 = $this->tokenize($text1);
        $words2 = $this->tokenize($text2);

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        // Create frequency vectors
        $allWords = array_unique(array_merge($words1, $words2));
        $vector1 = [];
        $vector2 = [];

        foreach ($allWords as $word) {
            $vector1[] = count(array_filter($words1, fn($w) => $w === $word));
            $vector2[] = count(array_filter($words2, fn($w) => $w === $word));
        }

        // Calculate cosine similarity
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Calculate variance of an array
     */
    private function variance(array $numbers): float
    {
        if (empty($numbers)) {
            return 0.0;
        }

        $mean = array_sum($numbers) / count($numbers);
        $variance = 0;

        foreach ($numbers as $number) {
            $variance += pow($number - $mean, 2);
        }

        return $variance / count($numbers);
    }
}
