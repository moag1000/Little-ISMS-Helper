<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Update\PostUpdateRunner;
use App\Service\Update\PostUpdateState;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `post_update()` for the Quick-Fix page: what the automatic post-update run did last and
 * whether it is switched on ({@see PostUpdateRunner}).
 */
final class PostUpdateExtension extends AbstractExtension
{
    public function __construct(
        private readonly PostUpdateState $state,
        private readonly PostUpdateRunner $runner,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('post_update', $this->summary(...))];
    }

    /** @return array{enabled: bool, state: array<string, mixed>|null} */
    public function summary(): array
    {
        return ['enabled' => $this->runner->isEnabled(), 'state' => $this->state->read()];
    }
}
