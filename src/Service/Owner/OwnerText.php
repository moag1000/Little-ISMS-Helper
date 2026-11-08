<?php

declare(strict_types=1);

namespace App\Service\Owner;

/**
 * An owner given as text (import cell) that names nobody known yet: on commit
 * it becomes a new Person of the organisation via {@see OwnerTextResolver}.
 * Carries the two setters of the record's owner slot; the preview shows the name.
 */
final readonly class OwnerText implements \Stringable, \JsonSerializable
{
    public function __construct(
        public string $text,
        public string $userSetter,
        public string $personSetter,
    ) {
    }

    public function __toString(): string
    {
        return $this->text;
    }

    public function jsonSerialize(): string
    {
        return $this->text;
    }
}
