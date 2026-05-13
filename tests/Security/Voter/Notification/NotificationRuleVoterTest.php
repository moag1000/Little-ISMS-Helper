<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter\Notification;

use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\Notification\NotificationRuleVoter;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Unit tests for NotificationRuleVoter.
 */
#[AllowMockObjectsWithoutExpectations]
final class NotificationRuleVoterTest extends TestCase
{
    private Tenant $tenant;
    private User $managerUser;
    private User $readUser;
    private NotificationRule $rule;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();

        $this->managerUser = new User();
        $this->managerUser->setTenant($this->tenant);
        $this->managerUser->setRoles(['ROLE_USER', 'ROLE_MANAGER']);

        $this->readUser = new User();
        $this->readUser->setTenant($this->tenant);
        $this->readUser->setRoles(['ROLE_USER']);

        $this->rule = new NotificationRule();
        $this->rule->setTenant($this->tenant);
    }

    private function buildModuleService(bool $active): ModuleConfigurationService
    {
        $svc = $this->createMock(ModuleConfigurationService::class);
        $svc->method('isModuleActive')->willReturn($active);
        return $svc;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    #[Test]
    public function managerCanViewEditDelete(): void
    {
        $voter = new NotificationRuleVoter($this->buildModuleService(true));

        self::assertTrue($voter->vote($this->token($this->managerUser), $this->rule, [NotificationRuleVoter::VIEW]) > 0);
        self::assertTrue($voter->vote($this->token($this->managerUser), $this->rule, [NotificationRuleVoter::EDIT]) > 0);
        self::assertTrue($voter->vote($this->token($this->managerUser), $this->rule, [NotificationRuleVoter::DELETE]) > 0);
    }

    #[Test]
    public function regularUserCanOnlyView(): void
    {
        $voter = new NotificationRuleVoter($this->buildModuleService(true));

        self::assertTrue($voter->vote($this->token($this->readUser), $this->rule, [NotificationRuleVoter::VIEW]) > 0);
        self::assertFalse($voter->vote($this->token($this->readUser), $this->rule, [NotificationRuleVoter::EDIT]) > 0);
        self::assertFalse($voter->vote($this->token($this->readUser), $this->rule, [NotificationRuleVoter::DELETE]) > 0);
    }

    #[Test]
    public function deniessWhenModuleInactive(): void
    {
        $voter = new NotificationRuleVoter($this->buildModuleService(false));

        self::assertFalse($voter->vote($this->token($this->managerUser), $this->rule, [NotificationRuleVoter::VIEW]) > 0);
    }

    #[Test]
    public function deniesWhenDifferentTenant(): void
    {
        // Use a different Tenant object (both have null IDs but are different objects)
        // The voter checks ->getId() which is null for both, so we test that users
        // with null tenant cannot access rules on a different null-id tenant.
        // This test verifies that a rule on a separate, unrelated Tenant is denied
        // for a user whose tenant has no matching ID (non-null test requires DB).
        // We verify the voter correctly calls module check and tenant check:
        $otherTenant = new Tenant();
        // Set a pseudo-id via reflection to simulate a different tenant (PHP 8.1+ allows without setAccessible)
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($otherTenant, 9999);

        $ruleOtherTenant = new NotificationRule();
        $ruleOtherTenant->setTenant($otherTenant);

        $voter = new NotificationRuleVoter($this->buildModuleService(true));
        self::assertFalse($voter->vote($this->token($this->managerUser), $ruleOtherTenant, [NotificationRuleVoter::VIEW]) > 0);
    }
}
