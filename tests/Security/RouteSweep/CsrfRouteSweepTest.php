<?php

declare(strict_types=1);

namespace App\Tests\Security\RouteSweep;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * T4 router sweep for Gate 65 (CSRF rule C1–C10 of the counter-check): the AST rule reads one way of writing at a
 * time, these tests ask the real router.
 *
 *  - Every route lives on a concrete class in App\Controller and its action is declared there (no trait, no
 *    abstract parent, no class outside the controller namespace — the rules check only those).
 *  - Every route that accepts POST/PUT/PATCH/DELETE, sent without a CSRF token by a signed-in administrator of an
 *    organisation with every module, changes no record (a Doctrine onFlush spy counts inserts, updates and deletes;
 *    the audit entry of a refused call is allowed). A helper whose result is dropped, a guard that holds no work,
 *    `#[IsCsrfTokenValid(methods: ['GET'])]` or a form with CSRF off all show up here as a write.
 *  - Every GET route, called the same way, changes no record (a write through a service, a repository, DQL or DBAL
 *    the rule cannot follow); reviewed exceptions carry Gate 65's `// @get-write-ok: <reason>` on the action (or,
 *    without a source line to mark, sit in GET_WRITES with the reason).
 *
 * Routes whose variables cannot be filled (see RouteTargets) are counted; the sweep fails when it calls fewer
 * than MIN_SWEPT of them, so a broken argument discovery cannot turn it green.
 */
final class CsrfRouteSweepTest extends WebTestCase
{
    private const array ALLOWED = [AuditLog::class];
    private const float MIN_SWEPT = 0.6;

    /**
     * Tables a page view may write by design, with the reason (reviewed in T4). Nothing in them comes from the
     * request; they hold derived state the page needs.
     *
     * @var array<string, string>
     */
    private const array ALLOWED_TABLES = [
        'audit_log' => 'the audit entry of the call',
        'sessions' => 'the session the call runs in',
        'messenger_messages' => 'queued mail of the call',
        'recurring_duty' => 'RecurringDutyCatalog seeds the next due date of each norm duty once per organisation',
        'alva_hint_render_count' => 'counts how often a hint was shown, so it fades after a few views',
    ];

    /**
     * GET routes that write on purpose, with the reason (reviewed in T4).
     *
     * @var array<string, string>
     */
    public const array GET_WRITES = [];

