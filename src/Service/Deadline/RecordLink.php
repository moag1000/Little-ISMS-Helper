<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * A route to a record's page, resolved to a URL where it is shown
 * (My Day: relative path, mail: absolute URL).
 */
final readonly class RecordLink
{
    /**
     * @param array<string, scalar> $parameters
     */
    public function __construct(
        public string $route,
        public array $parameters = [],
    ) {
    }
}
