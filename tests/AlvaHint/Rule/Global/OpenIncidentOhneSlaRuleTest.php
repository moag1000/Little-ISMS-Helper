<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\OpenIncidentOhneSlaRule;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IncidentRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Art. 33 early-warning hint must deep-link to EXACTLY the incidents it
 * counts: one → that incident's show page; several → the incident index
 * pre-filtered to focus=privacy_sla.
 */
#[AllowMockObjectsWithoutExpectations]
final class OpenIncidentOhneSlaRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoOpenPrivacyIncidents(): void
    {
        $rule = new OpenIncidentOhneSlaRule($this->makeRepo(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleIncidentDeepLinksToThatIncident(): void
    {
        $rule = new OpenIncidentOhneSlaRule($this->makeRepo(1, firstId: 9));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_incident_show', $hint->actionRoute);
        self::assertSame(['id' => 9], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalIncidentsLinkToFilteredIndex(): void
    {
        $rule = new OpenIncidentOhneSlaRule($this->makeRepo(3));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_incident_index', $hint->actionRoute);
        self::assertSame(['focus' => 'privacy_sla'], $hint->actionRouteParams);
        self::assertSame('3', $hint->bodyTranslationParams['%count%']);
        self::assertSame(1, $hint->priorityTier);
        self::assertSame('danger', $hint->variant);
        self::assertFalse($hint->dismissible);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    #[Test]
    public function requiresPrivacyModule(): void
    {
        $rule = new OpenIncidentOhneSlaRule($this->makeRepo(0));
        self::assertContains('privacy', $rule->requiredModules());
    }

    private function makeRepo(int $count, int $firstId = 1): IncidentRepository
    {
        $incidents = [];
        for ($i = 0; $i < $count; ++$i) {
            $incident = $this->createMock(Incident::class);
            $incident->method('getId')->willReturn($i === 0 ? $firstId : $firstId + $i);
            $incidents[] = $incident;
        }

        $repo = $this->createMock(IncidentRepository::class);
        $repo->method('findOpenPrivacyWithoutSla')->willReturn($incidents);

        return $repo;
    }
}
