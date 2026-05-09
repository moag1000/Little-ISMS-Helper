<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\LocationRepository;
use App\Service\PolicyWizard\Step\OrganisationScopeStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2 user-feedback (May 2026) — verifies the
 * OrganisationScopeStep::defaults() pre-fill rules:
 *   1. legal_name pulled from Tenant::getLegalName() (or getName())
 *   2. primary_address derived from the tenant's first office /
 *      building Location with a non-empty address
 *   3. user input always wins over the tenant pre-fill
 *
 * The location-multi-select payload (`available_locations`) is
 * exercised end-to-end here too — it shares the same repository
 * call as the address derivation so a single test pass covers both.
 */
#[AllowMockObjectsWithoutExpectations]
final class OrganisationScopeStepPrefillTest extends TestCase
{
    private function makeUser(int $id = 42, array $roles = ['ROLE_USER']): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    private function makeTenant(int $id, ?string $legalName, ?string $name): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getLegalName')->willReturn($legalName);
        $tenant->method('getName')->willReturn($name);
        return $tenant;
    }

    private function makeLocation(int $id, string $name, string $type, ?string $address): Location
    {
        $loc = $this->createStub(Location::class);
        $loc->method('getId')->willReturn($id);
        $loc->method('getName')->willReturn($name);
        $loc->method('getLocationType')->willReturn($type);
        $loc->method('getAddress')->willReturn($address);
        return $loc;
    }

    private function makeRun(?Tenant $tenant, ?User $user, array $persisted = []): WizardRun
    {
        $run = new WizardRun();
        if ($tenant instanceof Tenant) {
            $run->setTenant($tenant);
        }
        if ($user instanceof User) {
            $run->setStartedByUser($user);
        }
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStep(WizardStepKeys::STEP_ORG_SCOPE);
        if ($persisted !== []) {
            $run->setInputs([WizardStepKeys::STEP_ORG_SCOPE => $persisted]);
        }
        return $run;
    }

    #[Test]
    public function legalNamePrefilledFromTenantWhenSlotEmpty(): void
    {
        $tenant = $this->makeTenant(1, 'ACME GmbH & Co. KG', 'ACME');
        $user = $this->makeUser();

        $repo = $this->createMock(LocationRepository::class);
        $repo->method('findVisibleForUserAndTenant')->willReturn([]);

        $step = new OrganisationScopeStep(null, $repo);
        $defaults = $step->defaults($this->makeRun($tenant, $user));

        self::assertSame('ACME GmbH & Co. KG', $defaults['legal_name']);
        self::assertTrue($defaults['prefilled_legal_name']);
        self::assertFalse($defaults['prefilled_primary_address']);
        self::assertSame([], $defaults['available_locations']);
    }

    #[Test]
    public function legalNameFallsBackToTenantNameWhenLegalNameNull(): void
    {
        $tenant = $this->makeTenant(2, null, 'Plain-Name AG');
        $user = $this->makeUser();

        $repo = $this->createMock(LocationRepository::class);
        $repo->method('findVisibleForUserAndTenant')->willReturn([]);

        $step = new OrganisationScopeStep(null, $repo);
        $defaults = $step->defaults($this->makeRun($tenant, $user));

        self::assertSame('Plain-Name AG', $defaults['legal_name']);
        self::assertTrue($defaults['prefilled_legal_name']);
    }

    #[Test]
    public function headquartersAddressPrefilledFromPreferredLocationType(): void
    {
        $tenant = $this->makeTenant(3, 'ACME', 'ACME');
        $user = $this->makeUser();

        // Mix of types — building should win over datacenter, both
        // before the room with no address.
        $room = $this->makeLocation(11, 'Server-Room A', 'room', null);
        $datacenter = $this->makeLocation(12, 'DC1', 'datacenter', 'Datacenter Lane 1, Munich');
        $building = $this->makeLocation(13, 'HQ Building', 'building', "Goethestr. 1\n80336 Munich");

        $repo = $this->createMock(LocationRepository::class);
        $repo->method('findVisibleForUserAndTenant')->willReturn([$room, $datacenter, $building]);

        $step = new OrganisationScopeStep(null, $repo);
        $defaults = $step->defaults($this->makeRun($tenant, $user));

        // Whitespace collapsed by locationLabel/derivePrimaryAddress.
        self::assertSame('Goethestr. 1 80336 Munich', $defaults['primary_address']);
        self::assertTrue($defaults['prefilled_primary_address']);

        // available_locations payload includes ALL three (id+label) and
        // skips none — only the address derivation prefers types.
        self::assertCount(3, $defaults['available_locations']);
        self::assertSame(13, $defaults['available_locations'][2]['id']);
        self::assertStringContainsString('HQ Building', $defaults['available_locations'][2]['label']);
        self::assertStringContainsString('Goethestr. 1', $defaults['available_locations'][2]['label']);
    }

    #[Test]
    public function userOverridesAlwaysWinOverTenantPrefill(): void
    {
        $tenant = $this->makeTenant(4, 'ACME GmbH', 'ACME');
        $user = $this->makeUser();

        $building = $this->makeLocation(14, 'HQ', 'building', 'Tenant-derived address');

        $repo = $this->createMock(LocationRepository::class);
        $repo->method('findVisibleForUserAndTenant')->willReturn([$building]);

        $persisted = [
            'legal_name' => 'User-edited Name AG',
            'primary_address' => 'User-typed address line',
            'site_ids' => [14],
        ];

        $step = new OrganisationScopeStep(null, $repo);
        $defaults = $step->defaults($this->makeRun($tenant, $user, $persisted));

        // Persisted slot wins — no overwrite, no prefilled flags.
        self::assertSame('User-edited Name AG', $defaults['legal_name']);
        self::assertSame('User-typed address line', $defaults['primary_address']);
        self::assertFalse($defaults['prefilled_legal_name']);
        self::assertFalse($defaults['prefilled_primary_address']);
        // site_ids preserved untouched.
        self::assertSame([14], $defaults['site_ids']);
        // available_locations still computed for the picker.
        self::assertCount(1, $defaults['available_locations']);
    }

    #[Test]
    public function defaultsGracefullyHandleMissingRepositoryAndContext(): void
    {
        $step = new OrganisationScopeStep();

        // No tenant, no user, no repo — defaults() must not blow up;
        // every flag returns false and the locations list is empty.
        $run = new WizardRun();
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStep(WizardStepKeys::STEP_ORG_SCOPE);

        $defaults = $step->defaults($run);

        self::assertFalse($defaults['prefilled_legal_name']);
        self::assertFalse($defaults['prefilled_primary_address']);
        self::assertSame([], $defaults['available_locations']);
    }
}
