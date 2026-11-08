<?php

declare(strict_types=1);

namespace App\Tests\Service\Register;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Enum\RegisterType;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use App\Security\Persona\PersonaResolver;
use App\Service\ModuleConfigurationService;
use App\Service\Register\RegisterProposalProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ISO 27001 5.3: a role entry taken over from a persona role whose holder no
 * longer has it is reported, so "check holder" can propose to archive it.
 */
final class RegisterVacatedRoleTest extends TestCase
{
    #[Test]
    public function roleEntriesOfFormerHoldersAreVacated(): void
    {
        $fromPersona = (new IsmsRegisterEntry(RegisterType::ROLE))->setTitle('ISB')->setOrigin('persona.isb.user.7');
        $handWritten = (new IsmsRegisterEntry(RegisterType::ROLE))->setTitle('Notfallkoordination');
        $fromTenantDpo = (new IsmsRegisterEntry(RegisterType::ROLE))->setTitle('DSB')->setOrigin('tenant.dpo');

        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('findByType')->willReturn([$fromPersona, $handWritten, $fromTenantDpo]);
        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn([]);

        $provider = new RegisterProposalProvider(
            $entries,
            $this->createStub(ISMSContextRepository::class),
            $users,
            (new \ReflectionClass(PersonaResolver::class))->newInstanceWithoutConstructor(),
            $this->createStub(ComplianceFrameworkRepository::class),
            $this->createStub(InterestedPartyRepository::class),
            $this->createStub(SupplierRepository::class),
            $this->createStub(ManagementReviewRepository::class),
            $this->createStub(ModuleConfigurationService::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame([$fromPersona], $provider->vacatedRoles(new Tenant()));
    }

    #[Test]
    public function improvementRegisterAnswersClauseTenOne(): void
    {
        self::assertSame('ISO 27001 10.1 · 9.3.2 g', RegisterType::IMPROVEMENT->normReference());
        self::assertContains('management_review', RegisterType::IMPROVEMENT->categories());
        self::assertSame(3, RegisterType::IMPROVEMENT->reviewMonths());
    }
}
