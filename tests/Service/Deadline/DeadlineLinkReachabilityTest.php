<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Enum\RegisterType;
use App\EventSubscriber\ReadOnlyRoleSubscriber;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\Deadline\Configured\ConfiguredDeadlineSource;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Planning\Source\Adapter\RecurringDutyAdapter;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use App\Service\Register\RegisterReviewSource;
use App\Service\RiskApprovalConfigView;
use App\Service\Risk\RiskAcceptanceRequestSource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * W1 "act where reminded": whoever a deadline source reminds can open the page
 * the reminder links to. For every source (classes and config/deadline_types.yaml)
 * the route of linkOf() must exist and admit
 *   - every fallback role of responsibleOf(), and
 *   - a holder named on the record (owner, assignee) — a plain ROLE_USER
 *     account and a member of top management (read-only reviewer role),
 * through the route's #[IsGranted] (class + method), the /admin access rule
 * and ReadOnlyRoleSubscriber. Before W1 this would have caught the Maßnahme
 * edit link for top management and the retention link for the DPO.
 *
 * The routes and roles are read from the source code of linkOf() /
 * responsibleOf() (literal route names and role strings), so no record is
 * needed; roles computed at runtime are listed in DYNAMIC_ROLES.
 */
final class DeadlineLinkReachabilityTest extends KernelTestCase
{
    /** Holder accounts every source with record holders must reach. */
    private const array HOLDER_ACTORS = ['ROLE_USER', 'ROLE_TOP_MGMT'];

    /**
     * Sources whose holder field can only name an account with a given role,
     * because only that role creates the record (access review campaigns:
     * createdBy, the campaign form is ROLE_MANAGER).
     */
    private const array HOLDER_MIN_ROLE = [
        'access_review' => 'ROLE_MANAGER',
    ];

    /** Sources whose fallback role is computed: every value it can take. */
    private const array DYNAMIC_ROLES = [
        RegisterReviewSource::class => 'registerRoles',
        RecurringDutyAdapter::class => 'recurringDutyRoles',
        RiskAcceptanceRequestSource::class => 'approvalLevelRoles',
    ];

