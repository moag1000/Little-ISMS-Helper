<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ComplianceRequirement;
use App\Event\FrameworkActivatedEvent;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\MappingSuggestionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * V3 W2-WS-12 — Compliance-Framework activation → Auto-Coverage-Check.
 *
 * When a framework is freshly activated for a tenant we want to know
 * upfront how many of its requirements are already covered by existing
 * mappings (transitive coverage from already-active source frameworks)
 * and how many *additional* mappings the AutoMapper proposes.
 *
 * Output is written to the application logger as a structured event
 * record (`compliance.framework.coverage_check`) so the admin UI can
 * surface it as an Aurora-toast: "23 of 47 requirements auto-mapped
 * from existing controls".
 *
 * Heuristics:
 *  - "covered_by_mappings": requirement has at least one ComplianceMapping
 *    (source or target).
 *  - "auto_suggested": MappingSuggestionService finds at least one
 *    high-confidence (>= 0.40) suggestion from an existing tenant
 *    Control. That control therefore likely satisfies the requirement
 *    and the auditor only has to confirm the link.
 *
 * The listener does not write any new mappings — it merely surfaces
 * the diagnostic. WS-12 explicitly leaves the human-in-the-loop guard.
 */
#[AsEventListener(event: FrameworkActivatedEvent::class)]
final class ComplianceFrameworkImportListener
{
    private const SUGGESTION_THRESHOLD = 0.40;

    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ControlRepository $controlRepository,
        private readonly MappingSuggestionService $mappingSuggestionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FrameworkActivatedEvent $event): void
    {
        try {
            $framework = $event->framework;
            $tenant = $event->tenant;

            $requirements = $this->requirementRepository->findBy([
                'framework' => $framework,
            ]);
            $total = count($requirements);
            if ($total === 0) {
                return;
            }

            $coveredByMapping = 0;
            $autoSuggestable = 0;

            // Build a single set of requirement-ids that the AutoMapper
            // suggests across the tenant's controls. Capped at 50 controls
            // to keep the listener responsive on freshly seeded tenants.
            $controls = $this->controlRepository->findBy(['tenant' => $tenant]);
            $suggestedReqIds = $this->collectSuggestedRequirementIds($controls);

            foreach ($requirements as $req) {
                /** @var ComplianceRequirement $req */
                if ($this->hasExistingMapping($req)) {
                    $coveredByMapping++;
                    continue;
                }
                if (in_array($req->getId(), $suggestedReqIds, true)) {
                    $autoSuggestable++;
                }
            }

            $this->logger->info('compliance.framework.coverage_check', [
                'tenant_id' => $tenant->getId(),
                'framework_code' => $framework->getCode(),
                'requirements_total' => $total,
                'covered_by_mapping' => $coveredByMapping,
                'auto_suggestable' => $autoSuggestable,
                'remaining_uncovered' => max(0, $total - $coveredByMapping - $autoSuggestable),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Compliance-Framework coverage-check failed', [
                'framework' => $event->framework->getCode(),
                'tenant_id' => $event->tenant->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasExistingMapping(ComplianceRequirement $req): bool
    {
        $existing = $this->mappingRepository->findBy(['targetRequirement' => $req], null, 1);
        if (!empty($existing)) {
            return true;
        }
        $existing = $this->mappingRepository->findBy(['sourceRequirement' => $req], null, 1);
        return !empty($existing);
    }

    /**
     * Walk every control once, collect the set of requirement-ids that
     * the AutoMapper suggests above the threshold.
     *
     * @param list<\App\Entity\Control> $controls
     * @return list<int>
     */
    private function collectSuggestedRequirementIds(array $controls): array
    {
        $ids = [];
        $checked = 0;
        foreach ($controls as $control) {
            if ($checked++ > 50) {
                break;
            }
            $byFramework = $this->mappingSuggestionService->suggestForControl(
                $control,
                self::SUGGESTION_THRESHOLD,
                limitPerFramework: 5,
            );
            foreach ($byFramework as $entries) {
                foreach ($entries as $entry) {
                    if (
                        isset($entry['requirement'], $entry['confidence'])
                        && $entry['requirement'] instanceof ComplianceRequirement
                        && (float) $entry['confidence'] >= self::SUGGESTION_THRESHOLD
                    ) {
                        $rid = $entry['requirement']->getId();
                        if ($rid !== null) {
                            $ids[$rid] = true;
                        }
                    }
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }
}
