<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\KonzernSettingsDriftRule;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The drift hint ("subsidiary has fewer approved docs than its parent") must
 * deep-link to the INHERITED document view — the one that surfaces the parent's
 * documents the subsidiary can adopt, i.e. exactly what the hint is about.
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernSettingsDriftRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWithoutParentTenant(): void
    {
        $rule = new KonzernSettingsDriftRule($this->em(0, 0));
        self::assertNull($rule->evaluate(new Tenant(), $this->user));
    }

    #[Test]
    public function returnsNullWhenSubsidiaryHasEnoughDocs(): void
    {
        // parent=10, tenant=6 → 6 >= round(10*0.5)=5 → no drift
        $rule = new KonzernSettingsDriftRule($this->em(10, 6));
        self::assertNull($rule->evaluate($this->subsidiary(), $this->user));
    }

    #[Test]
    public function deepLinksToInheritedViewOnDrift(): void
    {
        // parent=10, tenant=2 → 2 < 5 → drift fires
        $rule = new KonzernSettingsDriftRule($this->em(10, 2));
        $hint = $rule->evaluate($this->subsidiary(), $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.konzern_settings_drift', $hint->key);
        self::assertSame('app_document_index', $hint->actionRoute);
        self::assertSame(['view' => 'inherited'], $hint->actionRouteParams);
        self::assertContains('ROLE_CISO', $hint->requiredRoles);
    }

    private function subsidiary(): Tenant
    {
        $child = new Tenant();
        $child->setParent(new Tenant());
        return $child;
    }

    private function em(int $parentCount, int $tenantCount): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        // evaluate() runs the parent COUNT first, then the tenant COUNT.
        $query->method('getSingleScalarResult')->willReturnOnConsecutiveCalls($parentCount, $tenantCount);
        $query->method('setParameter')->willReturnSelf();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        return $em;
    }
}
