<?php

declare(strict_types=1);

namespace App\Service\Deadline\Configured;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The deadline types declared in `config/deadline_types.yaml`
 * (parameter `app.deadline_types`), one {@see ConfiguredDeadlineSource} each.
 * {@see \App\Service\Deadline\DeadlineSourceRegistry} adds them to the
 * sources written as classes.
 */
final class ConfiguredDeadlineSources
{
    /** @var list<ConfiguredDeadlineSource>|null */
    private ?array $sources = null;

    /**
     * @param array<string, array<string, mixed>> $types
     */
    public function __construct(
        #[Autowire(param: 'app.deadline_types')]
        private readonly array $types,
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $accessor,
    ) {
    }

    /**
     * The types of a deadline_types.yaml file, outside the container (tests,
     * tooling). Default: the app's `config/deadline_types.yaml`.
     */
    public static function fromFile(EntityManagerInterface $entityManager, ?string $path = null): self
    {
        $config = Yaml::parseFile($path ?? dirname(__DIR__, 4) . '/config/deadline_types.yaml');

        return new self($config['parameters']['app.deadline_types'] ?? [], $entityManager, PropertyAccess::createPropertyAccessor());
    }

    public function get(string $slug): ?ConfiguredDeadlineSource
    {
        foreach ($this->all() as $source) {
            if ($source->slug() === $slug) {
                return $source;
            }
        }

        return null;
    }

    /** @return list<ConfiguredDeadlineSource> */
    public function all(): array
    {
        if ($this->sources === null) {
            $expressions = self::expressionLanguage();
            $this->sources = [];
            foreach ($this->types as $slug => $config) {
                $this->sources[] = new ConfiguredDeadlineSource(
                    DeadlineTypeDefinition::fromArray((string) $slug, $config),
                    $this->entityManager,
                    $expressions,
                    $this->accessor,
                );
            }
        }

        return $this->sources;
    }

    /**
     * Expression language for `done` and `title`, with `words(a, b, …)`:
     * the non-empty parts joined by a space.
     */
    public static function expressionLanguage(): ExpressionLanguage
    {
        $language = new ExpressionLanguage();
        $language->addFunction(new ExpressionFunction(
            'words',
            static fn (string ...$parts): string => sprintf('\trim(\implode(" ", \array_filter([%s], static fn ($p): bool => (string) $p !== "")))', implode(', ', $parts)),
            static fn (array $variables, mixed ...$parts): string => trim(implode(' ', array_filter(
                array_map(static fn (mixed $p): string => trim((string) $p), $parts),
                static fn (string $p): bool => $p !== '',
            ))),
        ));

        return $language;
    }
}
