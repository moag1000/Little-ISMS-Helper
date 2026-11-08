<?php

declare(strict_types=1);

namespace App\Tests\Service\ManagementDecision;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ManagementDecision\BoardUnassignedSource;
use App\Service\Responsibility\ResponsibilityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** No account holds ROLE_TOP_MGMT → admins get a duty, ISB and CISO are informed. */
final class BoardUnassignedSourceTest extends TestCase
{
    #[Test]
    public function oneDutyWhileNobodyHoldsTheTopManagementRole(): void
    {
        $tenant = new Tenant();

        self::assertCount(1, [...$this->source([])->findOpen($tenant)]);
        self::assertCount(0, [...$this->source([new User()])->findOpen($tenant)]);
    }

    #[Test]
    public function adminsActIsbAndCisoAreInformed(): void
    {
        $source = $this->source([]);
        $item = [...$source->findOpen(new Tenant())][0];
        $responsibility = $source->responsibleOf($item);

        self::assertSame(['ROLE_ADMIN'], $responsibility->roles);
        self::assertSame(['ROLE_ISB', 'ROLE_CISO'], $responsibility->informedRoles);
        self::assertSame('app_management_decision_index', $source->linkOf($item)->route);
        self::assertTrue($source->supports($item));
    }

    /** @param list<User> $holders */
    private function source(array $holders): BoardUnassignedSource
    {
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('usersHolding')->willReturn($holders);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new BoardUnassignedSource($resolver, $translator);
    }
}
