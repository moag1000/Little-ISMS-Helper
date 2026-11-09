<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Attribute\PersonalDataResource;
use App\Security\Persona\PersonaResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one answer to "which personal data may go to the assistant" — read
 * from {@see PersonalDataResource} on the entity classes, the same for the
 * REST gateway (list/get/save answers) and the global search tool. Records of
 * people beyond their name reach the external model only for the data
 * protection officer's role (GDPR Art. 5 (1) c, 25).
 */
final class PersonalDataPolicy
{
    /** The role that may hand personal data to the assistant. */
    public const string ROLE = 'ROLE_DPO';

    /** @var array<class-string, PersonalDataResource|null> */
    private array $markers = [];

    /** @var array<string, PersonalDataResource>|null */
    private ?array $searchGroups = null;

    public function __construct(
        private readonly PersonaResolver $personas,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @param class-string $class */
    public function marker(string $class): ?PersonalDataResource
    {
        if (is_subclass_of($class, \Doctrine\Persistence\Proxy::class)) {
            $class = (string) get_parent_class($class); // a lazy-loaded record's proxy class carries no attributes
        }
        if (!array_key_exists($class, $this->markers)) {
            $attributes = class_exists($class)
                ? (new \ReflectionClass($class))->getAttributes(PersonalDataResource::class)
                : [];
            $this->markers[$class] = $attributes === [] ? null : $attributes[0]->newInstance();
        }

        return $this->markers[$class];
    }

    public function mayReadPersonalData(McpSession $session): bool
    {
        return $this->personas->reaches($session->user, self::ROLE);
    }

    /**
     * Is the resource offered to this caller at all?
     *
     * @param class-string $class
     */
    public function offers(string $class, McpSession $session): bool
    {
        $marker = $this->marker($class);
        if ($marker === null) {
            return true;
        }
        if (!$marker->assistant) {
            return false;
        }

        return $marker->isFieldLevel() || $this->mayReadPersonalData($session);
    }

    /**
     * A record as the API serialised it, without the fields this caller may
     * not hand to the assistant.
     *
     * @param class-string        $class
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function strip(string $class, array $item, McpSession $session): array
    {
        $marker = $this->marker($class);
        if ($marker === null || !$marker->isFieldLevel() || $this->mayReadPersonalData($session)) {
            return $item;
        }

        return array_diff_key($item, array_flip($marker->fields));
    }

    /**
     * Search groups whose hits are records of a marked class.
     *
     * @return array<string, PersonalDataResource>
     */
    public function searchGroups(): array
    {
        if ($this->searchGroups !== null) {
            return $this->searchGroups;
        }
        $this->searchGroups = [];
        foreach (self::entityClasses($this->projectDir) as $class) {
            $marker = $this->marker($class);
            if ($marker?->searchGroup !== null) {
                $this->searchGroups[$marker->searchGroup] = $marker;
            }
        }

        return $this->searchGroups;
    }

    /**
     * Every class below src/Entity/.
     *
     * @return list<class-string>
     */
    public static function entityClasses(string $projectDir): array
    {
        $base = $projectDir . '/src/Entity/';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $class = 'App\\Entity\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($base), -4));
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }
        sort($classes);

        return $classes;
    }
}
