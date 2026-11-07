<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Training;
use Doctrine\ORM\Mapping\OrderBy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The REST collection filters moved from the deprecated #[ApiFilter] /
 * Operation::$filters (API Platform 4.4, removed in 6.0) to #[QueryParameter]
 * on GetCollection. API clients must not notice: this test freezes the public
 * query-parameter names that the old filters exposed and checks them both in
 * the resource metadata and in the generated OpenAPI document.
 *
 * Exact names per old filter:
 *   SearchFilter  → `x` and `x[]`
 *   BooleanFilter → `x`
 *   DateFilter    → `x[after]`, `x[before]`, `x[strictly_after]`, `x[strictly_before]`
 *   OrderFilter   → `order[x]`
 */
final class ApiCollectionParametersTest extends KernelTestCase
{
    /**
     * @var array<class-string, array{path: string, search: list<string>, bool: list<string>, date: list<string>, order: list<string>}>
     */
    private const array LEGACY_FILTERS = [
        Asset::class => [
            'path' => '/api/assets',
            'search' => ['name', 'assetType', 'owner', 'status'],
            'bool' => [],
            'date' => [],
            'order' => ['name', 'assetType', 'createdAt'],
        ],
        Control::class => [
            'path' => '/api/controls',
            'search' => ['controlId', 'name', 'category', 'implementationStatus'],
            'bool' => ['applicable'],
            'date' => ['targetDate', 'lastReviewDate'],
            'order' => ['controlId', 'category', 'implementationPercentage', 'targetDate'],
        ],
        Incident::class => [
            'path' => '/api/incidents',
            'search' => ['title', 'incidentNumber', 'severity', 'status', 'category'],
            'bool' => ['dataBreachOccurred', 'notificationRequired'],
            'date' => ['detectedAt', 'resolvedAt', 'closedAt'],
            'order' => ['detectedAt', 'severity', 'status'],
        ],
        InternalAudit::class => [
            'path' => '/api/internal_audits',
            'search' => ['title', 'auditNumber', 'status', 'scopeType'],
            'bool' => [],
            'date' => ['plannedDate', 'actualDate'],
            'order' => ['plannedDate', 'actualDate', 'status'],
        ],
        Risk::class => [
            'path' => '/api/risks',
            'search' => ['title', 'status', 'riskOwner.email', 'riskOwner.firstName', 'riskOwner.lastName'],
            'bool' => [],
            'date' => ['createdAt', 'reviewDate'],
            'order' => ['title', 'createdAt', 'status'],
        ],
        Supplier::class => [
            'path' => '/api/suppliers',
            'search' => ['name', 'status', 'criticality'],
            'bool' => [],
            'date' => ['nextAssessmentDate', 'lastSecurityAssessment'],
            'order' => ['name', 'criticality', 'nextAssessmentDate'],
        ],
        Training::class => [
            'path' => '/api/trainings',
            'search' => ['title', 'trainingType', 'status'],
            'bool' => ['mandatory'],
            'date' => ['scheduledDate', 'completionDate'],
            'order' => ['scheduledDate', 'status'],
        ],
    ];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function resources(): iterable
    {
        foreach (array_keys(self::LEGACY_FILTERS) as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resources')]
    public function collectionKeepsTheLegacyFilterNames(string $class): void
    {
        $spec = self::LEGACY_FILTERS[$class];
        $expected = [
            ...$spec['search'],
            ...$spec['bool'],
            ...$spec['date'],
            ...array_map(static fn (string $p): string => 'order[' . $p . ']', $spec['order']),
        ];
        sort($expected);

        $collections = 0;
        foreach ($this->metadataFactory()->create($class) as $resource) {
            foreach ($resource->getOperations() ?? [] as $name => $operation) {
                self::assertSame(
                    [],
                    $operation->getFilters() ?? [],
                    sprintf('%s: Operation::$filters is deprecated since API Platform 4.4 — declare a QueryParameter instead.', $name),
                );

                $filterKeys = [];
                foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                    if (null !== $parameter->getFilter()) {
                        $filterKeys[] = $key;
                    }
                }
                sort($filterKeys);

                if ($operation instanceof CollectionOperationInterface) {
                    ++$collections;
                    self::assertSame($expected, $filterKeys, sprintf('%s: public filter parameters changed.', $name));
                } else {
                    // The old filters only ever applied to collections; a filter on an
                    // item operation would turn GET /api/x/{id}?status=… into a 404.
                    self::assertSame([], $filterKeys, sprintf('%s: filters must stay collection-only.', $name));
                }
            }
        }

        self::assertSame(1, $collections, sprintf('%s: expected exactly one GetCollection.', $class));
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resources')]
    public function openApiDocumentsTheSameQueryParameters(string $class): void
    {
        $spec = self::LEGACY_FILTERS[$class];
        $expected = [];
        foreach ($spec['search'] as $p) {
            $expected[] = $p;
            $expected[] = $p . '[]';
        }
        foreach ($spec['bool'] as $p) {
            $expected[] = $p;
        }
        foreach ($spec['date'] as $p) {
            foreach (['after', 'before', 'strictly_after', 'strictly_before'] as $op) {
                $expected[] = $p . '[' . $op . ']';
            }
        }
        foreach ($spec['order'] as $p) {
            $expected[] = 'order[' . $p . ']';
        }

        $documented = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $this->collectionGetParameters($spec['path']),
        );

        $missing = array_values(array_diff($expected, $documented));
        self::assertSame([], $missing, sprintf('%s: OpenAPI lost query parameters.', $spec['path']));

        // Booleans and sort keys were never documented as arrays.
        foreach ([...$spec['bool'], ...array_map(static fn (string $p): string => 'order[' . $p . ']', $spec['order'])] as $scalar) {
            self::assertNotContains($scalar . '[]', $documented, sprintf('%s: "%s" must stay a scalar parameter.', $spec['path'], $scalar));
        }
    }

    #[Test]
    public function riskOwnerFiltersRequireTheSameRoleAsTheOwnerField(): void
    {
        foreach ($this->metadataFactory()->create(Risk::class) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                if (!$operation instanceof CollectionOperationInterface) {
                    continue;
                }
                foreach (['riskOwner.email', 'riskOwner.firstName', 'riskOwner.lastName'] as $key) {
                    $parameter = $operation->getParameters()?->get($key);
                    self::assertNotNull($parameter, $key);
                    self::assertSame("is_granted('ROLE_MANAGER')", (string) $parameter->getSecurity(), $key);
                }
            }
        }
    }

