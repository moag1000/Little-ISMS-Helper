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
    /**
     * Tools no probe below reaches, each with the reason. T2: a tool that took only an `id`, or had
     * required parameters besides `resource`/`query`, used to be skipped without a word.
     *
     * @var array<string, string>
     */
    private const array NOT_PROBED = [];

    private KernelBrowser $client;
    private Tenant $own;
    private Tenant $foreign;
    private string $token;
    private User $tokenUser;
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

        $this->tokenUser = $user;
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
        $probedTools = [];
        $counters = [];
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
                    $probedTools[$name] = true;
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
                    $probedTools[$name] = true;
                }
                continue;
            }
            if (isset($properties['query'])) {
                // Matches A's and B's records alike; only A's may come back.
                $hits = $this->call($name, ['query' => $this->base]);
                $this->assertNoLeak($name, $hits);
                self::assertStringContainsString($this->base . 'own', (string) json_encode($hits), $name . ' must find the own records (the probe is not vacuous)');
                ++$probed;
                $probedTools[$name] = true;
                continue;
            }
            if (($tool['annotations']['readOnlyHint'] ?? false) === true && ($tool['inputSchema']['required'] ?? []) === []) {
                $answer = $this->call($name);
                $this->assertNoLeak($name, $answer);
                $counters[$name] = self::numbers($answer);
                ++$probed;
                $probedTools[$name] = true;
            }
        }
        self::assertGreaterThan(count($ids), $probed);
        $unprobed = array_values(array_diff(array_column($tools, 'name'), array_keys($probedTools), array_keys(self::NOT_PROBED)));
        self::assertSame([], $unprobed, 'MCP tool without a cross-organisation probe; probe it or give the reason in NOT_PROBED.');
        self::assertSame([], array_values(array_diff(array_keys(self::NOT_PROBED), array_column($tools, 'name'))), 'Stale NOT_PROBED entry.');

        // Counters carry no marker (T2: a KPI counting every organisation's rows stayed green). More
        // records of B must not move a single number A sees.
        self::assertArrayHasKey('management_kpis', $counters);
        foreach ($resources as $name) {
            $class = 'App\\Entity\\' . $name;
            if (class_exists($class) && property_exists($class, 'tenant')) {
                for ($i = 0; $i < 3; ++$i) {
                    $factory->create($class, $this->em()->find(Tenant::class, $this->foreign->getId()), $this->marker . 'n' . $i);
                }
            }
        }
        $this->em()->flush();
        // The probes above use up most of a token's 100 calls per minute (`api` limiter); the second
        // round runs on a fresh token of the same person, so a 429 never passes for a changed number.
        $this->token = static::getContainer()->get(ApiTokenManager::class)
            ->mint($this->em()->find(User::class, $this->tokenUser->getId()) ?? $this->tokenUser, 'Contract 2', canWrite: true);
        foreach ($counters as $name => $before) {
            $after = $this->call($name);
            self::assertSame($before, self::numbers($after), $name . ' counts records of another organisation: ' . mb_substr((string) json_encode($after), 0, 1500));
        }

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

    /**
     * S24: personal data reaches the assistant only by the rules of
     * #[PersonalDataResource], the same in every tool. An employee's token
     * (ROLE_USER) of A: badge/crypto logs are not offered, a person comes
     * without contact details in list, get and search, and data-subject
     * records (data breach, DSR, consent) are neither listed nor searched.
     * The person's name stays (the probe is not vacuous).
     */
    #[Test]
    public function anEmployeesTokenGetsNoPersonalDataBeyondTheName(): void
    {
        $em = $this->em();
        $own = $em->find(Tenant::class, $this->own->getId());
        $employee = (new User())
            ->setEmail('mcp-employee-' . $this->base . '@example.test')
            ->setFirstName('Eli')
            ->setLastName('Employee')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed')
            ->setTenant($own)
            ->setIsActive(true);
        $em->persist($employee);
        $factory = new ContractRecordFactory($em);
        $person = $factory->create(\App\Entity\Person::class, $own, $this->base . 'name');
        $secret = $this->base . 'contact';
        $person->setEmail($secret . '@example.test');
        $person->setPhone('0' . substr((string) crc32($secret), 0, 9));
        $person->setNotes($secret);
        $person->setBadgeId($secret);
        foreach ([\App\Entity\DataBreach::class, \App\Entity\DataSubjectRequest::class, \App\Entity\Consent::class] as $subject) {
            $factory->create($subject, $own, $this->base . 'subject');
        }
        $em->flush();
        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($employee, 'Employee');

        $resources = $this->call('get_context')['structuredContent']['resources'] ?? [];
        self::assertNotEmpty($resources, 'get_context offers no resources');
        foreach (self::markedClasses() as $class => $marker) {
            $short = (new \ReflectionClass($class))->getShortName();
            if (!$marker->assistant || !$marker->isFieldLevel()) {
                self::assertArrayNotHasKey($short, $resources, $short . ' is offered to an employee\'s assistant');
                $answer = $this->call('list_records', ['resource' => $short]);
                self::assertStringNotContainsString($this->base, (string) json_encode($answer), 'list_records ' . $short);
            }
        }
        self::assertArrayHasKey('Person', $resources, 'a person without contact details stays readable');

        $listed = (string) json_encode($this->call('list_records', ['resource' => 'Person']));
        self::assertStringContainsString($this->base . 'name', $listed, 'the person is listed (the probe is not vacuous)');
        self::assertStringNotContainsString($secret, $listed, 'list_records Person hands contact details to the assistant');
        $single = (string) json_encode($this->call('get_record', ['resource' => 'Person', 'id' => (int) $person->getId()]));
        self::assertStringContainsString($this->base . 'name', $single);
        self::assertStringNotContainsString($secret, $single, 'get_record Person hands contact details to the assistant');

        $byName = (string) json_encode($this->call('search', ['query' => $this->base . 'name']));
        self::assertStringContainsString($this->base . 'name', $byName, 'search finds the person by name');
        self::assertStringNotContainsString($secret, $byName);
        $byContact = (string) json_encode($this->call('search', ['query' => $secret]));
        self::assertStringNotContainsString($this->base . 'name', $byContact, 'search tells whose e-mail address it is');
        self::assertStringNotContainsString('withheld', $byContact, 'search counts the hits it leaves out (existence oracle)');
        $withheldGroups = array_keys(array_filter(
            static::getContainer()->get(\App\Mcp\PersonalDataPolicy::class)->searchGroups(),
            static fn (\App\Attribute\PersonalDataResource $marker): bool => !$marker->isFieldLevel(),
        ));
        self::assertNotEmpty($withheldGroups);
        $groupsOf = fn (): array => array_column($this->call('search', ['query' => $this->base . 'subject'])['structuredContent']['hits'] ?? [], 'group');
        self::assertSame([], array_values(array_intersect($groupsOf(), $withheldGroups)), 'search hands data-subject records to an employee\'s assistant');

        // The same token with the DPO role gets them: the rule is the role, not a switch-off.
        $em = $this->em(); // the client rebooted the kernel
        $dpo = $em->find(User::class, $employee->getId()) ?? throw new \LogicException('employee');
        $dpo->setRoles(['ROLE_DPO']);
        $em->flush();
        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($dpo, 'DPO');
        self::assertStringContainsString($secret, (string) json_encode($this->call('get_record', ['resource' => 'Person', 'id' => (int) $person->getId()])));
        self::assertNotSame([], array_values(array_intersect($groupsOf(), $withheldGroups)), 'the DPO finds the data-subject records (the probe is not vacuous)');
        $dpoResources = $this->call('get_context')['structuredContent']['resources'] ?? [];
        foreach (self::markedClasses() as $class => $marker) {
            if (!$marker->assistant) {
                self::assertArrayNotHasKey((new \ReflectionClass($class))->getShortName(), $dpoResources, 'assistant: false holds for every role');
            }
        }
    }

    /** @return array<class-string, \App\Attribute\PersonalDataResource> */
    private static function markedClasses(): array
    {
        $marked = [];
        foreach (\App\Mcp\PersonalDataPolicy::entityClasses(dirname(__DIR__, 2)) as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(\App\Attribute\PersonalDataResource::class) as $attribute) {
                $marked[$class] = $attribute->newInstance();
            }
        }
        self::assertArrayHasKey(\App\Entity\PhysicalAccessLog::class, $marked);

        return $marked;
    }

    /**
     * Every number of an answer by its path, without clock values (durations, timestamps).
     *
     * @param array<mixed> $answer
     * @return array<string, int|float>
     */
    private static function numbers(array $answer, string $path = ''): array
    {
        $out = [];
        foreach ($answer as $key => $value) {
            $at = $path . '/' . $key;
            if (is_array($value)) {
                $out += self::numbers($value, $at);
            } elseif ((is_int($value) || is_float($value)) && preg_match('/(_at|At|date|Date|time|Time|generated|duration|_ms)$/', (string) $key) !== 1) {
                $out[$at] = $value;
            } elseif (is_string($value) && $key === 'text' && str_starts_with(ltrim($value), '{')) {
                $out += self::numbers((array) json_decode($value, true), $at); // MCP content blocks carry the JSON as text
            }
        }

        return $out;
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

        $response = $this->client->getResponse();
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded, sprintf('%s %s answered %d: %s', $method, (string) json_encode($params), $response->getStatusCode(), mb_substr((string) $response->getContent(), 0, 2000)));

        return $decoded;
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
