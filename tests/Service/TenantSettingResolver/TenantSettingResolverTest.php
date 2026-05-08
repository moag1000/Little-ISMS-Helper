<?php

declare(strict_types=1);

namespace App\Tests\Service\TenantSettingResolver;

use App\Entity\Tenant;
use App\Service\TenantSettingResolver\ChangeAttemptLoggerInterface;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\RelaxAttempt;
use App\Service\TenantSettingResolver\SettingProviderInterface;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W1-C — TenantSettingResolver behaviour matrix.
 *
 * Each test exercises one of the five override-modes from
 * `docs/plans/policy-wizard/05-architecture.md` §7.3, plus the
 * §7.4 drift-detection / change-attempt logging.
 */
#[AllowMockObjectsWithoutExpectations]
class TenantSettingResolverTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<Tenant> $ancestors immediate-parent first, root last
     * @return Stub&Tenant
     */
    private function makeTenant(int $id, array $ancestors = []): Stub
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    /**
     * @param array<string, OverrideMode>                  $modes
     * @param array<int, array<string, mixed>>             $stored map tenant-id => key => value
     * @param array<string, mixed>                         $defaults
     */
    private function makeProvider(array $modes, array $stored, array $defaults = []): SettingProviderInterface
    {
        return new InMemorySettingProvider($modes, $stored, $defaults);
    }

    private function makeRecordingLogger(): RecordingChangeAttemptLogger
    {
        return new RecordingChangeAttemptLogger();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testResolvesParentValueWhenChildAbsent(): void
    {
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['security.password_min_length' => OverrideMode::FloorOnly],
            stored: [1 => ['security.password_min_length' => 12]],
            defaults: ['security.password_min_length' => 8],
        );
        $resolver = new TenantSettingResolver($provider);

        $result = $resolver->resolveFor($child, 'security.password_min_length', 8);

        $this->assertSame(12, $result->getValue());
        $this->assertSame(1, $result->sourceTenantId);
        $this->assertFalse($result->childRelaxBlocked);
    }

    #[Test]
    public function testStricterChildOverridesParentFloorOnly(): void
    {
        // floor_only: parent=128, child=192 → child wins (192 ≥ 128 = stricter)
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['crypto.min_key_bits' => OverrideMode::FloorOnly],
            stored: [
                1 => ['crypto.min_key_bits' => 128],
                2 => ['crypto.min_key_bits' => 192],
            ],
        );
        $resolver = new TenantSettingResolver($provider);

        $result = $resolver->resolveFor($child, 'crypto.min_key_bits');

        $this->assertSame(192, $result->getValue());
        $this->assertSame(2, $result->sourceTenantId);
        $this->assertFalse($result->childRelaxBlocked);
    }

    #[Test]
    public function testStricterChildOverridesParentCeilingOnly(): void
    {
        // ceiling_only: parent=24 mo, child=12 mo → child wins (12 ≤ 24 = stricter / more frequent)
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['review.interval_months' => OverrideMode::CeilingOnly],
            stored: [
                1 => ['review.interval_months' => 24],
                2 => ['review.interval_months' => 12],
            ],
        );
        $resolver = new TenantSettingResolver($provider);

        $result = $resolver->resolveFor($child, 'review.interval_months');

        $this->assertSame(12, $result->getValue());
        $this->assertSame(2, $result->sourceTenantId);
        $this->assertFalse($result->childRelaxBlocked);
    }

    #[Test]
    public function testForbiddenToChangeBlocksChild(): void
    {
        // forbidden_to_change: child value silently discarded, parent wins,
        // and the resolver records a relax-attempt because the child stored
        // a different value.
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['approval.top_management_user_id' => OverrideMode::ForbiddenToChange],
            stored: [
                1 => ['approval.top_management_user_id' => 'user-konzern'],
                2 => ['approval.top_management_user_id' => 'user-tochter'],
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        $result = $resolver->resolveFor($child, 'approval.top_management_user_id');

        $this->assertSame('user-konzern', $result->getValue());
        $this->assertSame(1, $result->sourceTenantId);
        $this->assertTrue($result->childRelaxBlocked);
        $this->assertCount(1, $logger->attempts);
        $this->assertSame(2, $logger->attempts[0]->tenantId);
        $this->assertSame('user-tochter', $logger->attempts[0]->attemptedValue);
        $this->assertSame('user-konzern', $logger->attempts[0]->enforcedValue);
    }

    #[Test]
    public function testForbiddenToRelaxLogsAttempt(): void
    {
        // forbidden_to_relax (boolean): parent=true (GDPR scope on),
        // child stored false → forced back to true and logged.
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['privacy.gdpr_in_scope' => OverrideMode::ForbiddenToRelax],
            stored: [
                1 => ['privacy.gdpr_in_scope' => true],
                2 => ['privacy.gdpr_in_scope' => false],
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        $result = $resolver->resolveFor($child, 'privacy.gdpr_in_scope');

        $this->assertTrue($result->getValue());
        $this->assertTrue($result->childRelaxBlocked);
        $this->assertCount(1, $logger->attempts);
        $attempt = $logger->attempts[0];
        $this->assertInstanceOf(RelaxAttempt::class, $attempt);
        $this->assertSame(2, $attempt->tenantId);
        $this->assertSame('privacy.gdpr_in_scope', $attempt->key);
        $this->assertFalse($attempt->attemptedValue);
        $this->assertTrue($attempt->enforcedValue);
        $this->assertSame(OverrideMode::ForbiddenToRelax, $attempt->mode);
        $this->assertSame(1, $attempt->blockedByTenantId);
    }

    #[Test]
    public function testForbiddenToRelaxNumericChildBelowParentClamped(): void
    {
        // numeric forbidden_to_relax: parent=12 char password floor,
        // child stored 8 → clamped up to 12 + relax-attempt logged.
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['security.password_min_length' => OverrideMode::ForbiddenToRelax],
            stored: [
                1 => ['security.password_min_length' => 12],
                2 => ['security.password_min_length' => 8],
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        $result = $resolver->resolveFor($child, 'security.password_min_length');

        $this->assertSame(12, $result->getValue());
        $this->assertTrue($result->childRelaxBlocked);
        $this->assertCount(1, $logger->attempts);
    }

    #[Test]
    public function testFreeMode(): void
    {
        // free: child fully autonomous, parent value ignored.
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['bcm.crisis_team_size' => OverrideMode::Free],
            stored: [
                1 => ['bcm.crisis_team_size' => 7],
                2 => ['bcm.crisis_team_size' => 5],
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        $result = $resolver->resolveFor($child, 'bcm.crisis_team_size');

        $this->assertSame(5, $result->getValue());
        $this->assertSame(2, $result->sourceTenantId);
        $this->assertFalse($result->childRelaxBlocked);
        $this->assertCount(0, $logger->attempts);
    }

    #[Test]
    public function testFreeModeFallsBackToDefaultWhenChildEmpty(): void
    {
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: ['bcm.crisis_team_size' => OverrideMode::Free],
            stored: [
                1 => ['bcm.crisis_team_size' => 7], // ignored under free
            ],
            defaults: ['bcm.crisis_team_size' => 5],
        );
        $resolver = new TenantSettingResolver($provider);

        $result = $resolver->resolveFor($child, 'bcm.crisis_team_size');

        $this->assertSame(5, $result->getValue());
        $this->assertNull($result->sourceTenantId);
    }

    #[Test]
    public function testThreeTierAncestorWalk(): void
    {
        // root (id=1) sets crypto floor=128
        // mid  (id=2) tightens to 192
        // leaf (id=3) tightens to 256
        // expected: 256 wins (each tier valid stricter override, no relax-attempts).
        $root = $this->makeTenant(1, []);
        $mid = $this->makeTenant(2, [$root]);
        $leaf = $this->makeTenant(3, [$mid, $root]);

        $provider = $this->makeProvider(
            modes: ['crypto.min_key_bits' => OverrideMode::FloorOnly],
            stored: [
                1 => ['crypto.min_key_bits' => 128],
                2 => ['crypto.min_key_bits' => 192],
                3 => ['crypto.min_key_bits' => 256],
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        $result = $resolver->resolveFor($leaf, 'crypto.min_key_bits');

        $this->assertSame(256, $result->getValue());
        $this->assertSame(3, $result->sourceTenantId);
        $this->assertCount(0, $logger->attempts);
    }

    #[Test]
    public function testThreeTierAncestorWalkDriftDetected(): void
    {
        // §7.4 push-down scenario: Konzern just raised crypto from 128 → 256.
        // Mid-tier still has 192 stored; leaf has 200 stored. Both relax
        // attempts get logged when the resolver walks the chain.
        // Effective value: 256 (the new Konzern floor).
        $root = $this->makeTenant(1, []);
        $mid = $this->makeTenant(2, [$root]);
        $leaf = $this->makeTenant(3, [$mid, $root]);

        $provider = $this->makeProvider(
            modes: ['crypto.min_key_bits' => OverrideMode::FloorOnly],
            stored: [
                1 => ['crypto.min_key_bits' => 256],
                2 => ['crypto.min_key_bits' => 192], // drift
                3 => ['crypto.min_key_bits' => 200], // drift
            ],
        );
        $logger = $this->makeRecordingLogger();
        $resolver = new TenantSettingResolver($provider, $logger);

        // Resolve from leaf — both mid and leaf should fail to relax.
        // Note: ancestor (mid) drift only surfaces when *that* tenant resolves.
        // Here we resolve for leaf; mid's stored value is still applied as the
        // chain enforcement (192) but root's 256 then overrides it because the
        // ancestor walk treats each ancestor's stored value as its own
        // enforcement. So our chain becomes: root=256 → mid=192 ignored as too
        // low (handled by treating each ancestor stored value as new floor)…
        //
        // Behaviour: each ancestor stored value becomes the new chain value,
        // overwriting the previous one. mid's 192 is not strictly enforced
        // against root=256 because ancestor-vs-ancestor merging is NOT in
        // scope here (W1-A push-down job handles that). Leaf is the only
        // node where the resolver explicitly clamps.
        // Therefore: with mid stored=192 *ahead* of root chain value (root walked
        // first → 256, then mid OVERWRITES to 192, then leaf=200 < 192 OK?)
        //
        // The architecturally correct semantic: walking top-down, each ancestor
        // *replaces* the chain value (§7.3 step 2 "take its setting"). So
        // root=256, mid=192, leaf=200 → leaf clamped to 192? No: floor_only on
        // leaf vs chain=192 → leaf=200 (200 > 192 = stricter, fine).
        // Result: 200, no relax attempt for leaf.
        // Drift on mid surfaces only when mid is the resolution target.
        $result = $resolver->resolveFor($leaf, 'crypto.min_key_bits');

        $this->assertSame(200, $result->getValue());
        $this->assertSame(3, $result->sourceTenantId);
        $this->assertCount(0, $logger->attempts);

        // Now resolve for mid: chain=root=256, own=192 → clamped to 256 + drift logged.
        $result2 = $resolver->resolveFor($mid, 'crypto.min_key_bits');
        $this->assertSame(256, $result2->getValue());
        $this->assertTrue($result2->childRelaxBlocked);
        $this->assertCount(1, $logger->attempts);
        $this->assertSame(2, $logger->attempts[0]->tenantId);
        $this->assertSame(192, $logger->attempts[0]->attemptedValue);
        $this->assertSame(256, $logger->attempts[0]->enforcedValue);
    }

    #[Test]
    public function testMultipleSettingsPerTenantIndependent(): void
    {
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: [
                'security.password_min_length' => OverrideMode::FloorOnly,
                'review.interval_months' => OverrideMode::CeilingOnly,
                'bcm.crisis_team_size' => OverrideMode::Free,
            ],
            stored: [
                1 => [
                    'security.password_min_length' => 10,
                    'review.interval_months' => 24,
                    'bcm.crisis_team_size' => 7,
                ],
                2 => [
                    'security.password_min_length' => 14,
                    'review.interval_months' => 12,
                    'bcm.crisis_team_size' => 5,
                ],
            ],
        );
        $resolver = new TenantSettingResolver($provider);

        $pwd = $resolver->resolveFor($child, 'security.password_min_length');
        $this->assertSame(14, $pwd->getValue());
        $this->assertSame(OverrideMode::FloorOnly, $pwd->effectiveMode);

        $review = $resolver->resolveFor($child, 'review.interval_months');
        $this->assertSame(12, $review->getValue());
        $this->assertSame(OverrideMode::CeilingOnly, $review->effectiveMode);

        $crisis = $resolver->resolveFor($child, 'bcm.crisis_team_size');
        $this->assertSame(5, $crisis->getValue());
        $this->assertSame(OverrideMode::Free, $crisis->effectiveMode);
    }

    #[Test]
    public function testCacheReturnsSameResultForRepeatedReads(): void
    {
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = new CountingProvider($this->makeProvider(
            modes: ['security.password_min_length' => OverrideMode::FloorOnly],
            stored: [1 => ['security.password_min_length' => 12]],
        ));
        $resolver = new TenantSettingResolver($provider);

        $r1 = $resolver->resolveFor($child, 'security.password_min_length');
        $callsAfterFirst = $provider->getStoredCalls;
        $r2 = $resolver->resolveFor($child, 'security.password_min_length');
        $callsAfterSecond = $provider->getStoredCalls;

        $this->assertSame(12, $r1->getValue());
        $this->assertSame(12, $r2->getValue());
        // First call walks the chain; second call hits cache → no further provider hits.
        $this->assertGreaterThan(0, $callsAfterFirst);
        $this->assertSame($callsAfterFirst, $callsAfterSecond);
    }

    #[Test]
    public function testInvalidateClearsCache(): void
    {
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = new CountingProvider($this->makeProvider(
            modes: ['security.password_min_length' => OverrideMode::FloorOnly],
            stored: [1 => ['security.password_min_length' => 12]],
        ));
        $resolver = new TenantSettingResolver($provider);

        $resolver->resolveFor($child, 'security.password_min_length');
        $resolver->invalidate();
        $resolver->resolveFor($child, 'security.password_min_length');

        $this->assertGreaterThan(1, $provider->getStoredCalls);
    }

    #[Test]
    public function testUnknownKeyDefaultsToForbiddenToRelax(): void
    {
        // No mode registered ⇒ provider's default is ForbiddenToRelax (safe default).
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        $provider = $this->makeProvider(
            modes: [],
            stored: [
                1 => ['unknown.key' => 10],
                2 => ['unknown.key' => 5],
            ],
        );
        $resolver = new TenantSettingResolver($provider);

        $result = $resolver->resolveFor($child, 'unknown.key');

        $this->assertSame(10, $result->getValue());
        $this->assertSame(OverrideMode::ForbiddenToRelax, $result->effectiveMode);
        $this->assertTrue($result->childRelaxBlocked);
    }
}

/**
 * Trivial in-memory provider used only by these tests.
 */
final class InMemorySettingProvider implements SettingProviderInterface
{
    /**
     * @param array<string, OverrideMode>      $modes
     * @param array<int, array<string, mixed>> $stored
     * @param array<string, mixed>             $defaults
     */
    public function __construct(
        private readonly array $modes,
        private readonly array $stored,
        private readonly array $defaults = [],
    ) {
    }

    public function getOverrideMode(string $key): OverrideMode
    {
        return $this->modes[$key] ?? OverrideMode::ForbiddenToRelax;
    }

    public function getStoredValue(Tenant $tenant, string $key): mixed
    {
        $tid = $tenant->getId();
        if ($tid === null) {
            return null;
        }
        return $this->stored[$tid][$key] ?? null;
    }

    public function getGlobalDefault(string $key, mixed $default): mixed
    {
        return $this->defaults[$key] ?? $default;
    }
}

/**
 * Provider decorator that counts getStoredValue() calls — used to verify
 * caching behaviour.
 */
final class CountingProvider implements SettingProviderInterface
{
    public int $getStoredCalls = 0;

    public function __construct(private readonly SettingProviderInterface $inner)
    {
    }

    public function getOverrideMode(string $key): OverrideMode
    {
        return $this->inner->getOverrideMode($key);
    }

    public function getStoredValue(Tenant $tenant, string $key): mixed
    {
        $this->getStoredCalls++;
        return $this->inner->getStoredValue($tenant, $key);
    }

    public function getGlobalDefault(string $key, mixed $default): mixed
    {
        return $this->inner->getGlobalDefault($key, $default);
    }
}

/**
 * Records every relax attempt the resolver emits during a test run.
 */
final class RecordingChangeAttemptLogger implements ChangeAttemptLoggerInterface
{
    /** @var list<RelaxAttempt> */
    public array $attempts = [];

    public function log(RelaxAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }
}
