<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Per-entity lifecycle override attribute.
 *
 * When applied to an entity class, the LifecycleRegistry will use the
 * declared {@see $stages} map instead of the standard 5-stage flow
 * (draft → in_review → approved → published → archived).
 *
 * Example:
 * <code>
 * #[Lifecycle(stages: LifecycleRegistry::FINDING_4_STAGE)]
 * class AuditFinding { ... }
 * </code>
 *
 * Foundation pattern P-4 (audit-s3). See
 * {@see \App\Lifecycle\LifecycleRegistry} for available presets and the
 * stage-array contract.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Lifecycle
{
    public function __construct(
        /** @var array<string, array{transitions: array<int, string>, tone: string}> */
        public readonly array $stages,
    ) {
    }
}
