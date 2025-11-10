<?php

namespace App\Service;

use App\Entity\ComplianceRequirement;
use App\Repository\AssetRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;

/**
 * Service for mapping ISMS data to various compliance frameworks
 * Maximizes data value by reusing existing information
 */
class ComplianceMappingService
{
    public function __construct(
        private ControlRepository $controlRepository,
        private AssetRepository $assetRepository,
        private BusinessProcessRepository $businessProcessRepository,
        private IncidentRepository $incidentRepository,
        private InternalAuditRepository $internalAuditRepository
    ) {}

    /**
     * Map ISO 27001 controls to a requirement automatically
     */
    public function mapControlsToRequirement(ComplianceRequirement $requirement): array
    {
        $mapping = $requirement->getDataSourceMapping() ?? [];
        $mappedControls = [];

        // Check if there are explicit ISO control mappings
        if (isset($mapping['iso_controls']) && is_array($mapping['iso_controls'])) {
            foreach ($mapping['iso_controls'] as $controlId) {
                $controls = $this->controlRepository->findBy(['controlId' => $controlId]);
                foreach ($controls as $control) {
                    $mappedControls[] = $control;
                    $requirement->addMappedControl($control);
                }
            }
        }

        return $mappedControls;
    }

    /**
     * Get data reuse analysis for a requirement
     * Shows which existing ISMS data contributes to fulfilling this requirement
     */
    public function getDataReuseAnalysis(ComplianceRequirement $requirement): array
    {
        $analysis = [
            'requirement_id' => $requirement->getRequirementId(),
            'title' => $requirement->getTitle(),
            'sources' => [],
            'confidence' => 'unknown',
            'fulfillment_percentage' => $requirement->getFulfillmentPercentage(),
        ];

        $mapping = $requirement->getDataSourceMapping() ?? [];

        // Analyze mapped controls
        if (!$requirement->getMappedControls()->isEmpty()) {
            $controlsAnalysis = $this->analyzeControlsContribution($requirement);
            $analysis['sources']['controls'] = $controlsAnalysis;
        }

        // Analyze asset-related evidence
        if (isset($mapping['asset_types']) && is_array($mapping['asset_types'])) {
            $assetsAnalysis = $this->analyzeAssetsContribution($mapping['asset_types']);
            $analysis['sources']['assets'] = $assetsAnalysis;
        }

        // Analyze BCM data
        if (isset($mapping['bcm_required']) && $mapping['bcm_required'] === true) {
            $bcmAnalysis = $this->analyzeBCMContribution();
            $analysis['sources']['bcm'] = $bcmAnalysis;
        }

        // Analyze incident data
        if (isset($mapping['incident_management']) && $mapping['incident_management'] === true) {
            $incidentAnalysis = $this->analyzeIncidentContribution();
            $analysis['sources']['incidents'] = $incidentAnalysis;
        }

        // Analyze audit evidence
        if (isset($mapping['audit_evidence']) && $mapping['audit_evidence'] === true) {
            $auditAnalysis = $this->analyzeAuditContribution();
            $analysis['sources']['audits'] = $auditAnalysis;
        }

        // Calculate overall confidence
        $analysis['confidence'] = $this->calculateConfidence($analysis['sources']);

        return $analysis;
    }

    /**
     * Analyze how controls contribute to requirement fulfillment
     */
    private function analyzeControlsContribution(ComplianceRequirement $requirement): array
    {
        $controls = $requirement->getMappedControls();
        $totalImplementation = 0;
        $implementedCount = 0;
        $controlDetails = [];

        foreach ($controls as $control) {
            $implementation = $control->getImplementationPercentage() ?? 0;
            $totalImplementation += $implementation;

            if ($control->getImplementationStatus() === 'implemented') {
                $implementedCount++;
            }

            $controlDetails[] = [
                'id' => $control->getControlId(),
                'name' => $control->getName(),
                'status' => $control->getImplementationStatus(),
                'implementation' => $implementation,
            ];
        }

        $avgImplementation = $controls->count() > 0 ? $totalImplementation / $controls->count() : 0;

        return [
            'count' => $controls->count(),
            'implemented' => $implementedCount,
            'average_implementation' => round($avgImplementation, 2),
            'controls' => $controlDetails,
            'contribution' => round($avgImplementation, 0),
        ];
    }

