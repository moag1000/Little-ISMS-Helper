<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Compliance\FrameworkLoaderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loader-Fixer: re-runs framework loaders idempotently to pick up requirements
 * that were added in newer loader versions after the initial load.
 *
 * Loaders with an idempotent findOrCreate flow (BSI/GDPR/ISO27701/...) will add
 * only the missing deltas. Loaders that still skip when requirements exist will
 * report 0 added (still safe to run).
 */
final class ComplianceLoaderFixerService
{
    /**
     * Ordered map of framework code → human-readable label.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'ISO27001'        => 'ISO/IEC 27001:2022',
        'ISO27005'        => 'ISO/IEC 27005:2022',
        'ISO27701'        => 'ISO/IEC 27701:2019',
        'ISO-22301'       => 'ISO 22301:2019',
        'NIS2'            => 'NIS2 Directive (2022/2555)',
        'NIS2UMSUCG'      => 'NIS2UmsuCG (DE)',
        'DORA'            => 'EU-DORA (2022/2554)',
        'TISAX'           => 'TISAX (VDA-ISA 6.0)',
        'BSI_GRUNDSCHUTZ' => 'BSI IT-Grundschutz',
        'BSI-C5'          => 'BSI C5:2020',
        'BSI-C5-2026'     => 'BSI C5:2026',
        'GDPR'            => 'GDPR (2016/679)',
        'BDSG'            => 'BDSG 2018/2024',
        'NIST-CSF'        => 'NIST CSF 2.0',
        'SOC2'            => 'SOC 2 (2017 rev. 2022)',
        'CIS-CONTROLS'    => 'CIS Controls v8.1',
        'EU-AI-ACT'       => 'EU AI Act (2024/1689)',
        'KRITIS'          => 'KRITIS (§8a/§8b)',
        'KRITIS-HEALTH'   => 'KRITIS Health (KHPatSiG)',
        'TKG-2024'        => 'TKG 2024',
        'DIGAV'           => 'DiGAV',
        'GXP'             => 'GxP',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly FrameworkLoaderRegistry $loaderRegistry,
    ) {}

    /**
     * @return list<array{code:string, label:string, loaded_count:int, framework_id:?int, exists:bool}>
     */
    public function getStatus(): array
    {
        $rows = [];
        foreach (self::LABELS as $code => $label) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            $loaded = $framework !== null
                ? $this->requirementRepository->count(['framework' => $framework])
                : 0;
            $rows[] = [
                'code' => $code,
                'label' => $label,
                'loaded_count' => $loaded,
                'framework_id' => $framework?->getId(),
                'exists' => $framework !== null,
            ];
        }
        return $rows;
    }

    public function knownFrameworks(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @return array{added:int, output:string, success:bool, code:string, before:int, after:int}
     */
    public function fixOne(string $code, string $actorDescription = 'system'): array
    {
        if (!isset(self::LABELS[$code])) {
            return [
                'added' => 0,
                'output' => sprintf('Unknown framework code: %s', $code),
                'success' => false,
                'code' => $code,
                'before' => 0,
                'after' => 0,
            ];
        }

        $before = $this->countRequirementsFor($code);
        $beforeMetadata = $this->snapshotFrameworkMetadata($code);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $success = true;
        try {
            if (!$this->loaderRegistry->has($code)) {
                throw new \RuntimeException(sprintf('No loader registered for framework code: %s', $code));
            }
            $rc = $this->loaderRegistry->load($code, false, $io);
            if ($rc !== 0) {
                $success = false;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Loader fixer failed', ['code' => $code, 'error' => $e->getMessage()]);
            $success = false;
            $output->writeln('EXCEPTION: ' . $e->getMessage());
        }

        // NOTE: do NOT clear() the EntityManager here. Under Doctrine ORM 3.x
        // EntityManager::clear() takes no argument — the ComplianceFramework::class
        // hint is silently ignored and the WHOLE identity map is detached,
        // including the security token's Tenant/User. The result page then renders
        // the global mega-menu (which reads app.user.tenant) against detached
        // entities and throws an identity-map collision ("another object of class
        // Tenant was already present for the same ID"), 500-ing the page so the
        // fixer appears to "do nothing". A fresh count is not needed anyway:
        // countRequirementsFor() runs a COUNT query (hits the DB, sees the inner
        // flush) and snapshotFrameworkMetadata() reads the same managed framework
        // instance the loader just upserted.
        $after = $this->countRequirementsFor($code);
        $added = max(0, $after - $before);
        $afterMetadata = $this->snapshotFrameworkMetadata($code);
        $metadataDiff = $this->diffMetadata($beforeMetadata, $afterMetadata);

        // ISB MAJOR-4: Audit-Log must show field-level diff when the loader
        // upserts framework metadata, not just count deltas. Auditor's
        // question "show me every framework row ever silently overwritten"
        // is now answerable from the AuditLog.
        $this->auditLogger->logCustom(
            'compliance.loader_fixer.run',
            'ComplianceFramework',
            $afterMetadata['id'] ?? null,
            [
                'loaded_before' => $before,
                'metadata_before' => $beforeMetadata,
            ],
            [
                'loaded_after' => $after,
                'added' => $added,
                'success' => $success,
                'metadata_after' => $afterMetadata,
                'metadata_changed_fields' => array_keys($metadataDiff),
            ],
            sprintf(
                'Loader-Fixer: %s — added %d requirement(s), %d metadata field(s) refreshed (total %d, %s)',
                $code,
                $added,
                count($metadataDiff),
                $after,
                $actorDescription,
            ),
        );

        return [
            'added' => $added,
            'output' => $output->fetch(),
            'success' => $success,
            'code' => $code,
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @return array<string, array{added:int, output:string, success:bool, code:string, before:int, after:int}>
     */
    public function fixAll(string $actorDescription = 'system'): array
    {
        $results = [];
        foreach (array_keys(self::LABELS) as $code) {
            $results[$code] = $this->fixOne($code, $actorDescription);
        }
        return $results;
    }

    private function countRequirementsFor(string $code): int
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
        return $framework === null
            ? 0
            : $this->requirementRepository->count(['framework' => $framework]);
    }

    /**
     * Capture the framework-row metadata that the upsert-loaders rewrite on
     * every run. Returns [] when the framework does not exist yet.
     *
     * @return array<string, mixed>
     */
    private function snapshotFrameworkMetadata(string $code): array
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
        if ($framework === null) {
            return [];
        }
        return [
            'id' => $framework->getId(),
            'code' => $framework->getCode(),
            'name' => $framework->getName(),
            'description' => $framework->getDescription(),
            'version' => $framework->getVersion(),
            'applicable_industry' => $framework->getApplicableIndustry(),
            'regulatory_body' => $framework->getRegulatoryBody(),
            'scope_description' => $framework->getScopeDescription(),
            'mandatory' => $framework->isMandatory(),
            'active' => $framework->isActive(),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{before: mixed, after: mixed}> — field => before/after pair
     */
    private function diffMetadata(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue !== $newValue) {
                $diff[$key] = ['before' => $oldValue, 'after' => $newValue];
            }
        }
        return $diff;
    }
}
