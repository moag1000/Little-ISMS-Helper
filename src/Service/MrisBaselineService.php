<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lädt und applied Branchen-Baselines für MRIS-MHC-Reifegrade.
 *
 * Eine Baseline ist eine vorkonfigurierte Soll-Stufen-Liste pro MHC,
 * abgestimmt auf eine Branche (KRITIS / Finance / Automotive / SaaS).
 * Spart beim Onboarding 8-12 Beratertage gegenüber manueller MHC-für-MHC-
 * Soll-Diskussion.
 *
 * Quelle MRIS-Konzepte: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: CC BY 4.0.
 */
final class MrisBaselineService
{
    public const FRAMEWORK_CODE = 'MRIS-v1.5';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly MrisMaturityService $maturityService,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Listet alle verfügbaren Baselines (mit Metadaten, ohne mhc_targets).
     *
     * @return array<int, array{id: string, name: string, industry: string, description: string, file: string}>
     */
    public function listBaselines(): array
    {
        $dir = $this->projectDir . '/fixtures/mris/baselines';
        $files = glob($dir . '/*.yaml') ?: [];
        $out = [];
        foreach ($files as $file) {
            $payload = Yaml::parseFile($file);
            $b = $payload['baseline'] ?? [];
            $out[] = [
                'id' => $b['id'] ?? basename($file, '.yaml'),
                'name' => $b['name'] ?? basename($file, '.yaml'),
                'industry' => $b['industry'] ?? 'unknown',
                'description' => $b['description'] ?? '',
                'file' => basename($file),
            ];
        }
        return $out;
    }

    /**
     * Lädt eine Baseline-Datei (nach id, ohne .yaml-Extension).
     *
     * @return array{id: string, name: string, industry: string, mhc_targets: array<string, array{target: string, reason: string}>}
     */
    public function loadBaseline(string $idOrFilename): array
    {
        $dir = $this->projectDir . '/fixtures/mris/baselines';
        // Try by filename first (kritis.yaml), then by id-match
        $candidates = [
            $dir . '/' . $idOrFilename . '.yaml',
            $dir . '/' . $idOrFilename,
        ];
        $found = null;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $found = $path;
                break;
            }
        }
        if ($found === null) {
            // Try id-match by reading all baselines
            foreach (glob($dir . '/*.yaml') ?: [] as $f) {
                $p = Yaml::parseFile($f);
                if (($p['baseline']['id'] ?? null) === $idOrFilename) {
                    $found = $f;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new DomainException(sprintf('Baseline "%s" nicht gefunden.', $idOrFilename));
        }

        $payload = Yaml::parseFile($found);
        $b = $payload['baseline'] ?? [];
        if (empty($b['id']) || empty($b['mhc_targets'])) {
            throw new DomainException(sprintf('Baseline "%s" ist ungültig (id oder mhc_targets fehlt).', $found));
        }

        return [
            'id' => $b['id'],
            'name' => $b['name'] ?? $b['id'],
            'industry' => $b['industry'] ?? 'unknown',
            'mhc_targets' => $b['mhc_targets'],
        ];
    }

    /**
     * Wendet eine Baseline auf alle MRIS-MHC-Requirements an.
     *
     * Setzt nur das Soll (maturity_target) — das Ist bleibt unangetastet,
     * damit bestehende Self-Assessments erhalten bleiben.
     *
     * @return array{baseline: string, applied: int, skipped: int, missing_mhcs: array<int, string>}
     */
    public function applyBaseline(Tenant $tenant, string $baselineId, bool $dryRun = false): array
    {
        $baseline = $this->loadBaseline($baselineId);
        $framework = $this->frameworkRepository->findOneBy(['code' => self::FRAMEWORK_CODE]);
        if (!$framework instanceof ComplianceFramework) {
            throw new DomainException(sprintf('Framework "%s" nicht im System — Library zuerst importieren.', self::FRAMEWORK_CODE));
        }

        $applied = 0;
        $skipped = 0;
        $missing = [];

        foreach ($baseline['mhc_targets'] as $mhcId => $config) {
            $target = is_array($config) ? ($config['target'] ?? null) : $config;
            if ($target === null) {
                $skipped++;
                continue;
            }

            $requirement = $this->requirementRepository->findOneBy([
                'framework' => $framework,
                'requirementId' => $mhcId,
            ]);
            if ($requirement === null) {
                $missing[] = $mhcId;
                continue;
            }

            if (!$dryRun) {
                $this->maturityService->setTarget($requirement, $target);
            }
            $applied++;
        }

        return [
            'baseline' => $baseline['id'],
            'applied' => $applied,
            'skipped' => $skipped,
            'missing_mhcs' => $missing,
        ];
    }
}
