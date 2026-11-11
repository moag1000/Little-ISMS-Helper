<?php

declare(strict_types=1);

namespace App\Tests\Security\RouteSweep;

use App\Attribute\RequiresModule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Job\JobStatusService;
use App\Service\ModuleConfigurationService;
use App\Tests\PHPStan\Rule\Gate\ModuleGateRule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * T4 router sweeps for the module gate (Gate 11, M1–M3 of the counter-check) and job ids (Gate 90, B4–B6).
 *
 *  - Module off: every GET route that serves an optional module — named by `#[RequiresModule]` or by Gate 11's own
 *    route patterns (ModuleGateRule::CONTROLLER_MODULES / DOWNLOAD_MODULES) — answers an administrator of an
 *    organisation with only the required modules with a redirect, 403 or 404, or a page that says the module is
 *    off. A gate that only passes a flag into render(), points the wrong way or needs a second condition shows
 *    up as a 200 page.
 *  - Foreign job id: a job of person A (with a marker in its payload) is asked for through every GET route with a
 *    free string variable by person B of another organisation. B must not get what A gets (a non-error answer to
 *    both, or the marker in B's answer): a wrapper service that reads the job store without AsyncJobVoter, a job
 *    id taken from `$_GET`, a dynamic call — whatever the regex gate missed.
 */
final class ModuleAndJobRouteSweepTest extends WebTestCase
{
    /** Part of messages:common.module_not_active (DE/EN), the soft "module off" page (see DeadlineLinkReachabilityTest). */
    private const array MODULE_OFF_TEXT = ['ist nicht aktiviert. Bitte Setup', 'is not active. Please activate via Setup'];
    private const float MIN_SWEPT = 0.6;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function aModuleRouteIsClosedWhileItsModuleIsOff(): void
    {
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $required = array_values($modules->resolveModuleDependencies(array_keys($modules->getRequiredModules()))['modules']);
        [$tenant, $admin] = $this->organisation('modoff', $required);
        $targets = new RouteTargets(static::getContainer()->get(RouterInterface::class), $this->em(), $tenant);

        $failures = [];
        $skipped = [];
        $total = 0;
        $swept = 0;
        foreach ($targets->appRoutes() as $name => $target) {
            $methods = $target['route']->getMethods();
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }
            $expected = array_values(array_diff($this->modulesOf($name, $target), $required));
            if ($expected === []) {
                continue;
            }
            ++$total;
            $url = $targets->url($name, $target);
            if ($url['url'] === null) {
                $skipped[] = (string) $url['skipped'];
                continue;
            }
            ++$swept;
            $response = $this->isolated(fn () => $this->call($admin, (string) $url['url']));
            $body = (string) $response->getContent();
            if ($response->getStatusCode() === 200 && !$this->saysModuleOff($body)) {
                $failures[] = sprintf('GET %s (%s, module %s off) → 200', $url['url'], $name, implode('/', $expected));
            }
        }

        self::assertGreaterThan(50, $total, 'module route discovery broke');
        self::assertGreaterThanOrEqual((int) ($total * self::MIN_SWEPT), $swept, "Too few module routes swept ({$swept}/{$total}):\n" . implode("\n", array_slice($skipped, 0, 60)));
        self::assertSame([], $failures, "Module routes reachable with their module off:\n" . implode("\n", $failures));
    }

    #[Test]
    public function aForeignJobIdOpensNothing(): void
    {
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $all = array_values($modules->resolveModuleDependencies(array_keys($modules->getAllModules()))['modules']);
        [$tenantA, $ownerA] = $this->organisation('joba', $all);
        [, $otherB] = $this->organisation('jobb', $all);

        $marker = 'job-marker-' . bin2hex(random_bytes(6));
        $tokens = static::getContainer()->get('security.untracked_token_storage');
        $tokens->setToken(new UsernamePasswordToken($ownerA, 'main', $ownerA->getRoles()));
        $jobs = static::getContainer()->get(JobStatusService::class);
        $jobId = $jobs->create('admin.sweep_probe', ['_label' => $marker, '_subtitle' => $marker, 'format' => 'json']);
        $jobs->markSucceeded($jobId, $marker);
        $tokens->setToken(null);

        $targets = new RouteTargets(static::getContainer()->get(RouterInterface::class), $this->em(), $tenantA);
        $router = static::getContainer()->get(RouterInterface::class);
        $failures = [];
        $probed = 0;
        foreach ($targets->appRoutes() as $name => $target) {
            $route = $target['route'];
            if ($route->getMethods() !== [] && !in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            $values = $this->jobVariables($route->compile()->getPathVariables(), $target, $jobId, $route);
            if ($values === null) {
                continue;
            }
            try {
                $url = $router->generate($name, $values);
            } catch (\Throwable) {
                continue;
            }
            ++$probed;
            $owner = $this->isolated(fn () => $this->call($ownerA, $url))->getStatusCode();
            $other = $this->isolated(fn () => $this->call($otherB, $url));
            if (str_contains((string) $other->getContent(), $marker)) {
                $failures[] = sprintf('GET %s (%s): another organisation reads the job', $url, $name);
            } elseif ($owner < 400 && $other->getStatusCode() < 400) {
                $failures[] = sprintf('GET %s (%s): owner %d, other person %d', $url, $name, $owner, $other->getStatusCode());
            }
            $this->em()->clear();
        }

        self::assertGreaterThanOrEqual(10, $probed, 'job route discovery broke');
        self::assertSame([], $failures, "Routes that open another person's job:\n" . implode("\n", $failures));
    }

    private function call(User $user, string $url): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->loginUser($this->em()->find(User::class, $user->getId()) ?? $user);
        $this->client->request('GET', $url);

        return $this->client->getResponse();
    }

    /**
     * One request in its own (rolled back) transaction: a route that fails a query must not abort the transaction
     * the next request runs in, and nothing a probe writes stays.
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    private function isolated(callable $request): mixed
    {
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        try {
            return $request();
        } finally {
            $connection->rollBack();
            $this->em()->clear();
        }
    }

    /**
     * Fill the free string variables of a route with the job id; null when it has none or one that a job id
     * cannot match.
     *
     * @param list<string> $variables
     * @param array{route: \Symfony\Component\Routing\Route, class: class-string, method: string} $target
     * @return array<string, string>|null
     */
    private function jobVariables(array $variables, array $target, string $jobId, \Symfony\Component\Routing\Route $route): ?array
    {
        $strings = [];
        foreach (new \ReflectionMethod($target['class'], $target['method'])->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                $strings[] = $parameter->getName();
            }
        }
        $values = [];
        foreach ($variables as $variable) {
            if ($variable === '_locale' || $variable === 'locale') {
                $values[$variable] = 'de';
                continue;
            }
            $requirement = $route->getRequirement($variable) ?? '[^/]+';
            // An id-like variable (`{id}`, `{uuid}`, `{jobId}`, `{taskId}`, `{token}`) the action takes as a string.
            if ((in_array($variable, $strings, true) || $route->hasDefault($variable))
                && preg_match('/^(id|uuid|token|key)$|(Id|Uuid|Token)$/', $variable) === 1
                && preg_match('#^(?:' . $requirement . ')$#', $jobId) === 1) {
                $values[$variable] = $jobId;
                continue;
            }
            if ($route->hasDefault($variable)) {
                continue;
            }

            return null;
        }

        return in_array($jobId, $values, true) ? $values : null;
    }

    /**
     * @param array{route: \Symfony\Component\Routing\Route, class: class-string, method: string} $target
     * @return list<string>
     */
    private function modulesOf(string $name, array $target): array
    {
        $modules = [];
        $class = new \ReflectionClass($target['class']);
        foreach ([...$class->getAttributes(RequiresModule::class), ...$class->getMethod($target['method'])->getAttributes(RequiresModule::class)] as $attribute) {
            $modules = [...$modules, ...array_filter((array) ($attribute->getArguments()[0] ?? []), 'is_string')];
        }
        $path = $target['route']->getPath();
        $haystack = sprintf('%s %s %s', $class->getShortName(), $path, $name);
        foreach (ModuleGateRule::CONTROLLER_MODULES as $pattern => $module) {
            if (preg_match($pattern, $haystack) === 1) {
                $modules[] = $module;
            }
        }
        if (preg_match(ModuleGateRule::DOWNLOAD_ROUTE, $path) === 1) {
            foreach (ModuleGateRule::DOWNLOAD_MODULES as $pattern => $module) {
                if (preg_match($pattern, $path . ' ' . $name) === 1) {
                    $modules[] = $module;
                }
            }
        }

        return array_values(array_unique($modules));
    }

    private function saysModuleOff(string $body): bool
    {
        foreach (self::MODULE_OFF_TEXT as $text) {
            if (stripos($body, $text) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $modules
     * @return array{Tenant, User}
     */
    private function organisation(string $prefix, array $modules): array
    {
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $tenant = (new Tenant())->setName('Sweep ' . $prefix . ' ' . $id)->setCode($prefix . '_' . $id);
        $tenant->setActiveModules($modules);
        $em->persist($tenant);
        $user = (new User())
            ->setEmail($prefix . '-' . $id . '@example.test')
            ->setFirstName('Sweep')
            ->setLastName($prefix)
            ->setRoles(['ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER', 'ROLE_COMPLIANCE_MANAGER'])
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();

        return [$tenant, $user];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
