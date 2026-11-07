<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Workflow\WorkflowStateLookup;
use Twig\Attribute\AsTwigFunction;

/**
 * Data for list rows that the controller does not have to pass: the running
 * approval per record, looked up once per page.
 *
 * Twig: `{% set _wf = workflow_states(page) %}` above the table, then in the row
 * `{{ _fa_workflow_pill.render(_wf[risk.id] ?? {}) }}`.
 */
final readonly class ListPageExtension
{
    public function __construct(
        private WorkflowStateLookup $workflowStateLookup,
    ) {
    }

    /**
     * @param iterable<object> $entities
     *
     * @return array<int, array<string, mixed>>
     */
    #[AsTwigFunction('workflow_states')]
    public function workflowStates(iterable $entities): array
    {
        return $this->workflowStateLookup->forMany($entities);
    }
}
