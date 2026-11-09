<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

/**
 * What accepting a suggestion of one kind needs (S30): the same right as the
 * write it stands for in the UI. Normally the controller action of that write
 * — its `#[IsGranted]` attributes (class and method) are read live and checked
 * against the suggestion's record, so the queue cannot drift from the form.
 * A role only where no single UI action fits the write (with the reason).
 */
final readonly class TargetAccess
{
    /**
     * @param array{0: class-string, 1: string}|null $action
     */
    private function __construct(
        public ?array $action,
        public ?string $role,
        public string $reason,
    ) {
    }

    /** @param class-string $controller */
    public static function action(string $controller, string $method): self
    {
        return new self([$controller, $method], null, '');
    }

    public static function role(string $role, string $reason): self
    {
        return new self(null, $role, $reason);
    }
}
