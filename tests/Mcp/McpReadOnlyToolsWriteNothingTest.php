<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Mcp\McpSettings;
use App\Service\ApiTokenManager;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T (wave 7): McpToolClassificationTest pins which tools write and scans the
 * source of the others for writing calls — a read-only tool that writes
 * through a service it calls passes that scan. Here every tool that calls
 * itself read-only is RUN over /mcp (with the arguments it offers: a resource,
 * a record id, a query) while a Doctrine onFlush spy records every insert,
 * update and delete. Only the audit trail of the call may be written.
 *
 * The spy proves itself in theSpySeesAWrite().
 */
final class McpReadOnlyToolsWriteNothingTest extends WebTestCase
{
    /** Rows a read may write: the audit entry of the call, the token's last use. */
    private const array ALLOWED = [AuditLog::class, \App\Entity\ApiToken::class];

    private KernelBrowser $client;
    private string $token;
    private Tenant $tenant;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // one container: the spy stays attached
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $this->tenant = (new Tenant())->setName('MCP RO ' . $id)->setCode('mro_' . $id);
        $em->persist($this->tenant);
        $user = (new User())
            ->setEmail('mcp-ro-' . $id . '@example.test')
            ->setFirstName('Rita')
            ->setLastName('Readonly')
            ->setRoles(['ROLE_ADMIN', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $settings = static::getContainer()->get(McpSettings::class);
        $settings->setEnabled($this->tenant, true);
        $settings->setWriteEnabled($this->tenant, true);
        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($user, 'ReadOnly', canWrite: true);

        $em->getEventManager()->addEventListener([Events::onFlush], $this);
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
                    $this->written[] = $kind . ' ' . $entity::class;
                }
            }
        }
    }

    #[Test]
    public function readOnlyToolsChangeNoRecord(): void
    {
        $tools = $this->rpc('tools/list')['result']['tools'] ?? [];
        $readOnly = array_values(array_filter($tools, static fn (array $t): bool => ($t['annotations']['readOnlyHint'] ?? false) === true));
        self::assertGreaterThanOrEqual(8, count($readOnly), 'tools/list offers fewer read-only tools than exist');

        $ids = $this->records($tools);
        $failures = [];
        $calls = 0;
        foreach ($readOnly as $tool) {
            foreach ($this->argumentSets($tool, $ids) as $arguments) {
                $this->written = [];
                $this->call((string) $tool['name'], $arguments);
                ++$calls;
                if ($this->written !== []) {
                    $failures[] = $tool['name'] . ' ' . json_encode($arguments) . ' wrote: ' . implode(', ', array_unique($this->written));
                }
            }
        }

        self::assertGreaterThan(20, $calls, 'too few tool calls — did the argument discovery break?');
        self::assertSame([], $failures, "Tools marked read-only that change data:\n" . implode("\n", $failures));
    }

    /**
     * The spy itself: it hangs on the manager the requests use (no reboot), so a
     * flush there shows up — and an MCP call between does not detach it.
     */
    #[Test]
    public function theSpySeesAWrite(): void
    {
        $this->call('get_context');
        $this->written = [];
        $em = $this->em();
        $em->persist((new Tenant())->setName('MCP RO probe')->setCode('mro_probe_' . bin2hex(random_bytes(3))));
        $em->flush();
        self::assertSame(['insert ' . Tenant::class], $this->written);
    }

    /**
     * One record of every resource a tool can name.
     *
     * @param list<array<string, mixed>> $tools
     * @return array<string, int>
     */
    private function records(array $tools): array
    {
        $resources = array_keys($this->call('get_context')['structuredContent']['resources'] ?? []);
        foreach ($tools as $tool) {
            $resources = [...$resources, ...($tool['inputSchema']['properties']['resource']['enum'] ?? [])];
        }
        $em = $this->em();
        $factory = new ContractRecordFactory($em);
        $tenant = $em->find(Tenant::class, $this->tenant->getId());
        $records = [];
        foreach (array_unique($resources) as $name) {
            $class = 'App\\Entity\\' . $name;
            if (class_exists($class) && property_exists($class, 'tenant')) {
                $records[$name] = $factory->create($class, $tenant, 'mcpro');
            }
        }
        $em->flush();

        return array_map(static fn (object $r): int => (int) $r->getId(), $records);
    }

    /**
     * Arguments the tool's schema asks for: every offered resource with and
     * without a record id, a search term, nothing.
     *
     * @param array<string, mixed> $tool
     * @param array<string, int> $ids
     * @return list<array<string, mixed>>
     */
    private function argumentSets(array $tool, array $ids): array
    {
        $properties = $tool['inputSchema']['properties'] ?? [];
        $base = [];
        foreach (['query', 'q', 'term'] as $text) {
            if (isset($properties[$text])) {
                $base[$text] = 'mcpro';
            }
        }
        if (!isset($properties['resource'])) {
            return [$base];
        }
        $sets = [];
        foreach ($properties['resource']['enum'] ?? array_keys($ids) as $resource) {
            $set = $base + ['resource' => $resource];
            if (isset($properties['id'])) {
                if (!isset($ids[$resource])) {
                    continue;
                }
                $set['id'] = $ids[$resource];
            }
            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = []): array
    {
        $answer = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]);

        return (array) ($answer['result'] ?? $answer['error'] ?? []);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ], content: (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params,
        ]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
