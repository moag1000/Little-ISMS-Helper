<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ApiTokenManager;
use App\Tests\Contract\Support\ContractRecordFactory;
use App\Tests\Support\PhpSourceScanner;
use Doctrine\ORM\EntityManagerInterface;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S6 contract: a token of organisation A never reaches a record of
 * organisation B — for EVERY REST resource, discovered from the entities, so
 * a new resource is covered without a new test.
 *
 * Per resource, with a writing admin token of A (the strongest token an
 * organisation can hand out, so the only barrier left is the organisation):
 *  - the collection does not list B's record (the record carries a marker);
 *  - the item answers 403/404;
 *  - PUT/PATCH and DELETE on B's record are refused and change nothing — with
 *    the token (REST is read-only for tokens: 405) and with A's browser
 *    session, which reaches the API through the main firewall;
 *  - A's own record cannot be linked to a record of B by IRI (session; the
 *    token's write path is /mcp, see CrossTenantMcpContractTest).
 */
final class CrossTenantApiContractTest extends WebTestCase
{
    private const array DENIED = [403, 404];
    /** 405: a bearer token never writes over REST (the assistant writes through /mcp). */
    private const array DENIED_WRITE = [403, 404, 405];

    /**
     * API resources without an organisation (global catalogues), each with the reason the
     * organisation check does not apply. T2: a resource without `$tenant` used to drop out of the
     * discovery silently.
     *
     * @var array<class-string, string>
     */
    private const array WITHOUT_TENANT = [];

    /**
     * Operations the probe cannot address (URI variables besides `id`), each with the reason it is
     * safe. T2: sub-resource routes were skipped without a word.
     *
     * @var array<string, string> "<Class> <METHOD> <uriTemplate>" => reason
     */
    private const array UNPROBED_OPERATIONS = [];

    private KernelBrowser $client;
    private Tenant $own;
    private Tenant $foreign;
    private string $token;
    private string $marker;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();

        $id = bin2hex(random_bytes(5));
        $this->marker = 'zzleak' . $id;
        $this->own = (new Tenant())->setName('Contract Own ' . $id)->setCode('cto_' . $id);
        $this->foreign = (new Tenant())->setName('Contract Foreign ' . $id)->setCode('ctf_' . $id);
        $em->persist($this->own);
        $em->persist($this->foreign);
        $user = (new User())
            ->setEmail('contract-' . $id . '@example.test')
            ->setFirstName('Cora')
            ->setLastName('Contract')
            ->setRoles(['ROLE_ADMIN', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER'])
            ->setPassword('hashed')
            ->setTenant($this->own)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $this->user = $user;

        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($user, 'Contract', canWrite: true);
    }

    /** @return iterable<string, array{class-string}> */
    public static function tenantOwnedResources(): iterable
    {
        foreach (self::apiResources() as $class) {
            if (new ReflectionClass($class)->hasProperty('tenant')) {
                yield $class => [$class];
            }
        }
    }

    /**
     * Every resource API Platform itself serves — entities, DTOs in src/ApiResource/ or anywhere else the
     * `api_platform.resource_class_directories` / mapping config reaches. W9: discovery used to scan
     * src/Entity/ only, so an #[ApiResource] DTO elsewhere was never probed.
     *
     * @return list<class-string>
     */
    public static function apiResources(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            /** @var ResourceNameCollectionFactoryInterface $names */
            $names = self::getContainer()->get('api_platform.metadata.resource.name_collection_factory');
            $classes = [];
            foreach ($names->create() as $class) {
                $classes[] = $class;
            }
        } finally {
            self::ensureKernelShutdown();
        }
        sort($classes);

        return $classes;
    }

    /**
     * Classes below src/ (any folder) that carry #[ApiResource] in their own source, found by the parser.
     * They must all be served by API Platform — a resource its mapping does not reach would be invisible
     * to the discovery above and probed by nobody.
     *
     * @return list<class-string>
     */
    private static function apiResourceClassesInSource(): array
    {
        $classes = [];
        foreach (PhpSourceScanner::phpFiles(dirname(__DIR__, 2) . '/src') as $path) {
            if (!str_contains((string) file_get_contents($path), 'ApiResource')) {
                continue;
            }
            foreach (PhpSourceScanner::find(PhpSourceScanner::parseFile($path), Node\Stmt\Class_::class) as $class) {
                foreach ($class->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if (ltrim($attribute->name->toString(), '\\') === ApiResource::class && $class->namespacedName !== null) {
                            $classes[] = $class->namespacedName->toString();
                        }
                    }
                }
            }
        }
        sort($classes);

