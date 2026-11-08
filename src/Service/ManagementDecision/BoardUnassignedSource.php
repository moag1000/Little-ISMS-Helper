<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Keine Leitung zugewiesen": nobody in the organisation holds ROLE_TOP_MGMT,
 * so the decisions reserved for top management (ISO 27001 5.1, 9.3; NIS-2
 * Art. 20; risk acceptance above the manager level) reach no one who may take
 * them. Admins assign the role (user management, button on the decision
 * register the reminder links to); ISB and CISO are told, because they
 * prepare those decisions. Ends on its own once the role is held.
 */
final class BoardUnassignedSource implements DeadlineSource
{
    public function __construct(
        // Lazy: the resolver collects every deadline source, this one included.
        #[Autowire(lazy: true)]
        private readonly ResponsibilityResolver $responsibility,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function slug(): string
    {
        return 'board_unassigned';
    }

    public function label(): string
    {
        return 'Keine Leitung zugewiesen';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<BoardUnassigned> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->responsibility->usersHolding($tenant, 'ROLE_TOP_MGMT') === [] ? [new BoardUnassigned($tenant)] : [];
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        return null;
    }

    public function titleOf(object $item): string
    {
        return $this->translator->trans('my_day.board_unassigned_title', [], 'my_day');
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof BoardUnassigned);

        return (int) $item->tenant->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof BoardUnassigned;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_ADMIN')->alsoInform('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        // The decision register: ISB/CISO can open it, admins find the "assign role" button there.
        return new RecordLink('app_management_decision_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'management_decisions_pending', leadDays: null, normReference: 'ISO 27001 5.1 / NIS-2 Art. 20');
    }
}