    /**
     * Analyze asset inventory contribution
     */
    private function analyzeAssetsContribution(array $assetTypes): array
    {
        $totalAssets = $this->assetRepository->count([]);
        $typedAssets = 0;

        foreach ($assetTypes as $type) {
            $typedAssets += $this->assetRepository->count(['assetType' => $type]);
        }

        return [
            'total_assets' => $totalAssets,
            'relevant_assets' => $typedAssets,
            'asset_types' => $assetTypes,
            'contribution' => $typedAssets > 0 ? 100 : 0,
        ];
    }

    /**
     * Analyze BCM data contribution
     */
    private function analyzeBCMContribution(): array
    {
        $processCount = $this->businessProcessRepository->count([]);
        $criticalProcesses = $this->businessProcessRepository->findCriticalProcesses();

        return [
            'total_processes' => $processCount,
            'critical_processes' => count($criticalProcesses),
            'has_bia_data' => $processCount > 0,
            'contribution' => $processCount > 0 ? 100 : 0,
        ];
    }

    /**
     * Analyze incident management contribution
     */
    private function analyzeIncidentContribution(): array
    {
        $incidentCount = $this->incidentRepository->count([]);
        $resolvedIncidents = $this->incidentRepository->count(['status' => 'resolved']);

        return [
            'total_incidents' => $incidentCount,
            'resolved_incidents' => $resolvedIncidents,
            'has_incident_process' => $incidentCount > 0,
            'contribution' => $incidentCount > 0 ? 100 : 0,
        ];
    }

    /**
     * Analyze audit evidence contribution
     */
    private function analyzeAuditContribution(): array
    {
        $auditCount = $this->internalAuditRepository->count([]);
        $completedAudits = $this->internalAuditRepository->count(['status' => 'completed']);

        return [
            'total_audits' => $auditCount,
            'completed_audits' => $completedAudits,
            'has_audit_program' => $auditCount > 0,
            'contribution' => $completedAudits > 0 ? 100 : 0,
        ];
    }

    /**
     * Calculate overall confidence based on available data sources
     */
    private function calculateConfidence(array $sources): string
    {
        if (empty($sources)) {
            return 'low';
        }

        $totalSources = count($sources);
        $contributingSources = 0;

        foreach ($sources as $source) {
            if (isset($source['contribution']) && $source['contribution'] > 0) {
                $contributingSources++;
            }
        }

        $ratio = $contributingSources / $totalSources;

        if ($ratio >= 0.8) {
            return 'high';
        } elseif ($ratio >= 0.5) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Get cross-framework mapping insights
     * Shows which ISMS controls/data satisfy multiple frameworks
     */
    public function getCrossFrameworkInsights(): array
    {
        $controls = $this->controlRepository->findAll();
        $insights = [];

        foreach ($controls as $control) {
            $controlInsight = [
                'control_id' => $control->getControlId(),
                'control_name' => $control->getName(),
                'frameworks_satisfied' => [],
            ];

            // This would be populated based on actual requirement mappings
            // For now, we'll return the structure
            $insights[] = $controlInsight;
        }

        return $insights;
    }

    /**
     * Calculate potential time savings from data reuse
     */
    public function calculateDataReuseValue(ComplianceRequirement $requirement): array
    {
        $analysis = $this->getDataReuseAnalysis($requirement);
        $sourceCount = count($analysis['sources']);

        // Estimate hours saved per data source reused (conservative estimate)
        $hoursPerSource = 4; // Average time to gather evidence from scratch
        $hoursSaved = $sourceCount * $hoursPerSource;

        return [
            'requirement_id' => $requirement->getRequirementId(),
            'data_sources_reused' => $sourceCount,
            'estimated_hours_saved' => $hoursSaved,
            'confidence' => $analysis['confidence'],
        ];
    }
}
