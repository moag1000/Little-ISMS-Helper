<?php

declare(strict_types=1);

namespace App\Mcp;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Attribute\RequiresModule;
use App\Service\ModuleConfigurationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The REST API (API Platform) as the assistant's generic record reader.
 *
 * The resource list comes from the API Platform metadata, so every resource
 * the API offers — today and after new ones are added — is readable here
 * without a tool of its own. A read runs the resource's own GetCollection /
 * Get operation as an internal sub-request of the MCP request: same
 * `security` expressions and voters, same serialisation groups, same
 * filters, same tenant filter (armed on the main request for the token's
 * user). Only the module gate of the resource class is checked here,
 * because ApiResourceModuleListener only looks at main requests.
 */
class ApiResourceGateway
{
    private const int MAX_ITEMS_PER_PAGE = 50;

    /** @var array<string, array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>}>|null */
    private ?array $resources = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private readonly HttpKernelInterface $httpKernel,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    /**
     * Readable resources for the organisation (module active), by short name.
     *
     * @return array<string, array{description: ?string, filters: list<string>, list: bool, get: bool}>
     */
    public function resources(McpSession $session): array
    {
        $list = [];
        foreach ($this->all() as $name => $resource) {
            if ($this->moduleActive($resource['class'], $session)) {
                $list[$name] = [
                    'description' => $resource['description'],
                    'filters' => $resource['filters'],
                    'list' => $resource['collection'] !== null,
                    'get' => $resource['item'] !== null,
                ];
            }
        }
        ksort($list);

        return $list;
    }

    /**
     * One page of a resource's collection.
     *
     * @param array<string, mixed> $filters query parameters of the API operation (title, status, order[title], …)
     * @return array<string, mixed>
     */
    public function list(McpSession $session, string $resource, array $filters, int $page, int $itemsPerPage): array
    {
        $meta = $this->resolve($session, $resource);
        if ($meta['collection'] === null) {
            throw new McpToolException(sprintf('The resource "%s" cannot be listed.', $resource));
        }
        $itemsPerPage = max(1, min(self::MAX_ITEMS_PER_PAGE, $itemsPerPage));
        $query = array_filter($filters, static fn ($v): bool => is_scalar($v) || is_array($v));
        unset($query['page'], $query['itemsPerPage'], $query['pagination']);
        $query += ['page' => max(1, $page), 'itemsPerPage' => $itemsPerPage];

        $data = $this->fetch($session, $this->urlGenerator->generate($meta['collection']), $query);
        $items = $data['member'] ?? $data['hydra:member'] ?? [];
        $total = $data['totalItems'] ?? $data['hydra:totalItems'] ?? null;
        $view = $data['view'] ?? $data['hydra:view'] ?? [];

        return [
            'resource' => $resource,
            'page' => max(1, $page),
            'items_per_page' => $itemsPerPage,
            'total' => is_int($total) ? $total : null,
            'has_next_page' => is_array($view) && (isset($view['next']) || isset($view['hydra:next'])),
            'items' => is_array($items) ? array_values(array_map(self::compact(...), $items)) : [],
        ];
    }

    /**
     * One record of a resource.
     *
     * @return array<string, mixed>
     */
    public function get(McpSession $session, string $resource, int $id): array
    {
        $meta = $this->resolve($session, $resource);
        if ($meta['item'] === null) {
            throw new McpToolException(sprintf('The resource "%s" has no single-record read.', $resource));
        }

        return self::compact($this->fetch($session, $this->urlGenerator->generate($meta['item'], ['id' => $id]), []));
    }

    /**
     * @return array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>}
     */
    private function resolve(McpSession $session, string $resource): array
    {
        $all = $this->all();
        $meta = $all[$resource] ?? null;
        if ($meta === null) {
            foreach ($all as $name => $candidate) {
                if (strcasecmp($name, $resource) === 0) {
                    $meta = $candidate;
                    break;
                }
            }
        }
        if ($meta === null || !$this->moduleActive($meta['class'], $session)) {
            throw new McpToolException(sprintf('Unknown resource "%s". Call get_context for the list of resources.', $resource));
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetch(McpSession $session, string $path, array $query): array
    {
        $main = $this->requestStack->getMainRequest();
        $sub = Request::create($path, 'GET', $query, server: $main?->server->all() ?? []);
        $sub->headers->set('Accept', 'application/ld+json');
        if ($main !== null) {
            $sub->headers->set('Authorization', (string) $main->headers->get('Authorization', ''));
            if ($main->hasSession()) {
                $sub->setSession($main->getSession());
            }
        }

        $response = $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new McpToolException('Access denied: your role may not read this.');
        }
        if ($status === 404) {
            throw new McpToolException('Not found in your organisation.');
        }
        if ($status >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $detail = is_array($body) ? (string) ($body['detail'] ?? $body['description'] ?? $body['hydra:description'] ?? '') : '';
            throw new McpToolException(sprintf('The API refused the request (%d)%s', $status, $detail !== '' ? ': ' . mb_substr($detail, 0, 300) : '.'));
        }
        $data = json_decode((string) $response->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Drops the JSON-LD context noise the assistant does not need.
     *
     * @param mixed $item
     * @return array<string, mixed>
     */
    private static function compact(mixed $item): array
    {
        if (!is_array($item)) {
            return ['value' => $item];
        }
        unset($item['@context']);

        return $item;
    }

    /** @param class-string $class */
    private function moduleActive(string $class, McpSession $session): bool
    {
        foreach ((new \ReflectionClass($class))->getAttributes(RequiresModule::class) as $attribute) {
            if (!$this->modules->isModuleActiveForTenant($attribute->newInstance()->module, $session->tenant)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>}>
     */
    private function all(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }
        $this->resources = [];
        foreach ($this->resourceNames->create() as $class) {
            foreach ($this->resourceMetadata->create($class) as $apiResource) {
                foreach ($apiResource->getOperations() ?? [] as $name => $operation) {
                    if (!$operation instanceof GetCollection && !$operation instanceof Get) {
                        continue;
                    }
                    $short = (string) ($operation->getShortName() ?? $apiResource->getShortName() ?? (new \ReflectionClass($class))->getShortName());
                    $entry = $this->resources[$short] ?? ['class' => $class, 'collection' => null, 'item' => null, 'description' => $apiResource->getDescription(), 'filters' => []];
                    if ($operation instanceof GetCollection) {
                        $entry['collection'] ??= (string) $name;
                        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                            $entry['filters'][] = (string) $key;
                        }
                        $entry['filters'] = array_values(array_unique($entry['filters']));
                        $entry['description'] ??= $operation->getDescription();
                    } elseif (str_contains((string) $operation->getUriTemplate(), '{id}')) {
                        $entry['item'] ??= (string) $name;
                    }
                    $this->resources[$short] = $entry;
                }
            }
        }

        return $this->resources;
    }
}
