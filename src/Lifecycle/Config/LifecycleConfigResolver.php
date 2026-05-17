<?php

declare(strict_types=1);

namespace App\Lifecycle\Config;

/**
 * Default no-op resolver used until the full YAML-based config layer
 * is implemented (Task 7 deliverable; deferred to Sprint X).
 *
 * Returns empty metadata arrays — callers treat absent keys as "not configured"
 * (e.g. reason_required defaults to false, no role guards).
 */
final class LifecycleConfigResolver implements LifecycleConfigResolverInterface
{
    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function resolve(object $subject, string $workflowName, string $transitionName): array
    {
        return [];
    }

    public function get(object $subject, string $workflowName, string $transitionName, string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
