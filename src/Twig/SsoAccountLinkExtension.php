<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Service\Sso\SsoAccountLinker;
use Twig\Attribute\AsTwigFunction;

/**
 * User detail page, "SSO-Verknüpfung" card:
 * `sso_link_allowed(user)` — may the viewer bind / unbind this account;
 * `sso_linkable_providers(user)` — enabled providers that can sign it in.
 */
final readonly class SsoAccountLinkExtension
{
    public function __construct(
        private SsoAccountLinker $linker,
    ) {
    }

    #[AsTwigFunction('sso_link_allowed')]
    public function isAllowed(User $user): bool
    {
        return $this->linker->mayManage($user);
    }

    /**
     * @return list<IdentityProvider>
     */
    #[AsTwigFunction('sso_linkable_providers')]
    public function linkableProviders(User $user): array
    {
        return $this->linker->linkableProviders($user);
    }
}
