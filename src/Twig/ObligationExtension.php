<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Deadline\ObligationInbox;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `my_obligations(limit)` — the logged-in user's open duties from every
 * deadline source ({@see ObligationInbox}), soonest first, for the persona
 * dashboards. Same list as My Day, so a dashboard never shows a duty My Day
 * does not have (and vice versa).
 */
final class ObligationExtension
{
    /** @var list<array{title: string, due: ?\DateTimeImmutable, overdue: bool, urgent: bool, url: ?string, norm: ?string, decision: bool}>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly ObligationInbox $inbox,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{items: list<array{title: string, due: ?\DateTimeImmutable, overdue: bool, urgent: bool, url: ?string, norm: ?string, decision: bool}>, total: int, overdue: int}
     */
    #[AsTwigFunction('my_obligations')]
    public function myObligations(int $limit = 8): array
    {
        $all = $this->memo ??= $this->load();

        return [
            'items' => array_slice($all, 0, max(0, $limit)),
            'total' => count($all),
            'overdue' => count(array_filter($all, static fn (array $o): bool => $o['overdue'])),
        ];
    }

    /** @return list<array{title: string, due: ?\DateTimeImmutable, overdue: bool, urgent: bool, url: ?string, norm: ?string, decision: bool}> */
    private function load(): array
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$user instanceof User || !$tenant instanceof Tenant) {
            return [];
        }

        $items = [];
        foreach ($this->inbox->forUser($user, $tenant) as $obligation) {
            $link = $obligation->link();
            $items[] = [
                'title' => $obligation->title,
                'due' => $obligation->dueDate,
                'overdue' => $obligation->overdue,
                'urgent' => $obligation->urgent,
                'url' => $link !== null ? $this->url($link->route, $link->parameters) : null,
                'norm' => $obligation->profile()->normReference,
                'decision' => $obligation->profile()->decision,
            ];
        }

        return $items;
    }

    /** @param array<string, scalar> $parameters */
    private function url(string $route, array $parameters): ?string
    {
        try {
            return $this->urls->generate($route, $parameters);
        } catch (\Throwable) {
            return null; // a source pointing at a missing route must not break the dashboard
        }
    }
}
