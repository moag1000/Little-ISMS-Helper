<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * A record that is evidence as a whole: written once by the step that produces it
 * (audit trail, management decision, audit freeze, SoA snapshot, acknowledgement,
 * approval request, approval run, published version) and never edited afterwards.
 *
 * An organisation restore neither adds, rewrites nor clears rows of such a class —
 * the backup file is untrusted (S19), and a restore would otherwise replace a
 * decision of the top management or an approval by values from the file (S26).
 * Only an instance restore by the instance operator (SUPER_ADMIN, no scope) brings
 * them back. Who writes the record is named in $writtenBy.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EvidenceRecord
{
    public function __construct(
        public string $writtenBy,
    ) {
    }
}
