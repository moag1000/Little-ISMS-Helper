<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Tenant;
use App\Entity\User;
use App\Mcp\McpSettings;
use App\Service\ApiTokenManager;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S6 contract: the assistant connection (MCP) of organisation A never reads,
 * changes or drafts on a record of organisation B — for EVERY tool and EVERY
 * resource the server offers, discovered from tools/list and get_context.
 *
 * A writing admin token of A asks each tool that takes a record id for B's
 * record of each resource, lists every resource and searches for the marker
 * B's records carry. No answer may contain the marker, B's records stay
 * unchanged, and save_record cannot link A's records to B's by IRI. A's own
 * records carry a second marker that search and list must find, so the probe
 * is never vacuous.
 */
final class CrossTenantMcpContractTest extends WebTestCase
{
    private KernelBrowser $client;
    private Tenant $own;
    private Tenant $foreign;
    private string $token;
    private string $marker;
    private string $base;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();

        $id = bin2hex(random_bytes(5));
        $this->base = 'zzct' . $id;
        $this->marker = $this->base . 'leak'; // B's records; A's carry base . 'own'
        $this->own = (new Tenant())->setName('MCP Own ' . $id)->setCode('cmo_' . $id);
        $this->foreign = (new Tenant())->setName('MCP Foreign ' . $id)->setCode('cmf_' . $id);
        $em->persist($this->own);
        $em->persist($this->foreign);
        $user = (new User())
            ->setEmail('mcp-contract-' . $id . '@example.test')
            ->setFirstName('Mia')
            ->setLastName('Contract')
            ->setRoles(['ROLE_ADMIN', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER'])
            ->setPassword('hashed')
            ->setTenant($this->own)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $settings = static::getContainer()->get(McpSettings::class);
        $settings->setEnabled($this->own, true);
        $settings->setWriteEnabled($this->own, true);

        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($user, 'Contract', canWrite: true);
    }

    #[Test]
    public function noToolReachesARecordOfAnotherOrganisation(): void
    {
        $context = $this->call('get_context');
        $resources = array_keys($context['structuredContent']['resources'] ?? []);
        self::assertNotEmpty($resources, 'get_context offers no resources');
        $tools = $this->rpc('tools/list')['result']['tools'] ?? [];
        self::assertContains('save_record', array_column($tools, 'name'), 'the token writes — every tool is in play');
        foreach ($tools as $tool) {
            // Draft subjects and other record names a tool accepts, beyond the REST resources.
            $resources = [...$resources, ...($tool['inputSchema']['properties']['resource']['enum'] ?? [])];
        }
        $resources = array_values(array_unique($resources));

        // B's record of every resource the assistant can name.
        $em = $this->em();
        $factory = new ContractRecordFactory($em);
        $foreignTenant = $em->find(Tenant::class, $this->foreign->getId());
        $ownTenant = $em->find(Tenant::class, $this->own->getId());
        $foreign = [];
        $own = [];
        foreach ($resources as $name) {
            $class = 'App\\Entity\\' . $name;
            if (class_exists($class) && property_exists($class, 'tenant')) {
                $foreign[$name] = $factory->create($class, $foreignTenant, $this->marker);
                $own[$name] = $factory->create($class, $ownTenant, $this->base . 'own');
            }
        }
        $em->flush();
        $ids = array_map(static fn (object $record): int => (int) $record->getId(), $foreign);
        self::assertNotEmpty($ids);
        $ownIds = array_map(static fn (object $record): int => (int) $record->getId(), $own);

        $probed = 0;
        foreach ($tools as $tool) {
            $properties = $tool['inputSchema']['properties'] ?? [];
            $name = (string) $tool['name'];
            if (isset($properties['id'], $properties['resource'])) {
                $offered = $properties['resource']['enum'] ?? array_keys($ids);
                foreach ($offered as $resource) {
                    if (!isset($ids[$resource])) {
                        continue;
                    }
                    $arguments = ['resource' => $resource, 'id' => $ids[$resource]];
                    if (isset($properties['data'])) {
                        $arguments['data'] = ['title' => 'contract-overwritten', 'name' => 'contract-overwritten'];
                    }
                    if (isset($properties['kind'])) {
                        $arguments['kind'] = 'contract_probe'; // the record is resolved before the kind
                    }
                    $this->assertNoLeak($name . ' ' . $resource . '#' . $ids[$resource], $this->call($name, $arguments));
                    ++$probed;
                }
                continue;
            }
            if (isset($properties['resource'])) {
                foreach ($properties['resource']['enum'] ?? [] as $resource) {
                    $page = $this->call($name, ['resource' => $resource]);
                    $this->assertNoLeak($name . ' ' . $resource, $page);
                    if ($resource === 'Risk') {
                        self::assertStringContainsString($this->base . 'own', (string) json_encode($page), $name . ' must list the own records (the probe is not vacuous)');
                    }
                    ++$probed;
                }
                continue;
            }
            if (isset($properties['query'])) {
                // Matches A's and B's records alike; only A's may come back.
                $hits = $this->call($name, ['query' => $this->base]);
                $this->assertNoLeak($name, $hits);
                self::assertStringContainsString($this->base . 'own', (string) json_encode($hits), $name . ' must find the own records (the probe is not vacuous)');
                ++$probed;
                continue;
            }
            if (($tool['annotations']['readOnlyHint'] ?? false) === true && ($tool['inputSchema']['required'] ?? []) === []) {
                $this->assertNoLeak($name, $this->call($name));
                ++$probed;
            }
        }
        self::assertGreaterThan(count($ids), $probed);

        // save_record must not link A's records to B's (IRI references in the body).
        $iris = static::getContainer()->get(IriConverterInterface::class);
        $linkProbes = 0;
        foreach ($own as $resource => $record) {
            foreach ($factory->writableTenantRelations($record::class) as $property => [$target, $toMany]) {
                $targetResource = (new \ReflectionClass($target))->getShortName();
                if (!isset($foreign[$targetResource])) {
                    continue;
                }
                $iri = $iris->getIriFromResource($this->fresh($target, $ids[$targetResource]) ?? throw new \LogicException($target));
                $answer = $this->call('save_record', ['resource' => $resource, 'id' => $ownIds[$resource], 'data' => [$property => $toMany ? [$iri] : $iri]]);
                $this->assertNoLeak('save_record ' . $resource . '.' . $property, $answer);
                $after = $this->fresh($record::class, $ownIds[$resource]);
                $value = (new \ReflectionProperty($after, $property))->getValue($after);
                $linked = $toMany
                    ? array_map(static fn (object $o): mixed => $o->getId(), iterator_to_array($value ?? []))
                    : [$value?->getId()];
                self::assertNotContains($ids[$targetResource], $linked, sprintf('save_record linked %s.%s to a record of another organisation', $resource, $property));
                ++$linkProbes;
            }
        }
        self::assertGreaterThan(0, $linkProbes, 'no relation was probed');

        // Nothing of B changed.
        foreach ($foreign as $resource => $record) {
            $after = $this->fresh($record::class, $ids[$resource]);
            self::assertNotNull($after, $resource . ' of another organisation was deleted');
            foreach (['title', 'name'] as $field) {
                if (property_exists($after, $field)) {
                    $value = (new \ReflectionProperty($after, $field))->getValue($after);
                    self::assertNotSame('contract-overwritten', $value, $resource . '::$' . $field . ' of another organisation was overwritten');
                }
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function assertNoLeak(string $what, array $result): void
    {
        $json = (string) json_encode($result);
        self::assertStringNotContainsString($this->marker, $json, $what . ' returned a record of another organisation: ' . substr($json, 0, 600));
        self::assertNotSame(500, $this->client->getResponse()->getStatusCode(), $what);
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

    private function fresh(string $class, int $id): ?object
    {
        $em = $this->em();
        $em->clear();
        if ($em->getFilters()->isEnabled('tenant_filter')) {
            $em->getFilters()->disable('tenant_filter'); // armed by the last request
        }

        return $em->find($class, $id);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
