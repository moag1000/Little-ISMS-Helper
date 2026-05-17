<?php

declare(strict_types=1);

namespace App\Lifecycle\Exception;

class ReasonRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly string $workflowName,
        public readonly string $transitionName,
    ) {
        parent::__construct(sprintf(
            'Reason required for transition "%s" in workflow "%s".',
            $transitionName,
            $workflowName,
        ));
    }
}
