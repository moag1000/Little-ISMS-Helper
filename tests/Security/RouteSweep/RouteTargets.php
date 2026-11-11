<?php

declare(strict_types=1);

namespace App\Tests\Security\RouteSweep;

use App\Entity\Tenant;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * T4 router sweeps: every route of the app with a URL a test can call. Path variables are filled from the action's
 * signature: an entity argument gets a record of the organisation (built from the Doctrine mapping by
 * ContractRecordFactory, so a new entity is covered the day it is added) and its id goes into `{id}` or the
 * variable of the argument's name; a variable with an alternation requirement takes its first alternative, one with
 * a default keeps it, a free text variable the action takes as a string gets `sweep`, `{_locale}` is `de`. A route whose variables cannot be filled is returned as skipped with the
 * reason, so a test can bound how many it misses instead of skipping silently.
 */
final class RouteTargets
{
    /** @var array<string, int> */
    private array $records = [];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly Tenant $tenant,
    ) {
    }

    /**
     * Routes of App\Controller, by name.
     *
     * @return array<string, array{route: Route, class: class-string, method: string}>
     */
    public function appRoutes(): array
    {
        $routes = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'App\\')) {
                continue;
            }
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }
            $routes[$name] = ['route' => $route, 'class' => $class, 'method' => $method];
        }
        ksort($routes);

        return $routes;
    }

    /**
     * The URL of a route with every variable filled, or the reason it cannot be filled.
     *
     * @param array{route: Route, class: class-string, method: string} $target
     * @return array{url: ?string, skipped: ?string}
     */
    public function url(string $name, array $target): array
    {
        $route = $target['route'];
        $variables = $route->compile()->getPathVariables();
        $entities = [];
        $strings = [];
        foreach (new \ReflectionMethod($target['class'], $target['method'])->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && str_starts_with($type->getName(), 'App\Entity\\')) {
                $entities[$parameter->getName()] = $type->getName();
            } elseif ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                $strings[] = $parameter->getName();
            }
        }

        $values = [];
        foreach ($variables as $variable) {
            if ($variable === '_locale' || $variable === 'locale') {
                $values[$variable] = 'de';
                continue;
            }
            $class = $entities[$variable] ?? null;
            if ($class === null && in_array($variable, ['id', 'uuid'], true) && count($entities) === 1) {
                $class = array_values($entities)[0];
            }
            if ($class === null && str_ends_with($variable, 'Id') && isset($entities[substr($variable, 0, -2)])) {
                $class = $entities[substr($variable, 0, -2)];
            }
            if ($class !== null) {
                $id = $this->recordId($class);
                if ($id === null) {
                    return ['url' => null, 'skipped' => sprintf('%s: no record of %s can be built', $name, $class)];
                }
                $values[$variable] = $id;
                continue;
            }
            if ($route->hasDefault($variable) && $route->getDefault($variable) !== null) {
                continue;
            }
            $requirement = $route->getRequirement($variable);
            if ($requirement !== null && preg_match('/^\(?([\w.-]+)(\||\)?$)/', $requirement, $m) === 1 && !str_starts_with($requirement, '\\')) {
                $values[$variable] = $m[1];
                continue;
            }

            // A free text variable (`{category}`, `{type}`) the action takes as a string: any value reaches the action.
            if (in_array($variable, $strings, true) && preg_match('#^(?:' . ($requirement ?? '[^/]+') . ')$#', 'sweep') === 1) {
                $values[$variable] = 'sweep';
                continue;
            }

            return ['url' => null, 'skipped' => sprintf('%s: {%s} names no entity argument', $name, $variable)];
        }

        try {
            return ['url' => $this->router->generate($name, $values), 'skipped' => null];
        } catch (\Throwable $e) {
            return ['url' => null, 'skipped' => sprintf('%s: %s', $name, $e->getMessage())];
        }
    }

    /** @param class-string $class */
    private function recordId(string $class): ?int
    {
        if (array_key_exists($class, $this->records)) {
            return $this->records[$class];
        }
        $id = null;
        try {
            $meta = $this->em->getClassMetadata($class);
            if ($meta->hasAssociation('tenant')) {
                $record = new ContractRecordFactory($this->em)->create($class, $this->em->find(Tenant::class, $this->tenant->getId()), 'sweep');
                $this->em->flush();
                $id = (int) $record->getId();
            } else {
                $found = $this->em->getRepository($class)->findOneBy([]);
                $id = $found === null ? null : (int) $this->em->getUnitOfWork()->getSingleIdentifierValue($found);
            }
        } catch (\Throwable) {
            if (!$this->em->isOpen()) {
                throw new \RuntimeException('EntityManager closed while building a ' . $class);
            }
        }

        return $this->records[$class] = $id;
    }
}
