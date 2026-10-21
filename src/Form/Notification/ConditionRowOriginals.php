<?php

declare(strict_types=1);

namespace App\Form\Notification;

/**
 * The array values the condition rows of ONE notification-rule form were
 * rendered from, keyed by a stable row key instead of the collection index.
 *
 * A row's original value is registered when the form is built from the rule
 * ({@see ConditionRowTransformer::transform()}); the key travels with the row
 * as a hidden `rowKey` field and brings the original back on submit, whatever
 * index the row is submitted under — rows removed in the middle, re-indexed
 * or added by the condition-builder never inherit another row's original.
 * Only keys registered here are honoured: a posted key cannot inject values.
 */
final class ConditionRowOriginals
{
    /** @var array<string, list<mixed>> */
    private array $byKey = [];

    /**
     * @param list<mixed> $original
     *
     * @return string the row key (identical originals share one key)
     */
    public function register(array $original): string
    {
        $key = substr(hash('sha256', serialize($original)), 0, 20);
        $this->byKey[$key] = $original;

        return $key;
    }

    /**
     * @return list<mixed>|null
     */
    public function get(mixed $key): ?array
    {
        return is_string($key) && $key !== '' ? ($this->byKey[$key] ?? null) : null;
    }
}
