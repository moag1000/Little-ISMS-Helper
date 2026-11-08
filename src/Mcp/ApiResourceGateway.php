<?php

declare(strict_types=1);

namespace App\Mcp;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Attribute\ModuleField;
use App\Attribute\RequiresModule;
use App\Service\ModuleConfigurationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The REST API (API Platform) as the assistant's generic record reader and
 * writer.
 *
 * The resource list comes from the API Platform metadata, so every resource
 * the API offers — today and after new ones are added — is readable here
 * without a tool of its own. A read runs the resource's own GetCollection /
 * Get operation as an internal sub-request of the MCP request: same
 * `security` expressions and voters, same serialisation groups, same
 * filters, same tenant filter (armed on the main request for the token's
 * user). The module gate of the resource class is checked here as well.
 *
 * Writing runs the resource's own Post (create) and Patch / Put (update)
 * operation the same way: the operation's `security` / voter, its write
 * serialisation group, its validation and its state processor (tenant forced
 * on create) decide, exactly as for a person using the API. Never Delete.
 * On top: only tenant-owned resources (global catalogues and instance-wide
 * rows are never written by an organisation's token), only fields of the
 * write group (unknown keys are refused instead of silently dropped), and no
 * lifecycle status / tenant / id keys — status changes and approvals go
 * through LifecycleService and the suggestion queue. Whether the caller may
 * write at all is McpWriteAccess's answer, checked before every write.
 */
class ApiResourceGateway
{
    private const int MAX_ITEMS_PER_PAGE = 50;

    /** Keys an assistant never writes, whatever a write group says. */
    private const array FORBIDDEN_KEYS = ['id', 'tenant', 'status', 'lockVersion', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy'];

    /** @var array<string, array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>, create: ?string, update: ?string, update_method: ?string, write_groups: list<string>}>|null */
    private ?array $resources = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private readonly HttpKernelInterface $httpKernel,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ModuleConfigurationService $modules,
        private readonly PropertyNameCollectionFactoryInterface $propertyNames,
        private readonly PropertyMetadataFactoryInterface $propertyMetadata,
    ) {
    }

    /**
     * Readable resources for the organisation (module active), by short name.
     *
     * @return array<string, array{description: ?string, filters: list<string>, list: bool, get: bool, create: bool, update: bool}>
     */
    public function resources(McpSession $session): array
    {
        $list = [];
        foreach ($this->all() as $name => $resource) {
            if ($this->moduleActive($resource['class'], $session)) {
                $writable = self::tenantOwned($resource['class']) && $resource['write_groups'] !== [];
                $list[$name] = [
                    'description' => $resource['description'],
                    'filters' => $resource['filters'],
                    'list' => $resource['collection'] !== null,
                    'get' => $resource['item'] !== null,
                    'create' => $writable && $resource['create'] !== null,
                    'update' => $writable && $resource['update'] !== null,
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
     * Fields the assistant may send for a resource (its write group, minus
     * the keys that are never written).
     *
     * @return list<string>
     */
    public function writableFields(McpSession $session, string $resource): array
    {
        $meta = $this->resolve($session, $resource);

        return $this->fieldsOf($meta, $session);
    }

    /**
     * Create ($id null) or change one record through the resource's own API
     * operation. Returns the saved record as the API reads it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function write(McpSession $session, string $resource, ?int $id, array $data): array
    {
        $meta = $this->resolve($session, $resource);
        $operation = $id === null ? $meta['create'] : $meta['update'];
        // A write operation without a denormalisation group would accept every
        // field of the entity — never through the assistant.
        if ($operation === null || !self::tenantOwned($meta['class']) || $meta['write_groups'] === []) {
            throw new McpToolException(sprintf(
                'The resource "%s" cannot be %s by the assistant.',
                $resource,
                $id === null ? 'created' : 'changed',
            ));
        }
        if ($data === []) {
            throw new McpToolException('Argument "data" is empty: send the fields to set.');
        }

        $forbidden = array_values(array_intersect(array_keys($data), self::FORBIDDEN_KEYS));
        $forbidden = array_merge($forbidden, array_values(array_filter(array_keys($data), static fn (string|int $k): bool => str_starts_with((string) $k, '@'))));
        if ($forbidden !== []) {
            throw new McpToolException(sprintf(
                'Not writable here: %s. Status changes, approvals and acceptance go through the app (or propose_draft); tenant and ids are set by the app.',
                implode(', ', $forbidden),
            ));
        }
        $fields = $this->fieldsOf($meta, $session);
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $fields));
        if ($unknown !== []) {
            throw new McpToolException(sprintf(
                'Unknown or read-only fields for %s: %s. Writable fields: %s.',
                $resource,
                implode(', ', $unknown),
                implode(', ', $fields),
            ));
        }

        $path = $id === null
            ? $this->urlGenerator->generate($operation)
            : $this->urlGenerator->generate($operation, ['id' => $id]);
        $method = $id === null ? 'POST' : (string) $meta['update_method'];

        return self::compact($this->send($session, $method, $path, [], $data));
    }

    /**
     * Global catalogues and instance-wide rows carry no tenant: an organisation's token never writes them.
     *
     * @param class-string $class
     */
    private static function tenantOwned(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod('getTenant') && $reflection->hasMethod('setTenant');
    }

    /**
     * Write-group fields, minus the keys never written and the fields of a
     * module the organisation has switched off ({@see ModuleField}).
     *
     * @param array{class: class-string, write_groups: list<string>} $meta
     * @return list<string>
     */
    private function fieldsOf(array $meta, McpSession $session): array
    {
        if ($meta['write_groups'] === []) {
            return [];
        }
        $options = ['serializer_groups' => $meta['write_groups']];
        $offModule = [];
        foreach ((new \ReflectionClass($meta['class']))->getProperties() as $reflected) {
            foreach ($reflected->getAttributes(ModuleField::class) as $attribute) {
                if (!$this->modules->isModuleActiveForTenant($attribute->newInstance()->module, $session->tenant)) {
                    $offModule[] = $reflected->getName();
                }
            }
        }
        $fields = [];
        foreach ($this->propertyNames->create($meta['class']) as $property) {
            if (in_array($property, self::FORBIDDEN_KEYS, true) || in_array($property, $offModule, true)) {
                continue;
            }
            if ($this->propertyMetadata->create($meta['class'], $property, $options)->isWritable() === true) {
                $fields[] = $property;
            }
        }
        sort($fields);

        return $fields;
    }

    /**
     * @return array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>, create: ?string, update: ?string, update_method: ?string, write_groups: list<string>}
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
        return $this->send($session, 'GET', $path, $query, null);
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(McpSession $session, string $method, string $path, array $query, ?array $body): array
    {
        $main = $this->requestStack->getMainRequest();
        $content = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // The MCP request's own body headers do not describe the sub-request's body.
        $server = array_diff_key($main?->server->all() ?? [], ['CONTENT_TYPE' => true, 'CONTENT_LENGTH' => true, 'HTTP_CONTENT_LENGTH' => true]);
        $sub = Request::create($path, $method, $query, server: $server, content: $content);
        $sub->headers->set('Accept', 'application/ld+json');
        if ($body !== null) {
            $sub->headers->set('Content-Type', $method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json');
        }
        if ($main !== null) {
            $sub->headers->set('Authorization', (string) $main->headers->get('Authorization', ''));
            if ($main->hasSession()) {
                $sub->setSession($main->getSession());
            }
        }

        $response = $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw McpToolException::denied($method === 'GET' ? 'Access denied: your role may not read this.' : 'Access denied: your role may not change this.');
        }
        if ($status === 404) {
            throw new McpToolException('Not found in your organisation.');
        }
        if ($status >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $violations = is_array($body) && is_array($body['violations'] ?? null) ? $body['violations'] : [];
            if ($violations !== []) {
                $lines = array_map(
                    static fn (mixed $v): string => is_array($v) ? trim(((string) ($v['propertyPath'] ?? '')) . ': ' . ((string) ($v['message'] ?? ''))) : '',
                    $violations,
                );
                throw new McpToolException('Not saved, please correct: ' . mb_substr(implode('; ', array_filter($lines)), 0, 1000));
            }
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
     * @return array<string, array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>, create: ?string, update: ?string, update_method: ?string, write_groups: list<string>}>
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
                    if (!$operation instanceof GetCollection && !$operation instanceof Get
                        && !$operation instanceof Post && !$operation instanceof Patch && !$operation instanceof Put) {
                        continue;
                    }
                    $short = (string) ($operation->getShortName() ?? $apiResource->getShortName() ?? (new \ReflectionClass($class))->getShortName());
                    $entry = $this->resources[$short] ?? [
                        'class' => $class, 'collection' => null, 'item' => null, 'description' => $apiResource->getDescription(), 'filters' => [],
                        'create' => null, 'update' => null, 'update_method' => null, 'write_groups' => [],
                    ];
                    $hasId = str_contains((string) $operation->getUriTemplate(), '{id}');
                    if ($operation instanceof Post || $operation instanceof Patch || $operation instanceof Put) {
                        $entry = self::withWriteOperation($entry, $operation, (string) $name, $hasId);
                    } elseif ($operation instanceof GetCollection) {
                        $entry['collection'] ??= (string) $name;
                        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                            $entry['filters'][] = (string) $key;
                        }
                        $entry['filters'] = array_values(array_unique($entry['filters']));
                        $entry['description'] ??= $operation->getDescription();
                    } elseif ($hasId) {
                        $entry['item'] ??= (string) $name;
                    }
                    $this->resources[$short] = $entry;
                }
            }
        }

        return $this->resources;
    }

    /**
     * Create = a Post without {id}; update = a Patch with {id}, or a Put with
     * {id} whose semantics populate the existing object (standard_put off —
     * a replacing PUT would reset every field that is not sent).
     *
     * @param array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>, create: ?string, update: ?string, update_method: ?string, write_groups: list<string>} $entry
     * @return array{class: class-string, collection: ?string, item: ?string, description: ?string, filters: list<string>, create: ?string, update: ?string, update_method: ?string, write_groups: list<string>}
     */
    private static function withWriteOperation(array $entry, HttpOperation $operation, string $name, bool $hasId): array
    {
        $groups = array_values(array_map('strval', (array) ($operation->getDenormalizationContext()['groups'] ?? [])));
        if ($operation instanceof Post && !$hasId && $entry['create'] === null) {
            $entry['create'] = $name;
            $entry['write_groups'] = array_values(array_unique([...$entry['write_groups'], ...$groups]));
        } elseif ($operation instanceof Patch && $hasId && $entry['update_method'] !== 'PATCH') {
            $entry['update'] = $name;
            $entry['update_method'] = 'PATCH';
            $entry['write_groups'] = array_values(array_unique([...$entry['write_groups'], ...$groups]));
        } elseif ($operation instanceof Put && $hasId && $entry['update'] === null
            && ($operation->getExtraProperties()['standard_put'] ?? true) === false) {
            $entry['update'] = $name;
            $entry['update_method'] = 'PUT';
            $entry['write_groups'] = array_values(array_unique([...$entry['write_groups'], ...$groups]));
        }

        return $entry;
    }
}
