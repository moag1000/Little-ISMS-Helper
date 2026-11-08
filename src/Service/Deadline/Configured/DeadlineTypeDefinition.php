<?php

declare(strict_types=1);

namespace App\Service\Deadline\Configured;

use App\Exception\InvalidArgument\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One deadline type from `config/deadline_types.yaml`: a date field of one
 * tenant-owned entity, when the duty is done, who acts and how the inbox
 * shows it. {@see ConfiguredDeadlineSource} turns it into a deadline source.
 *
 * - due:      property path of the date, or null for a duty that is open
 *             without a date (shown while open).
 * - done:     expression over `item`; true = no duty (archived, closed, …).
 * - title:    expression over `item`; `words(a, b, …)` joins the non-empty parts.
 * - holders / deputies: property paths to User / Person (or collections of
 *             them) on the record; `self` = the record itself.
 * - roles:    fallback roles when no holder resolves.
 * - inform:   kept informed — role names (ROLE_*) or property paths.
 * - link:     route of the record's page; `link_id: false` for a page
 *             without the record id (e.g. an index), a property path for
 *             the page of a related record (`requirement.id`: a fulfilment
 *             lives on its requirement's page).
 */
#[Exclude]
final readonly class DeadlineTypeDefinition
{
    private const array KEYS = [
        'label', 'entity', 'module', 'due', 'done', 'title', 'holders', 'deputies', 'roles', 'inform',
        'link', 'link_id', 'bucket', 'lead_days', 'norm',
    ];

    /** @var class-string */
    public string $entity;
    public string $label;
    public ?string $module;
    public ?string $due;
    public string $done;
    public string $title;
    /** @var list<string> */
    public array $holders;
    /** @var list<string> */
    public array $deputies;
    /** @var list<string> */
    public array $roles;
    /** @var list<string> */
    public array $inform;
    public ?string $route;
    public bool $routeWithId;
    /** Property path of the id the route takes; null = the record's own id. */
    public ?string $routeIdPath;
    public string $bucket;
    public ?int $leadDays;
    public ?string $norm;

    /**
     * @param array<string, mixed> $config already validated by {@see fromArray()}
     */
    private function __construct(public string $slug, array $config)
    {
        $this->label = $config['label'];
        $this->entity = $config['entity'];
        $this->module = $config['module'] ?? null;
        $this->due = $config['due'];
        $this->done = (string) ($config['done'] ?? 'false');
        $this->title = $config['title'];
        $this->holders = self::strings($config['holders'] ?? []);
        $this->deputies = self::strings($config['deputies'] ?? []);
        $this->roles = self::strings($config['roles'] ?? []);
        $this->inform = self::strings($config['inform'] ?? []);
        $this->route = $config['link'] ?? null;
        $linkId = $config['link_id'] ?? true;
        $this->routeWithId = $linkId !== false;
        $this->routeIdPath = is_string($linkId) ? $linkId : null;
        $this->bucket = $config['bucket'];
        $leadDays = array_key_exists('lead_days', $config) ? $config['lead_days'] : 14;
        $this->leadDays = $leadDays === null ? null : (int) $leadDays;
        $this->norm = $config['norm'] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $slug, array $config): self
    {
        $unknown = array_diff(array_keys($config), self::KEYS);
        if ($unknown !== []) {
            // @intentional-assertion: App\Exception\InvalidArgument (not SPL) — broken deadline config
            throw new InvalidArgumentException(sprintf('Deadline type "%s": unknown key(s) %s.', $slug, implode(', ', $unknown)));
        }
        foreach (['label', 'entity', 'title', 'bucket'] as $required) {
            if (!is_string($config[$required] ?? null) || $config[$required] === '') {
                // @intentional-assertion: App\Exception\InvalidArgument (not SPL) — broken deadline config
                throw new InvalidArgumentException(sprintf('Deadline type "%s": "%s" is required.', $slug, $required));
            }
        }
        if (!class_exists($config['entity'])) {
            // @intentional-assertion: App\Exception\InvalidArgument (not SPL) — broken deadline config
            throw new InvalidArgumentException(sprintf('Deadline type "%s": entity %s does not exist.', $slug, $config['entity']));
        }
        if (!array_key_exists('due', $config)) {
            // @intentional-assertion: App\Exception\InvalidArgument (not SPL) — broken deadline config
            throw new InvalidArgumentException(sprintf('Deadline type "%s": "due" is required (null = open without a date).', $slug));
        }
        return new self($slug, $config);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', (array) $value));
    }
}
