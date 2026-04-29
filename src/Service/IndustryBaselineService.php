<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and applies industry-tailored baselines for any compliance framework.
 *
 * A baseline is a pre-configured set of `maturityTarget` values per requirement,
 * organised by industry (KRITIS, Finance, SaaS, Manufacturing, Healthcare, ...).
 * The service is framework-agnostic — it scans `fixtures/baselines/<framework
 * code>/*.yaml`, validates the schema, and writes the targets onto the matching
 * `ComplianceRequirement` rows for the given tenant.
 *
 * MRIS keeps its own `MrisBaselineService` because it predates this generic
 * service and additionally writes a maturity audit trail; the new service is
 * reserved for ISO 27001, BSI Grundschutz, BSI C5, NIS2, DORA, TISAX, GDPR,
 * ISO 27701 and NIST CSF.
 */
final class IndustryBaselineService
{
    private const BASELINE_ROOT = '/fixtures/baselines';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly RequestStack $requestStack,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Frameworks that ship at least one industry baseline.
     *
     * @return list<array{code: string, count: int}>
     */
    public function listFrameworksWithBaselines(): array
    {
        $root = $this->projectDir . self::BASELINE_ROOT;
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            $count = count(glob($dir . '/*.yaml') ?: []);
            if ($count > 0) {
                $out[] = ['code' => $code, 'count' => $count];
            }
        }
        usort($out, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $out;
    }

    /**
     * Metadata for every baseline of a given framework (without targets).
     *
     * @return list<array{id: string, name: string, industry: string, description: string, file: string, framework: string}>
     */
    public function listBaselinesForFramework(string $frameworkCode): array
    {
        $dir = $this->baselineDir($frameworkCode);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.yaml') ?: [] as $file) {
            $payload = Yaml::parseFile($file);
            $b = $payload['baseline'] ?? [];
            $out[] = [
                'id' => (string) ($b['id'] ?? basename($file, '.yaml')),
                'name' => $this->localisedField($b, 'name') ?: basename($file, '.yaml'),
                'industry' => (string) ($b['industry'] ?? 'unknown'),
                'description' => $this->localisedField($b, 'description'),
                'file' => basename($file),
                'framework' => $frameworkCode,
            ];
        }
        usort($out, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Load a single baseline by id (with or without `.yaml` extension).
     *
     * @return array{id: string, name: string, industry: string, framework: string, targets: array<string, array{maturity_target: ?string, applicable: ?bool, reason: string}>}
     */
    public function loadBaseline(string $frameworkCode, string $idOrFilename): array
    {
        $dir = $this->baselineDir($frameworkCode);
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
            foreach (glob($dir . '/*.yaml') ?: [] as $f) {
                $p = Yaml::parseFile($f);
                if (($p['baseline']['id'] ?? null) === $idOrFilename) {
                    $found = $f;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new DomainException(sprintf(
                'Baseline "%s" für Framework "%s" nicht gefunden.',
                $idOrFilename,
                $frameworkCode
            ));
        }

        $payload = Yaml::parseFile($found);
        $b = $payload['baseline'] ?? [];
        if (empty($b['id']) || !isset($b['targets']) || !is_array($b['targets'])) {
            throw new DomainException(sprintf(
                'Baseline "%s" ist ungültig (id oder targets fehlen).',
                $found
            ));
        }

        $locale = $this->currentLocale();
        $localisedTargets = [];
        foreach ($b['targets'] as $reqId => $config) {
            if (!is_array($config)) {
                $localisedTargets[(string) $reqId] = [
                    'maturity_target' => is_string($config) ? $config : null,
                    'applicable' => null,
                    'reason' => '',
                ];
                continue;
            }
            $reason = ($locale === 'en' && !empty($config['reason_en']))
                ? (string) $config['reason_en']
                : (string) ($config['reason'] ?? '');
            $localisedTargets[(string) $reqId] = [
                'maturity_target' => isset($config['maturity_target']) ? (string) $config['maturity_target'] : null,
                'applicable' => isset($config['applicable']) ? (bool) $config['applicable'] : null,
                'reason' => $reason,
            ];
        }

        return [
            'id' => (string) $b['id'],
            'name' => $this->localisedField($b, 'name') ?: (string) $b['id'],
            'industry' => (string) ($b['industry'] ?? 'unknown'),
            'framework' => $frameworkCode,
            'targets' => $localisedTargets,
        ];
    }

    /**
     * Apply a baseline to all matching requirements of a framework.
     *
     * Sets `maturityTarget` only — the current/measured value (`maturityCurrent`)
     * is left untouched so existing self-assessments are preserved.
     *
     * @return array{baseline: string, applied: int, skipped: int, missing: array<int, string>}
     */
    public function applyBaseline(Tenant $tenant, string $frameworkCode, string $baselineId, bool $dryRun = false): array
    {
        $baseline = $this->loadBaseline($frameworkCode, $baselineId);

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if (!$framework instanceof ComplianceFramework) {
            throw new DomainException(sprintf(
                'Framework "%s" nicht im System — Library zuerst importieren.',
                $frameworkCode
            ));
        }

        $applied = 0;
        $skipped = 0;
        $missing = [];

        foreach ($baseline['targets'] as $reqId => $config) {
            $target = $config['maturity_target'];
            if ($target === null || $target === '') {
                $skipped++;
                continue;
            }

            $requirement = $this->requirementRepository->findOneBy([
                'framework' => $framework,
                'requirementId' => (string) $reqId,
            ]);
            if (!$requirement instanceof ComplianceRequirement) {
                $missing[] = (string) $reqId;
                continue;
            }

            if (!$dryRun) {
                $requirement->setMaturityTarget($target);
            }
            $applied++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return [
            'baseline' => $baseline['id'],
            'applied' => $applied,
            'skipped' => $skipped,
            'missing' => $missing,
        ];
    }

    private function baselineDir(string $frameworkCode): string
    {
        // Sanitise to avoid traversal — only [A-Za-z0-9_.-] allowed.
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $frameworkCode) !== 1) {
            throw new DomainException(sprintf('Ungültiger Framework-Code: "%s".', $frameworkCode));
        }

        return $this->projectDir . self::BASELINE_ROOT . '/' . $frameworkCode;
    }

    private function localisedField(array $payload, string $field): string
    {
        $locale = $this->currentLocale();
        if ($locale === 'en' && !empty($payload[$field . '_en'])) {
            return (string) $payload[$field . '_en'];
        }

        return (string) ($payload[$field] ?? '');
    }

    private function currentLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'de';
    }
}
