<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * A field a workflow or a recorded action owns: approvals, report and
 * closing timestamps, implementation evidence. REST and the assistant (MCP)
 * may read it, never write it — otherwise backdating a field would fake a
 * deadline kept (NIS-2 Art. 23 report times) or an approval given.
 *
 * Who writes it instead is named in $writtenBy (the action in the UI, a
 * service, the lifecycle). tests/Api/ApiResourceContractTest.php fails when
 * such a property sits in a `:write` serialisation group.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ApiReadOnlyEvidence
{
    /**
     * @param bool $importable a current-state value a person may also set in a reviewed bulk import
     *                         (preview, audit batch) — never a timestamp or approval of the past
     */
    public function __construct(
        public string $writtenBy,
        public bool $importable = false,
    ) {
    }
}
