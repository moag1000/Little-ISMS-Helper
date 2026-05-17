<?php

declare(strict_types=1);

namespace App\Lifecycle\Config;

/**
 * Contract for the two-layer lifecycle config resolver.
 * Extracted to enable mocking in tests (LifecycleConfigResolver is final).
 */
interface LifecycleConfigResolverInterface
{
    /**
     * @return array<string, mixed>  Effective metadata for the given transition,
     *                               YAML keys plus tenant overrides.
     */
    public function resolve(object $subject, string $workflowName, string $transitionName): array;

    public function get(object $subject, string $workflowName, string $transitionName, string $key, mixed $default = null): mixed;
}