    private RouterInterface $router;
    private RoleHierarchyInterface $hierarchy;
    private ReadOnlyRoleSubscriber $readOnly;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->router = self::getContainer()->get('router');
        $this->hierarchy = self::getContainer()->get('security.role_hierarchy');
        $this->readOnly = new ReadOnlyRoleSubscriber($this->createStub(Security::class));
    }

    #[Test]
    public function everyReminderLinkIsReachableForWhoIsReminded(): void
    {
        $registry = self::getContainer()->get(DeadlineSourceRegistry::class);
        $failures = [];
        $checked = 0;
        foreach ($registry->all() as $source) {
            [$routes, $roles, $hasHolders] = $this->describe($source);
            $holders = isset(self::HOLDER_MIN_ROLE[$source->slug()]) ? [self::HOLDER_MIN_ROLE[$source->slug()]] : self::HOLDER_ACTORS;
            $actors = array_values(array_unique([...$roles, ...($hasHolders ? $holders : [])]));
            foreach ($routes as $route) {
                foreach ($actors as $actor) {
                    ++$checked;
                    $why = $this->unreachableBecause($route, $actor);
                    if ($why !== null) {
                        $failures[] = sprintf('%s → %s for %s: %s', $source->slug(), $route, $actor, $why);
                    }
                }
            }
        }

        self::assertGreaterThan(80, $checked, 'too few source/route/role combinations found — did the parsing break?');
        self::assertSame([], $failures, "Reminder links the reminded person cannot open:\n" . implode("\n", $failures));
    }

    /** The checker itself: a manager-only form refuses holders, a responsible-aware one admits them. */
    #[Test]
    public function checkerSeesRoleGatesAndTheReadOnlyRule(): void
    {
        self::assertNull($this->unreachableBecause('app_risk_show', 'ROLE_USER'));
        self::assertNotNull($this->unreachableBecause('app_data_subject_request_new', 'ROLE_USER'), 'ROLE_MANAGER form');
        self::assertNotNull($this->unreachableBecause('app_risk_new', 'ROLE_TOP_MGMT'), 'form route for a read-only role');
        self::assertNotNull($this->unreachableBecause('admin_settings_data_retention', 'ROLE_DPO'), '/admin');
        self::assertNull($this->unreachableBecause('app_planning_action_item_edit', 'ROLE_TOP_MGMT'), 'responsible may act');
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: bool} routes, fallback roles, names holders on the record
     */
    private function describe(DeadlineSource $source): array
    {
        if ($source instanceof ConfiguredDeadlineSource) {
            $d = $source->definition;

            return [
                $d->route !== null ? [$d->route] : [],
                array_values(array_filter($d->roles, static fn (string $r): bool => str_starts_with($r, 'ROLE_'))),
                $d->holders !== [] || $d->deputies !== [],
            ];
        }

        $class = new \ReflectionClass($source);
        $link = $this->body($class->getMethod('linkOf'));
        $responsible = $this->body($class->getMethod('responsibleOf'));

        preg_match_all("/RecordLink\\(\\s*'([a-z0-9_]+)'/", $link, $routes);
        preg_match_all("/'(ROLE_[A-Z_]+)'/", $responsible, $roles);
        $roleList = $roles[1];
        // Roles kept in a class constant the method names (self::FOO).
        preg_match_all('/self::([A-Z_]+)/', $responsible, $constants);
        foreach ($constants[1] as $constant) {
            $value = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
            foreach (is_array($value) ? $value : [$value] as $role) {
                if (is_string($role) && str_starts_with($role, 'ROLE_')) {
                    $roleList[] = $role;
                }
            }
        }
        if (isset(self::DYNAMIC_ROLES[$class->getName()])) {
            $roleList = [...$roleList, ...$this->{self::DYNAMIC_ROLES[$class->getName()]}()];
        }
        $roleList = array_values(array_unique($roleList));

        return [
            array_values(array_unique($routes[1])),
            $roleList,
            (bool) preg_match('/holders\\(|withDeputies\\(|ofRecord\\(/', $responsible),
        ];
    }

    /** Null when the actor may open the route with GET, else the reason. */
    private function unreachableBecause(string $routeName, string $actor): ?string
    {
        $route = $this->router->getRouteCollection()->get($routeName);
        if ($route === null) {
            return 'route does not exist';
        }
        $reachable = $this->hierarchy->getReachableRoleNames([$actor]);
        $path = (string) preg_replace('#^/\{_locale\}#', '', $route->getPath());
        if (str_starts_with($path, '/admin') && !in_array('ROLE_ADMIN', $reachable, true)) {
            return 'path under /admin (access_control: ROLE_ADMIN)';
        }

        $controller = (string) $route->getDefault('_controller');
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
        if (!class_exists($class)) {
            return null; // framework controller
        }
        $reflection = new \ReflectionClass($class);
        $attributes = [...$reflection->getAttributes(IsGranted::class)];
        if ($reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(IsGranted::class)];
        }
        $builtForReviewers = false;
        foreach ($attributes as $attribute) {
            $isGranted = $attribute->newInstance();
            if (!$this->grants($isGranted->attribute, $reachable)) {
                return 'IsGranted ' . (string) $isGranted->attribute;
            }
            $builtForReviewers = $builtForReviewers || $this->isBuiltForReviewers($isGranted->attribute);
        }

        $readOnlyActor = in_array('ROLE_AUDITOR', $reachable, true) && !in_array('ROLE_MANAGER', $reachable, true);
        if ($readOnlyActor && !$builtForReviewers && $this->readOnly->isRestricted($routeName, 'GET')) {
            return 'ReadOnlyRoleSubscriber (form route for a read-only role)';
        }

        return null;
    }

    /**
     * @param list<string> $reachable
     */
    private function grants(string|Expression $attribute, array $reachable): bool
    {
        if ($attribute instanceof Expression) {
            $language = new ExpressionLanguage();
            $language->addFunction(new ExpressionFunction(
                'is_granted',
                static fn (): string => '',
                fn (array $variables, string|Expression $inner, mixed $subject = null): bool => $this->grants($inner, $reachable),
            ));

            return (bool) $language->evaluate($attribute, ['subject' => null, 'args' => [], 'user' => null, 'token' => null, 'request' => null]);
        }
        if (str_starts_with($attribute, 'ROLE_')) {
            return in_array($attribute, $reachable, true);
        }
        if (str_starts_with($attribute, 'PERSONA_')) {
            return in_array('ROLE_' . substr($attribute, 8), $reachable, true);
        }

        // Record voters (view/edit, RECORD_RESPONSIBLE, report access): the reminded person is responsible
        // for the record and in its organisation — a voter decision this test cannot make without data.
        return true;
    }

    private function isBuiltForReviewers(string|Expression $attribute): bool
    {
        if ($attribute instanceof Expression) {
            return str_contains((string) $attribute, RecordResponsibleVoter::RESPONSIBLE);
        }
        $reviewer = (new \ReflectionClassConstant(ReadOnlyRoleSubscriber::class, 'REVIEWER_ATTRIBUTES'))->getValue();

        return in_array($attribute, $reviewer, true);
    }

    private function body(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];

        return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /** @return list<string> */
    private function registerRoles(): array
    {
        return array_values(array_unique(array_map(static fn (RegisterType $t): string => $t->maintainerRole(), RegisterType::cases())));
    }

    /** @return list<string> */
    private function approvalLevelRoles(): array
    {
        return array_map(RiskApprovalConfigView::approverRole(...), ['manager', 'executive']);
    }

    /** @return list<string> */
    private function recurringDutyRoles(): array
    {
        $roles = [];
        foreach (RecurringDutyCatalog::DUTIES as $duty) {
            $roles = [...$roles, ...($duty['roles'] ?? [])];
        }

        return array_values(array_unique($roles));
    }
}
