<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\Tenant;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;

/**
 * Dashboard figure "one control, several frameworks": which of the organisation's controls
 * serve requirements of two or more frameworks.
 *
 * The controls behind a (global) requirement are the organisation's, as the fulfilment engine
 * finds them ({@see FulfillmentRuleEngine::controlsFor()}) — never the requirement's
 * `getMappedControls()`, which holds every organisation's controls.
 */
final class CrossFrameworkControlReuse
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly FulfillmentRuleEngine $ruleEngine,
    ) {
    }

    /**
     * @return array{shared_controls: int, reuse_ratio: float|int, top_shared: list<array{id: ?string, name: ?string, framework_count: int}>}
     */
    public function forTenant(Tenant $tenant): array
    {
        $map = [];
        foreach ($this->requirementRepository->findAll() as $requirement) {
            $fwCode = $requirement->getFramework()?->getCode();
            if ($fwCode === null) {
                continue;
            }
            foreach ($this->ruleEngine->controlsFor($requirement, $tenant) as $control) {
                $id = (int) $control->getId();
                $map[$id] ??= ['control' => $control, 'frameworks' => []];
                $map[$id]['frameworks'][$fwCode] = true;
            }
        }

        $shared = array_values(array_filter($map, static fn (array $c): bool => count($c['frameworks']) >= 2));
        usort($shared, static fn (array $a, array $b): int => count($b['frameworks']) <=> count($a['frameworks']));

        return [
            'shared_controls' => count($shared),
            'reuse_ratio' => $map !== [] ? round(count($shared) / count($map) * 100) : 0,
            'top_shared' => array_map(static fn (array $c): array => [
                'id' => $c['control']->getControlId(),
                'name' => $c['control']->getName(),
                'framework_count' => count($c['frameworks']),
            ], array_slice($shared, 0, 5)),
        ];
    }
}
