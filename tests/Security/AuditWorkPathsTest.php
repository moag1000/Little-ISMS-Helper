<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Security\Voter\AuditWorkVoter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S27 (8th assessment F-01c/F-02/F-03): top management, the group CISO and the
 * group auditor inherit ROLE_AUDITOR to READ. They never do audit work by that
 * inheritance (verdicts, report, findings, verifications, SoA snapshots,
 * effectiveness reviews) — {@see AuditWorkVoter}.
 *
 * Discovery: every state-changing route whose role attributes (class and
 * method, plain role strings) a user holding only one of those reviewer roles
 * passes must be listed below with the reason it is meant for that reviewer.
 * A new write route gated by ROLE_AUDITOR alone fails here.
 */
final class AuditWorkPathsTest extends KernelTestCase
{
    private const array REVIEWER_ROLES = ['ROLE_TOP_MGMT', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'];

    /** Write routes a pure reviewer role may reach by its role attributes, with the reason. */
    private const array ALLOWED = [
        'app_analytics_export_dispatch' => 'export of what the reviewer reads (reviewers download, ReadOnlyRoleSubscriber allow list)',
        'app_management_decision_approve' => 'Leitungsentscheidung: names ROLE_TOP_MGMT, the decision belongs to top management',
        'app_management_decision_reject' => 'Leitungsentscheidung: names ROLE_TOP_MGMT, the decision belongs to top management',
    ];

    #[Test]
    public function reviewerRolesDoNoAuditWork(): void
    {
        $security = static::getContainer()->get(Security::class);
        foreach (self::REVIEWER_ROLES as $role) {
            $reviewer = $this->user([$role]);
            self::assertFalse($security->isGrantedForUser($reviewer, AuditWorkVoter::AUDIT_WORK), $role);
            self::assertFalse($security->isGrantedForUser($reviewer, AuditWorkVoter::AUDIT_WORK, new InternalAudit()), $role);
            self::assertFalse($security->isGrantedForUser($reviewer, AuditWorkVoter::AUDIT_WORK, new AuditFinding()), $role);
            self::assertTrue($security->isGrantedForUser($reviewer, 'ROLE_AUDITOR'), $role . ' still reads like an auditor');
        }
        foreach ([['ROLE_AUDITOR'], ['ROLE_MANAGER'], ['ROLE_ISB'], ['ROLE_SUPER_ADMIN'], ['ROLE_TOP_MGMT', 'ROLE_AUDITOR']] as $roles) {
            self::assertTrue($security->isGrantedForUser($this->user($roles), AuditWorkVoter::AUDIT_WORK), implode('+', $roles));
        }
        self::assertFalse($security->isGrantedForUser($this->user(['ROLE_USER']), AuditWorkVoter::AUDIT_WORK));
    }

    #[Test]
    public function noWriteRouteOpensToReviewerRolesByRoleAlone(): void
    {
        $security = static::getContainer()->get(Security::class);
        $router = static::getContainer()->get(RouterInterface::class);
        $offending = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = $route->getMethods();
            if ($methods !== [] && array_diff($methods, ['GET', 'HEAD']) === []) {
                continue;
            }
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'App\\') || !str_contains($controller, '::')) {
                continue;
            }
            [$class, $method] = explode('::', $controller, 2);
            if (!method_exists($class, $method)) {
                continue;
            }
            $roles = $this->plainRoleAttributes($class, $method);
            if (array_intersect($roles, ['ROLE_AUDITOR', AuditWorkVoter::AUDIT_WORK]) === []) {
                continue; // only routes gated for auditors
            }
            foreach (self::REVIEWER_ROLES as $reviewerRole) {
                $reviewer = $this->user([$reviewerRole]);
                $passes = array_reduce($roles, static fn (bool $carry, string $r): bool
                    => $carry && $security->isGrantedForUser($reviewer, $r), true);
                if ($passes && !isset(self::ALLOWED[$name])) {
                    $offending[] = $name . ' (' . $reviewerRole . ')';
                }
            }
        }

        self::assertSame([], array_values(array_unique($offending)), 'Write route gated by ROLE_AUDITOR alone: use AuditWorkVoter::AUDIT_WORK or list it with a reason.');
    }

    /** @return list<string> */
    private function plainRoleAttributes(string $class, string $method): array
    {
        $roles = [];
        $reflections = [new \ReflectionClass($class), new \ReflectionMethod($class, $method)];
        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes(IsGranted::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $argument = $arguments[0] ?? $arguments['attribute'] ?? null;
                $hasSubject = isset($arguments[1]) || isset($arguments['subject']);
                // Plain string attributes without subject (roles, AUDIT_WORK): decidable here.
                if (is_string($argument) && !$hasSubject) {
                    $roles[] = $argument;
                }
            }
        }

        return $roles;
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        return (new User())->setEmail(strtolower(implode('', $roles)) . '@awp.test')->setRoles($roles)->setIsActive(true);
    }
}
