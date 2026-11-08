<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\InterestedParty;
use App\Entity\IsmsRegisterEntry;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Enum\SuggestionOrigin;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\SuggestionRepository;
use App\Repository\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Suggestion\Kind\AiLegalRequirementFromPartyKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiLegalRequirementFromPartyKindTest extends TestCase
{
    #[Test]
    public function aPartyWithFreeTextRequirementsAndNoRegisterEntryIsACandidate(): void
    {
        $tenant = new Tenant();
        $party = $this->party($tenant);

        self::assertTrue($this->kind($party)->supportsRecord($party));
        self::assertFalse($this->kind($party, linked: [new IsmsRegisterEntry(RegisterType::LEGAL_REQUIREMENT)])->supportsRecord($party));
        self::assertFalse($this->kind($party)->supportsRecord($this->party($tenant)->setLegalRequirements([['title' => 'x']])), 'structured data feeds the rule proposals');
    }

    #[Test]
    public function theCategoryIsClampedToTheRegistersChoices(): void
    {
        $party = $this->party(new Tenant());

        $draft = $this->kind($party)->draftFromAssistant(new Tenant(), $party, [
            'title' => 'Informationssicherheit nach Kundenvertrag',
            'description' => 'Kunde verlangt ISO 27001 und jährliche Audits.',
            'category' => 'whatever',
            'reference' => 'Rahmenvertrag §7',
        ], 'MCP');

        self::assertNotNull($draft);
        self::assertSame('contract', $draft->payload['category']);
        self::assertSame('Rahmenvertrag §7', $draft->payload['reference']);
        self::assertNull($this->kind($party)->draftFromAssistant(new Tenant(), $party, ['title' => 'Nur Titel'], 'MCP'));
    }

    #[Test]
    public function acceptingAdoptsOneLegalRequirementLinkedToTheParty(): void
    {
        $tenant = new Tenant();
        $party = $this->party($tenant);
        $user = new User();
        $written = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($party);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$written): void {
            $written[] = $o;
        });

        $result = $this->kind($party, em: $em)->apply($this->suggestion($tenant), $user, 'Bearbeiteter Text');

        self::assertInstanceOf(IsmsRegisterEntry::class, $result);
        self::assertSame($written, [$result]);
        self::assertSame(RegisterType::LEGAL_REQUIREMENT, $result->getType());
        self::assertSame('Kundenvertrag', $result->getTitle());
        self::assertSame('Bearbeiteter Text', $result->getDescription());
        self::assertSame('contract', $result->getCategory());
        self::assertStringStartsWith('ai.party.', (string) $result->getOrigin());
        self::assertSame($user, $result->getOwnerUser());
    }

    #[Test]
    public function aForeignPartyOrAnEntryWrittenMeanwhileIsStale(): void
    {
        $party = $this->party(new Tenant());
        try {
            $this->kind($party)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $own = $this->party($tenant);
        $this->expectException(StaleSuggestionException::class);
        $this->kind($own, linked: [new IsmsRegisterEntry(RegisterType::LEGAL_REQUIREMENT)])->apply($this->suggestion($tenant), new User(), null);
    }

    private function party(Tenant $tenant): InterestedParty
    {
        return (new InterestedParty())
            ->setTenant($tenant)
            ->setName('Großkunde')
            ->setRequirements('Der Kunde verlangt ein zertifiziertes ISMS und jährliche Audits vor Ort.');
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiLegalRequirementFromPartyKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(InterestedParty::class, 6)
            ->setPayload(['title' => 'Kundenvertrag', 'category' => 'contract', 'reference' => ''])
            ->setEditableText('Entwurfstext');
    }

    /** @param list<IsmsRegisterEntry> $linked */
    private function kind(InterestedParty $found, array $linked = [], ?EntityManagerInterface $em = null): AiLegalRequirementFromPartyKind
    {
        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('findLinkingTo')->willReturn($linked);
        $entries->method('origins')->willReturn([]);
        if ($em === null) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('find')->willReturn($found);
        }
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiLegalRequirementFromPartyKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(InterestedPartyRepository::class),
            $entries,
            new RegisterProposalAdopter($em, $this->createStub(UserRepository::class), $entries),
            $em,
            new SuggestionText($translator),
        );
    }
}
