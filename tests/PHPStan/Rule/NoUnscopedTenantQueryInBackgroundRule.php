<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids unscoped repository reads on tenant-owned entities from code that
 * runs OUTSIDE an HTTP request.
 *
 * Why this rule exists (two shipped defects, #1023 and #1026):
 *   TenantFilterSubscriber arms the Doctrine `tenant_filter` only on
 *   kernel.request. In a cron command, a Messenger worker or an async admin
 *   job there is no request, so the filter parameter is never set, TenantFilter
 *   returns '' and filters NOTHING. Every findAll() then reads across ALL
 *   tenants — silently, and only in those deployments.
 *
 *   Scheduled reports aggregated foreign tenants' risks into a tenant's report;
 *   three export jobs exported every tenant's rows under
 *   APP_ASYNC_JOB_RUNNER=messenger. Both looked correct in review and were
 *   invisible under the default in_request runner, which masks the bug because
 *   the originating request had already armed the filter.
 *
 * The rule is type-driven, not textual: it resolves the call's return type and
 * only fires when the entity actually declares a `tenant` association. A grep
 * cannot do this — it flags every findAll() in every catalogue command and
 * drowns the real hits.
 *
 * Legitimate ways to satisfy it:
 *   - pass the tenant into the query (findBy(['tenant' => $t]), or a repository
 *     method that takes a Tenant — see CapturePortfolioSnapshotCommand), or
 *   - set the tenant via TenantContext, which arms the filter (see
 *     ExecuteJobHandler).
 *
 * Genuinely instance-wide maintenance code (catalogue rebuilds, migrations)
 * is expected to be frozen in phpstan-baseline.neon rather than exempted here —
 * a new one should be a conscious, reviewed decision.
 *
 * @implements Rule<MethodCall>
 */
final class NoUnscopedTenantQueryInBackgroundRule implements Rule
{
    /** Repository reads that return every row unless the filter is armed. */
    private const UNSCOPED_METHODS = ['findAll'];

    /** Code paths that run without an HTTP request. */
    private const BACKGROUND_FILE_PATTERNS = [
        '#/src/Command/#',
        '#/src/Job/#',
        '#/src/MessageHandler/#',
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        if (!in_array($node->name->name, self::UNSCOPED_METHODS, true)) {
            return [];
        }

        if (!$this->isBackgroundFile($scope->getFile())) {
            return [];
        }

        $entityClass = $this->resolveEntityClass($scope, $node);
        if ($entityClass === null || !$this->ownsTenant($entityClass)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Unscoped %s() on tenant-owned entity %s outside an HTTP request. '
                . 'The Doctrine tenant_filter is only armed on kernel.request, so this reads across ALL tenants. '
                . 'Pass the tenant into the query, or set it via TenantContext (which arms the filter).',
                $node->name->name,
                $entityClass,
            ))
            ->identifier('tenant.unscopedBackgroundQuery')
            ->build(),
        ];
    }

    private function isBackgroundFile(string $file): bool
    {
        foreach (self::BACKGROUND_FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive the queried entity from the call's return type (array<int, T>).
     * Returns null when the type is unresolvable — staying silent beats
     * guessing, since a false positive here blocks the build.
     */
    private function resolveEntityClass(Scope $scope, MethodCall $node): ?string
    {
        $valueType = $scope->getType($node)->getIterableValueType();

        foreach ($valueType->getObjectClassNames() as $className) {
            if (str_starts_with($className, 'App\\Entity\\')) {
                return $className;
            }
        }

        return null;
    }

    /** An entity is tenant-owned when it declares a `tenant` property. */
    private function ownsTenant(string $entityClass): bool
    {
        if (!$this->reflectionProvider->hasClass($entityClass)) {
            return false;
        }

        return $this->reflectionProvider->getClass($entityClass)->hasProperty('tenant');
    }
}