    #[Test]
    public function noEntityDeclaresTheDeprecatedApiFilterAttribute(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), '#[ApiFilter(')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, '#[ApiFilter] is deprecated since API Platform 4.4 — use #[QueryParameter] on GetCollection.');
    }

    #[Test]
    public function orderByMappingsUseSortDirection(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                foreach ($property->getAttributes(OrderBy::class) as $attribute) {
                    foreach ($attribute->newInstance()->value as $field => $direction) {
                        self::assertInstanceOf(
                            \SortDirection::class,
                            $direction,
                            sprintf('%s::$%s orders by "%s" with a string — doctrine/orm 3.7 deprecates that (issue 11313).', $class, $property->getName(), $field),
                        );
                        ++$checked;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    private function metadataFactory(): ResourceMetadataCollectionFactoryInterface
    {
        self::bootKernel();

        return self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
    }

    /**
     * @return list<\ApiPlatform\OpenApi\Model\Parameter>
     */
    private function collectionGetParameters(string $path): array
    {
        self::bootKernel();
        $openApi = self::getContainer()->get(OpenApiFactoryInterface::class)->__invoke();

        foreach ($openApi->getPaths()->getPaths() as $documentedPath => $pathItem) {
            if (preg_replace('/\{\._format\}$/', '', (string) $documentedPath) === $path && null !== $pathItem->getGet()) {
                return array_values($pathItem->getGet()->getParameters() ?? []);
            }
        }

        self::fail(sprintf('OpenAPI has no GET %s.', $path));
    }
}
