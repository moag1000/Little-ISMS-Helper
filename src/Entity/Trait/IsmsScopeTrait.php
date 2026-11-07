<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * ISO 27001 clause 4.3: whether the record lies within the ISMS scope.
 * Existing rows stay in scope (default true).
 */
trait IsmsScopeTrait
{
    #[ORM\Column(options: ['default' => true])]
    private bool $inIsmsScope = true;

    public function isInIsmsScope(): bool
    {
        return $this->inIsmsScope;
    }

    public function setInIsmsScope(bool $inIsmsScope): static
    {
        $this->inIsmsScope = $inIsmsScope;

        return $this;
    }
}