    /**
     * Routes not called by the sweep, with the reason.
     *
     * @var array<string, string>
     */
    private const array NOT_CALLED = [
        'app_logout' => 'ends the session the sweep runs in',
        'logout' => 'ends the session the sweep runs in',
    ];

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $admin;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(true);
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $this->tenant = (new Tenant())->setName('Sweep ' . $id)->setCode('swp_' . $id);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $this->tenant->setActiveModules(array_values($modules->resolveModuleDependencies(array_keys($modules->getAllModules()))['modules']));
        $em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('sweep-' . $id . '@example.test')
            ->setFirstName('Sven')
            ->setLastName('Sweep')
            ->setRoles(['ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER', 'ROLE_COMPLIANCE_MANAGER'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $em->persist($this->admin);
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([
            'insert' => $uow->getScheduledEntityInsertions(),
            'update' => $uow->getScheduledEntityUpdates(),
            'delete' => $uow->getScheduledEntityDeletions(),
        ] as $kind => $entities) {
            foreach ($entities as $entity) {
                if (!in_array($entity::class, self::ALLOWED, true)) {
                    $this->written[] = $kind . ' ' . (new \ReflectionClass($entity))->getShortName();
                }
            }
        }
    }

    #[Test]
    public function routesLiveOnConcreteControllers(): void
    {
        $failures = [];
        foreach ($this->targets()->appRoutes() as $name => $target) {
            $class = new \ReflectionClass($target['class']);
            $method = $class->getMethod($target['method']);
            if (!str_starts_with($class->getName(), 'App\Controller\\') || $class->isAbstract()) {
                $failures[] = sprintf('%s: %s is no concrete controller', $name, $class->getName());
            } elseif ($method->getDeclaringClass()->getName() !== $class->getName() || $method->getFileName() !== $class->getFileName()) {
                $failures[] = sprintf('%s: %s::%s() is inherited or comes from a trait', $name, $class->getName(), $method->getName());
            }
        }

        self::assertSame([], $failures, "Routes outside a concrete controller (Gates 65/11 do not see them):\n" . implode("\n", $failures));
    }

    #[Test]
    public function aWriteRouteWithoutTokenChangesNothing(): void
    {
        [$failures, $swept, $total, $skipped] = $this->sweep(['POST', 'PUT', 'PATCH', 'DELETE']);

        self::assertGreaterThanOrEqual((int) ($total * self::MIN_SWEPT), $swept, "Too few write routes swept ({$swept}/{$total}):\n" . implode("\n", array_slice($skipped, 0, 60)));
        self::assertSame([], $failures, "Write routes that change data without a CSRF token:\n" . implode("\n", $failures));
    }

    #[Test]
    public function aGetRouteChangesNothing(): void
    {
        [$failures, $swept, $total, $skipped] = $this->sweep(['GET']);

        self::assertGreaterThanOrEqual((int) ($total * self::MIN_SWEPT), $swept, "Too few GET routes swept ({$swept}/{$total}):\n" . implode("\n", array_slice($skipped, 0, 60)));
        self::assertSame([], $failures, "GET routes that change data (move the write to a POST with a token, or list the route in GET_WRITES with a reason):\n" . implode("\n", $failures));
    }

    /** The spy itself sees a write made between two requests on the same manager. */
    #[Test]
    public function theSpySeesAWrite(): void
    {
        $this->em()->getEventManager()->addEventListener([Events::onFlush], $this);
        $this->written = [];
        $this->em()->persist((new Tenant())->setName('Sweep probe')->setCode('swp_probe_' . bin2hex(random_bytes(3))));
        $this->em()->flush();
        $this->em()->getEventManager()->removeEventListener([Events::onFlush], $this);

        self::assertSame(['insert Tenant'], $this->written);
    }

    /**
     * @param list<string> $verbs
     * @return array{list<string>, int, int, list<string>}
     */
    private function sweep(array $verbs): array
    {
        $targets = $this->targets();
        $urls = [];
        $skipped = [];
        $total = 0;
        foreach ($targets->appRoutes() as $name => $target) {
            $methods = $target['route']->getMethods();
            $verb = $methods === [] ? ($verbs === ['GET'] ? 'GET' : null) : (array_values(array_intersect($verbs, $methods))[0] ?? null);
            if ($verb === null || isset(self::NOT_CALLED[$name])) {
                continue;
            }
            ++$total;
            $url = $targets->url($name, $target);
            if ($url['url'] === null) {
                $skipped[] = (string) $url['skipped'];
                continue;
            }
            $urls[$name] = [$verb, $url['url']];
        }

        $em = $this->em();
        $em->getEventManager()->addEventListener([Events::onFlush], $this);
        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        $failures = [];
        foreach ($urls as $name => [$verb, $url]) {
            $this->written = [];
            $this->client->loginUser($this->em()->find(User::class, $this->admin->getId()) ?? $this->admin);
            $connection = $this->em()->getConnection();
            $connection->beginTransaction(); // each call rolled back: a failing route cannot poison the next one
            $queries->reset();
            try {
                $this->client->request($verb, $url, [], [], ['HTTP_ACCEPT' => 'text/html']);
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s %s (%s): threw %s', $verb, $url, $name, $e->getMessage());
            }
            // Writes past the ORM (DBAL, DQL UPDATE/DELETE) show up as statements.
            foreach ($queries->getData() as $connectionQueries) {
                foreach ($connectionQueries as $query) {
                    $sql = ltrim((string) ($query['sql'] ?? ''));
                    if (preg_match('/^(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+[`"]?(\w+)/i', $sql, $table) === 1
                        && !isset(self::ALLOWED_TABLES[strtolower($table[1])])) {
                        $this->written[] = 'sql ' . strtok($sql, "\n");
                    }
                }
            }
            $status = $this->client->getResponse()->getStatusCode();
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                // the route ended the transaction itself; the next call opens a new one
            }
            if ($this->written !== [] && !($verb === 'GET' && (isset(self::GET_WRITES[$name]) || $this->reviewedGetWrite($name)))) {
                $failures[] = sprintf('%s %s (%s) → %d wrote: %s', $verb, $url, $name, $status, implode(', ', array_unique(array_map(static fn (string $w): string => substr($w, 0, 120), $this->written))));
            }
            if (!$this->em()->isOpen()) {
                break;
            }
            $this->em()->clear();
        }
        $this->em()->getEventManager()->removeEventListener([Events::onFlush], $this);

        return [$failures, count($urls), $total, $skipped];
    }

    /** The action carries the Gate 65 marker `// @get-write-ok: <reason>` in its lead (the rule's own exception). */
    private function reviewedGetWrite(string $name): bool
    {
        $target = $this->targets()->appRoutes()[$name] ?? null;
        if ($target === null) {
            return false;
        }
        $method = new \ReflectionMethod($target['class'], $target['method']);
        $lines = file((string) $method->getFileName()) ?: [];
        $lead = '';
        for ($i = $method->getStartLine() - 2; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            if ($line === '}' || str_ends_with($line, '{') || $line === '') {
                break;
            }
            $lead = $line . "\n" . $lead;
        }

        return preg_match('/@get-write-ok:[ \t]*[^\s*\/]/', $lead . $lines[$method->getStartLine() - 1]) === 1;
    }

    private function targets(): RouteTargets
    {
        return new RouteTargets(static::getContainer()->get(RouterInterface::class), $this->em(), $this->tenant);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
