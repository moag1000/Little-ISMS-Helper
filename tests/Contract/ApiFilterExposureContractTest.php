<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use App\Attribute\PersonalDataResource;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ApiTokenManager;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S24 contract: what a REST collection lets a client ask for — every filter
 * and sort parameter of every GetCollection, discovered from the API Platform
 * metadata, so a new parameter is covered the day it is added.
 *
 *  - a parameter only reaches fields the answer shows anyway (every segment
 *    of its path is in the operation's read group), or it carries its own
 *    `security` and a reason below — no oracle on hidden fields;
 *  - no parameter path touches a secret (password, token, secret, hash, MFA);
 *  - a client cannot switch pagination off (`?pagination=false` pulled a whole
 *    collection with its relations in one request) and a page has ≤ 100 items;
 *  - a resource whose answer carries contact or tracking data of people is
 *    marked #[PersonalDataResource] (assistant rules) or needs more than
 *    ROLE_USER;
 *  - per parameter, organisation A's token filtering with the value of
 *    organisation B's record never gets B's record, and with A's own value
 *    finds A's record (an empty answer does not pass).
 */
final class ApiFilterExposureContractTest extends WebTestCase
{
    /**
     * Parameters on fields outside the read group, each with the reason it is no leak.
     *
     * @var array<string, string> "<ShortClass> <parameter key>" => reason
     */
    private const array HIDDEN_FIELD_FILTERS = [
        'Risk riskOwner.email' => 'ROLE_MANAGER only (parameter security); owners of the own organisation\'s risks, whom a manager sees in the UI',
        'Risk riskOwner.firstName' => 'ROLE_MANAGER only; the owner\'s name is shown on the risk as display name',
        'Risk riskOwner.lastName' => 'ROLE_MANAGER only; the owner\'s name is shown on the risk as display name',
        'ComplianceRequirementFulfillment framework' => 'requirement.framework.code of the global catalogue: no organisation data',
    ];

    /** Path segments that never belong in a filter. */
    private const string SECRET_SEGMENT = '/password|token|secret|hash|mfa|totp|backupcode/i';

    /** Fields that describe a person beyond the name (contact, badge, network address). */
    private const array PERSON_DETAIL_FIELDS = ['email', 'phone', 'mobile', 'badgeId', 'ipAddress', 'personName', 'dateOfBirth', 'privateAddress'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return iterable<string, array{class-string, GetCollection}> */
    private function collections(): iterable
    {
        $metadata = static::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        foreach (CrossTenantApiContractTest::apiResources() as $class) {
            foreach ($metadata->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    if ($operation instanceof GetCollection) {
                        yield $class . ' ' . $operation->getName() => [$class, $operation];
                    }
                }
            }
        }
    }

    /**
     * The property paths a parameter reaches (`order[:property]` → each listed property).
     *
     * @return list<string>
     */
    private static function paths(string $key, Parameter $parameter): array
    {
        if (str_contains($key, ':property')) {
            return array_values(array_map('strval', $parameter->getProperties() ?? []));
        }

        return [(string) ($parameter->getProperty() ?? $key)];
    }

    #[Test]
    public function everyFilterReachesOnlyFieldsTheAnswerShows(): void
    {
        $properties = static::getContainer()->get(PropertyMetadataFactoryInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hiddenSeen = [];
        $failures = [];
        $parameters = 0;
        foreach ($this->collections() as [$class, $operation]) {
            $groups = (array) ($operation->getNormalizationContext()['groups'] ?? []);
            $short = (new ReflectionClass($class))->getShortName();
            foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                ++$parameters;
                foreach (self::paths((string) $key, $parameter) as $path) {
                    if (preg_match(self::SECRET_SEGMENT, $path) === 1) {
                        $failures[] = sprintf('%s %s: filter on a secret (%s)', $short, $key, $path);
                    }
                    $current = $class;
                    $visible = true;
                    foreach (explode('.', $path) as $segment) {
                        $readable = $properties->create($current, $segment, ['serializer_groups' => $groups])->isReadable();
                        if ($readable !== true) {
                            $visible = false;
                            break;
                        }
                        $meta = $em->getClassMetadata($current);
                        if (!$meta->hasAssociation($segment)) {
                            break;
                        }
                        $current = $meta->getAssociationTargetClass($segment);
                    }
                    if ($visible) {
                        continue;
                    }
                    $entry = $short . ' ' . $key;
                    if (!isset(self::HIDDEN_FIELD_FILTERS[$entry])) {
                        $failures[] = sprintf('%s: filter on %s, which the answer does not show (read groups %s)', $entry, $path, implode(', ', $groups));
                    }
                    $hiddenSeen[$entry] = true;
                }
            }
        }
        self::assertGreaterThan(40, $parameters, 'the discovery found fewer parameters than exist');
        self::assertSame([], $failures);
        self::assertSame([], array_values(array_diff(array_keys(self::HIDDEN_FIELD_FILTERS), array_keys($hiddenSeen))), 'Stale HIDDEN_FIELD_FILTERS entry.');
    }

    #[Test]
    public function aClientCannotSwitchPaginationOff(): void
    {
        foreach ($this->collections() as $name => [, $operation]) {
            self::assertNotFalse($operation->getPaginationEnabled(), $name . ': pagination off');
            self::assertFalse($operation->getPaginationClientEnabled() ?? true, $name . ': ?pagination=false is honoured');
            self::assertLessThanOrEqual(100, $operation->getPaginationMaximumItemsPerPage() ?? PHP_INT_MAX, $name . ': no page limit');
        }

        // And over the wire: 31 risks, ?pagination=false → one page, with a next page.
        [$token, $tenant] = $this->tokenOfNewOrganisation();
        $factory = new ContractRecordFactory($this->em());
        for ($i = 0; $i < 31; ++$i) {
            $factory->create(Risk::class, $this->em()->find(Tenant::class, $tenant->getId()), 'zzpage' . $i);
        }
        $this->em()->flush();
        $this->client->request('GET', '/api/risks?pagination=false', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        $view = $body['view'] ?? $body['hydra:view'] ?? [];
        self::assertLessThanOrEqual(30, count($members), '?pagination=false returned more than one default page');
        self::assertTrue(isset($view['next']) || isset($view['hydra:next']), 'the answer points to the next page');
    }

    #[Test]
    public function aResourceWithPersonDetailsIsMarkedOrRestricted(): void
    {
        $properties = static::getContainer()->get(PropertyMetadataFactoryInterface::class);
        $failures = [];
        foreach ($this->collections() as $name => [$class, $operation]) {
            $groups = (array) ($operation->getNormalizationContext()['groups'] ?? []);
            $attributes = (new ReflectionClass($class))->getAttributes(PersonalDataResource::class);
            $marker = $attributes === [] ? null : $attributes[0]->newInstance();
            $restricted = !str_contains((string) $operation->getSecurity(), "'ROLE_USER'");
            if ($marker !== null && !$marker->assistant && !$restricted) {
                $failures[] = sprintf('%s: a log no page shows (assistant: false) is readable by every ROLE_USER', $name);
            }
            $details = array_values(array_filter(
                self::PERSON_DETAIL_FIELDS,
                static fn (string $field): bool => property_exists($class, $field)
                    && $properties->create($class, $field, ['serializer_groups' => $groups])->isReadable() === true,
            ));
            if ($details === []) {
                continue;
            }
            if ($marker === null && !$restricted) {
                $failures[] = sprintf('%s shows %s to every ROLE_USER and to the assistant: mark it #[PersonalDataResource] or restrict the read', $name, implode(', ', $details));
            }
            $missing = $marker !== null && $marker->isFieldLevel() ? array_diff($details, $marker->fields) : [];
            if ($missing !== []) {
                $failures[] = sprintf('%s: #[PersonalDataResource] fields miss %s', $name, implode(', ', $missing));
            }
        }
        self::assertSame([], $failures);
    }

    /** @return iterable<string, array{class-string}> */
    public static function tenantOwnedResources(): iterable
    {
        yield from CrossTenantApiContractTest::tenantOwnedResources();
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('tenantOwnedResources')]
    public function noFilterReachesAnotherOrganisationsRecord(string $class): void
    {
        $operations = [];
        foreach ($this->collections() as [$candidate, $operation]) {
            if ($candidate === $class && ($operation->getParameters()?->count() ?? 0) > 0) {
                $operations[] = $operation;
            }
        }
        if ($operations === []) {
            $this->expectNotToPerformAssertions();

            return;
        }

        [$token, $own] = $this->tokenOfNewOrganisation();
        $id = bin2hex(random_bytes(4));
        $foreignTenant = (new Tenant())->setName('Filter Foreign ' . $id)->setCode('fff_' . $id);
        $this->em()->persist($foreignTenant);
        $factory = new ContractRecordFactory($this->em());
        $leak = 'zzfleak' . $id;
        $ownMarker = 'zzfown' . $id;
        $foreignRecord = $factory->create($class, $foreignTenant, $leak);
        $ownRecord = $factory->create($class, $this->em()->find(Tenant::class, $own->getId()), $ownMarker);
        $this->em()->flush();

        $iris = static::getContainer()->get(IriConverterInterface::class);
        $em = $this->em();
        foreach ($operations as $operation) {
            $path = $iris->getIriFromResource($class, UrlGeneratorInterface::ABS_PATH, $operation);
            foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                $key = (string) $key;
                if (str_starts_with($key, 'order[') && !str_contains($key, ':property')) {
                    $answer = $this->get($token, $path, [$key => 'asc']);
                    self::assertStringNotContainsString($leak, $answer, sprintf('%s %s lists another organisation\'s record', $class, $key));
                    continue;
                }
                if (str_contains($key, ':property')) {
                    foreach (self::paths($key, $parameter) as $property) {
                        $answer = $this->get($token, $path, ['order' => [$property => 'asc']]);
                        self::assertStringNotContainsString($leak, $answer, sprintf('%s order[%s] lists another organisation\'s record', $class, $property));
                    }
                    continue;
                }
                $property = self::paths($key, $parameter)[0];
                $meta = $em->getClassMetadata($class);
                if (str_contains($property, '.') || !$meta->hasField($property)) {
                    // Relation paths and virtual parameters: B's record never comes back, whatever the value.
                    $answer = $this->get($token, $path, [$key => $leak]);
                    self::assertStringNotContainsString($leak, $answer, sprintf('%s ?%s lists another organisation\'s record', $class, $key));
                    continue;
                }
                $type = $meta->getTypeOfField($property);
                $foreignValue = self::queryValue((new ReflectionClass($class))->getProperty($property)->getValue($foreignRecord));
                $query = match (true) {
                    in_array($type, ['date', 'date_immutable', 'datetime', 'datetime_immutable'], true) => [$key => ['before' => '2999-01-01']],
                    $type === 'boolean' => [$key => $foreignValue ?? 'true'],
                    default => [$key => $foreignValue ?? $leak],
                };
                $answer = $this->get($token, $path, $query);
                self::assertStringNotContainsString($leak, $answer, sprintf('%s ?%s=%s lists another organisation\'s record', $class, $key, (string) json_encode($query[$key])));

                $ownValue = self::queryValue((new ReflectionClass($class))->getProperty($property)->getValue($ownRecord));
                if (in_array($type, ['string', 'text'], true) && is_string($ownValue) && str_contains($ownValue, $ownMarker)) {
                    $mine = $this->get($token, $path, [$key => $ownValue]);
                    self::assertStringContainsString($ownMarker, $mine, sprintf('%s ?%s does not find the own record (the probe is not vacuous)', $class, $key));
                }
            }
        }
    }

    private static function queryValue(mixed $value): ?string
    {
        return match (true) {
            $value instanceof \BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * The records of the answer, as JSON.
     *
     * @param array<string, mixed> $query
     */
    private function get(string $token, string $path, array $query): string
    {
        $this->client->request('GET', $path . '?' . http_build_query($query), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $status = $this->client->getResponse()->getStatusCode();
        $body = (string) $this->client->getResponse()->getContent();
        // 400: filter on a switched-off module's field; 422: a value the parameter does not take.
        self::assertContains($status, [200, 400, 422], sprintf('GET %s?%s answered %d: %s', $path, http_build_query($query), $status, mb_substr($body, 0, 800)));
        // Only the records count: the answer's view echoes the query (and with it the probe value).
        $decoded = (array) json_decode($body, true);

        return (string) json_encode($decoded['member'] ?? $decoded['hydra:member'] ?? []);
    }

    /**
     * A writing admin token (with every persona role) of a new organisation.
     *
     * @return array{string, Tenant}
     */
    private function tokenOfNewOrganisation(): array
    {
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $tenant = (new Tenant())->setName('Filter Own ' . $id)->setCode('ffo_' . $id);
        $em->persist($tenant);
        $user = (new User())
            ->setEmail('filter-contract-' . $id . '@example.test')
            ->setFirstName('Fia')
            ->setLastName('Filter')
            ->setRoles(['ROLE_ADMIN', 'ROLE_CISO', 'ROLE_ISB', 'ROLE_DPO', 'ROLE_RISK_MANAGER', 'ROLE_BCM_OFFICER'])
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();

        return [static::getContainer()->get(ApiTokenManager::class)->mint($user, 'Filter contract'), $tenant];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
