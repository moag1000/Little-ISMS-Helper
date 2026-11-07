<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\IsmsRegisterEntry;
use App\Entity\ISMSContext;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Enum\SuggestionOrigin;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use App\Security\Persona\PersonaResolver;
use App\Service\ModuleConfigurationService;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Register\RegisterProposalProvider;
use App\Service\Suggestion\Kind\Register\RegisterIssueExternalProposalKind;
use App\Service\Suggestion\Kind\Register\RegisterLegalRequirementProposalKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegisterProposalKindTest extends TestCase
{
    #[Test]
    public function keyAndDeciderFollowTheRegisterType(): void
    {
        $kind = $this->kind([], $this->createStub(EntityManagerInterface::class), RegisterLegalRequirementProposalKind::class);

        self::assertSame('register.legal_requirement', $kind->key());
        self::assertSame(SuggestionOrigin::Import, $kind->origin());
        self::assertSame(['ROLE_COMPLIANCE_MANAGER', 'ROLE_MANAGER'], $kind->decisionRoles());
    }

    #[Test]
    public function generateOffersEachOpenProposalOnceByOrigin(): void
    {
        $kind = $this->kind([], $this->createStub(EntityManagerInterface::class));

        $drafts = iterator_to_array($kind->generate(new Tenant()), false);

        self::assertCount(2, $drafts);
        self::assertNull($drafts[0]->subject);
        self::assertStringContainsString('Fachkräftemangel', $drafts[0]->title);
        self::assertSame('issue_external', $drafts[0]->payload['type']);
        self::assertSame(['app_register_list', ['type' => 'issue_external']], $drafts[0]->payload['_link']);
        self::assertSame($drafts[0]->payload['origin'], $drafts[0]->dedupKey);
    }

    #[Test]
    public function applyAdoptsTheProposalAsRegisterEntry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(IsmsRegisterEntry::class));
        $kind = $this->kind([], $em);
        $tenant = new Tenant();
        $draft = iterator_to_array($kind->generate($tenant), false)[0];

        $entry = $kind->apply($this->suggestion($tenant, $draft->payload), new User(), null);

        self::assertInstanceOf(IsmsRegisterEntry::class, $entry);
        self::assertSame(RegisterType::ISSUE_EXTERNAL, $entry->getType());
        self::assertSame('Fachkräftemangel', $entry->getTitle());
    }

    #[Test]
    public function applyIsStaleWhenTheProposalWasAdoptedElsewhere(): void
    {
        $tenant = new Tenant();
        $payload = iterator_to_array($this->kind([], $this->createStub(EntityManagerInterface::class))->generate($tenant), false)[0]->payload;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $this->expectException(StaleSuggestionException::class);
        $this->kind([(string) $payload['origin']], $em)->apply($this->suggestion($tenant, $payload), new User(), null);
    }

    /**
     * @param list<string> $takenOrigins
     * @param class-string<RegisterIssueExternalProposalKind|RegisterLegalRequirementProposalKind> $class
     */
    private function kind(array $takenOrigins, EntityManagerInterface $em, string $class = RegisterIssueExternalProposalKind::class): RegisterIssueExternalProposalKind|RegisterLegalRequirementProposalKind
    {
        $context = (new ISMSContext())->setExternalIssues("- Fachkräftemangel\n- Neue NIS-2-Pflichten");
        $contexts = $this->createStub(ISMSContextRepository::class);
        $contexts->method('findOneBy')->willReturn($context);
        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('origins')->willReturn($takenOrigins);
        $users = $this->createStub(UserRepository::class);
        $users->method('findByIdsInTenant')->willReturn([]);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id . ' ' . implode(' ', array_map('strval', $params)),
        );
        $frameworks = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworks->method('findBy')->willReturn([]);
        $parties = $this->createStub(InterestedPartyRepository::class);
        $parties->method('findBy')->willReturn([]);

        $provider = new RegisterProposalProvider(
            $entries,
            $contexts,
            $users,
            (new \ReflectionClass(PersonaResolver::class))->newInstanceWithoutConstructor(),
            $frameworks,
            $parties,
            $this->createStub(SupplierRepository::class),
            $this->createStub(ManagementReviewRepository::class),
            $this->createStub(ModuleConfigurationService::class),
            $translator,
        );

        return new $class(
            $provider,
            new RegisterProposalAdopter($em, $users, $entries),
            new SuggestionText($translator),
            $translator,
        );
    }

    /** @param array<string, mixed> $payload */
    private function suggestion(Tenant $tenant, array $payload): Suggestion
    {
        return (new Suggestion($tenant, 'register.issue_external', SuggestionOrigin::Import, 'T', 'R', 'k'))->setPayload($payload);
    }
}