        return $classes;
    }

    #[Test]
    public function everyApiResourceInSourceIsServedByApiPlatform(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::apiResourceClassesInSource(), self::apiResources())),
            '#[ApiResource] class that API Platform does not serve (mapping paths?) — the contract would never probe it.',
        );
    }

    /** Resources that are not Doctrine entities (DTOs with a state provider): each needs a reason, the probe creates entities. */
    #[Test]
    public function everyApiResourceIsAnEntityOrSaysWhyNot(): void
    {
        $metadata = $this->em()->getMetadataFactory();
        $notEntities = array_values(array_filter(self::apiResources(), static fn (string $c): bool => !$metadata->hasMetadataFor($c)));

        self::assertSame(
            [],
            array_values(array_diff($notEntities, array_keys(self::WITHOUT_TENANT))),
            'API resource that is not a Doctrine entity: the cross-tenant probe cannot create its records. Probe it by hand and list it in WITHOUT_TENANT with the reason.',
        );
    }

    #[Test]
    public function everyApiResourceBelongsToAnOrganisationOrSaysWhyNot(): void
    {
        $without = array_values(array_filter(self::apiResources(), static fn (string $c): bool => !new ReflectionClass($c)->hasProperty('tenant')));
        self::assertSame([], array_values(array_diff($without, array_keys(self::WITHOUT_TENANT))), 'API resource without $tenant and without a reason in WITHOUT_TENANT.');
        self::assertSame([], array_values(array_diff(array_keys(self::WITHOUT_TENANT), $without)), 'Stale WITHOUT_TENANT entry.');
    }

    /** Operations with URI variables besides `id` reach records the probe never creates. */
    #[Test]
    public function everyOperationIsProbedOrSaysWhyNot(): void
    {
        $unprobed = [];
        foreach (iterator_to_array(self::tenantOwnedResources()) as [$class]) {
            foreach ($this->operations($class, all: true) as $operation) {
                if (array_diff(array_keys($operation->getUriVariables() ?? []), ['id']) !== []) {
                    $unprobed[] = sprintf('%s %s %s', $class, $operation->getMethod(), $operation->getUriTemplate());
                }
            }
        }
        self::assertSame([], array_values(array_diff($unprobed, array_keys(self::UNPROBED_OPERATIONS))), 'Sub-resource operation the cross-tenant probe skips; probe it or give the reason.');
        self::assertSame([], array_values(array_diff(array_keys(self::UNPROBED_OPERATIONS), $unprobed)), 'Stale UNPROBED_OPERATIONS entry.');
    }

    #[Test]
    public function everyApiResourceIsCovered(): void
    {
        $covered = count(iterator_to_array(self::tenantOwnedResources()));
        self::assertGreaterThanOrEqual(18, $covered, 'the resource discovery found fewer resources than exist');
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('tenantOwnedResources')]
    public function anotherOrganisationsRecordStaysOutOfReach(string $class): void
    {
        $factory = new ContractRecordFactory($this->em());
        $foreign = $factory->create($class, $this->em()->find(Tenant::class, $this->foreign->getId()), $this->marker);
        // A's own record must show up: proves the collection answered with data (not 403, not
        // another page) — with a broken organisation filter every row of every test competes for page 1.
        $ownMarker = str_replace('zzleak', 'zzown', $this->marker);
        $factory->create($class, $this->em()->find(Tenant::class, $this->own->getId()), $ownMarker);
        $this->em()->flush();
        $foreignId = (int) $foreign->getId();
        $iri = $this->iri($foreign);

        foreach ($this->operations($class) as $operation) {
            $method = $operation->getMethod();
            if ($operation instanceof GetCollection) {
                $this->bearer('GET', $this->collectionPath($class, $operation));
                self::assertSame(200, $this->httpStatus(), $class . ' collection: ' . $this->body());
                $content = (string) $this->client->getResponse()->getContent();
                self::assertStringNotContainsString($this->marker, $content, $class . ': the collection lists a record of another organisation');
                self::assertStringContainsString($ownMarker, $content, $class . ': the collection does not list the own record');
                continue;
            }
            if ($operation instanceof Get) {
                $this->bearer('GET', $iri);
                self::assertContains($this->httpStatus(), self::DENIED, $class . ' item of another organisation: ' . $this->body());
                self::assertStringNotContainsString($this->marker, (string) $this->client->getResponse()->getContent());
                continue;
            }
            if ($operation instanceof Put || $operation instanceof Patch) {
                $field = $this->writableTextField($class);
                $body = (string) json_encode($field !== null ? [$field => 'contract-overwritten'] : new \stdClass());
                $type = $operation instanceof Patch ? 'application/merge-patch+json' : 'application/ld+json';
                foreach (['bearer', 'session'] as $via) {
                    $this->send($via, $method, $iri, $body, $type);
                    self::assertContains($this->httpStatus(), self::DENIED_WRITE, sprintf('%s %s on another organisation (%s): %s', $class, $method, $via, $this->body()));
                }
                if ($field !== null) {
                    $after = $this->fresh($class, $foreignId);
                    self::assertNotSame('contract-overwritten', (new ReflectionClass($class))->getProperty($field)->getValue($after));
                }
                continue;
            }
            // A custom item action (`POST /risks/{id}/accept`, T2): another organisation's id is refused.
            if ($operation instanceof Post && array_key_exists('id', $operation->getUriVariables() ?? [])) {
                $path = static::getContainer()->get(IriConverterInterface::class)
                    ->getIriFromResource($foreign, UrlGeneratorInterface::ABS_PATH, $operation);
                foreach (['bearer', 'session'] as $via) {
                    $this->send($via, 'POST', $path, '{}');
                    self::assertContains($this->httpStatus(), self::DENIED_WRITE, sprintf('%s POST %s on another organisation (%s): %s', $class, $path, $via, $this->body()));
                }
                continue;
            }
            if ($operation instanceof Delete) {
                foreach (['bearer', 'session'] as $via) {
                    $this->send($via, 'DELETE', $iri);
                    self::assertContains($this->httpStatus(), self::DENIED_WRITE, sprintf('%s DELETE on another organisation (%s): %s', $class, $via, $this->body()));
                }
                self::assertNotNull($this->fresh($class, $foreignId), $class . ': a record of another organisation was deleted');
            }
        }
    }

    /**
     * IRI references: A's own record must not point at B's records, whatever
     * relation the write group offers (asset of a risk, owner person, …).
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('tenantOwnedResources')]
    public function ownRecordsCannotReferenceAnotherOrganisationsRecords(string $class): void
    {
        $update = null;
        foreach ($this->operations($class) as $operation) {
            if ($operation instanceof Patch || $operation instanceof Put) {
                $update = $operation;
                break;
            }
        }
        $relations = (new ContractRecordFactory($this->em()))->writableTenantRelations($class);
        if ($update === null || $relations === []) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $em = $this->em();
        $factory = new ContractRecordFactory($em);
        $own = $factory->create($class, $em->find(Tenant::class, $this->own->getId()), 'own ' . $this->marker);
        $targets = [];
        foreach ($relations as $property => [$target, $toMany]) {
            $targets[$property] = [$factory->create($target, $em->find(Tenant::class, $this->foreign->getId()), $this->marker), $toMany];
        }
        $em->flush();
        $ownId = (int) $own->getId();

        foreach ($targets as $property => [$target, $toMany]) {
            $targetIri = $this->iri($target);
            $targetId = $target->getId();
            $body = (string) json_encode([$property => $toMany ? [$targetIri] : $targetIri]);
            $this->send('session', $update->getMethod(), $this->iri($own), $body, $update instanceof Patch ? 'application/merge-patch+json' : 'application/ld+json');
            self::assertNotSame(500, $this->httpStatus(), $class . '::$' . $property . ': ' . $this->body());

            $after = $this->fresh($class, $ownId);
            $value = (new ReflectionClass($class))->getProperty($property)->getValue($after);
            $linked = $toMany
                ? array_map(static fn (object $o): mixed => $o->getId(), iterator_to_array($value ?? []))
                : [$value?->getId()];
            self::assertNotContains($targetId, $linked, sprintf('%s::$%s now points at a record of another organisation', $class, $property));
        }
    }

    /** @return list<HttpOperation> */
    private function operations(string $class, bool $all = false): array
    {
        $operations = [];
        foreach (static::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class)->create($class) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                if (!$operation instanceof HttpOperation) {
                    continue;
                }
                if (!$all && array_diff(array_keys($operation->getUriVariables() ?? []), ['id']) !== []) {
                    continue; // sub-resource routes: listed by everyOperationIsProbedOrSaysWhyNot()
                }
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    private function collectionPath(string $class, GetCollection $operation): string
    {
        return static::getContainer()->get(IriConverterInterface::class)
            ->getIriFromResource($class, UrlGeneratorInterface::ABS_PATH, $operation);
    }

    private function iri(object $record): string
    {
        return static::getContainer()->get(IriConverterInterface::class)->getIriFromResource($record);
    }

    /** A plain text property in the resource's write group, to try an overwrite with. */
    private function writableTextField(string $class): ?string
    {
        $meta = $this->em()->getClassMetadata($class);
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if (!$meta->hasField($property->getName()) || !in_array($meta->getTypeOfField($property->getName()), ['string', 'text'], true)) {
                continue;
            }
            if (ContractRecordFactory::inWriteGroup($property)) {
                return $property->getName();
            }
        }

        return null;
    }

    /** 'bearer' = API token, 'session' = the signed-in browser user (main firewall). */
    private function send(string $via, string $method, string $path, ?string $body = null, string $contentType = 'application/ld+json'): void
    {
        if ($via === 'bearer') {
            $this->bearer($method, $path, $body, $contentType);

            return;
        }
        $this->client->loginUser($this->em()->find(User::class, $this->user->getId()));
        $this->client->request($method, $path, server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => $contentType,
        ], content: $body);
    }

    private function bearer(string $method, string $path, ?string $body = null, string $contentType = 'application/ld+json'): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => $contentType,
        ], content: $body);
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function body(): string
    {
        return substr((string) $this->client->getResponse()->getContent(), 0, 2000);
    }

    /** The client reboots the kernel per request: read through the current container. */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function fresh(string $class, int $id): ?object
    {
        $em = $this->em();
        $em->clear();
        $em->getFilters()->isEnabled('tenant_filter') && $em->getFilters()->disable('tenant_filter');

        return $em->find($class, $id);
    }
}
