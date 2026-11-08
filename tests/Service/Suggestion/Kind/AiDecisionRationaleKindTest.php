<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\ManagementDecision;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionType;
use App\Enum\SuggestionOrigin;
use App\Repository\ManagementDecisionRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiDecisionRationaleKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiDecisionRationaleKindTest extends TestCase
{
    #[Test]
    public function onlyAPendingProposalWithoutTextIsACandidate(): void
    {
        $kind = $this->kind(new ManagementDecision());

        self::assertTrue($kind->supportsRecord($this->decision(new Tenant())));
        self::assertFalse($kind->supportsRecord($this->decision(new Tenant())->setProposal('Schon begründet.')));
        self::assertFalse($kind->supportsRecord($this->decision(new Tenant())->setStatus('approved')));
    }

    #[Test]
    public function theProposalTextIsTheEditableText(): void
    {
        $decision = $this->decision(new Tenant());

        $draft = $this->kind($decision)->draftFromAssistant(new Tenant(), $decision, ['proposal' => 'Die Leitung soll die Leitlinie billigen.'], 'MCP');

        self::assertNotNull($draft);
        self::assertSame('Die Leitung soll die Leitlinie billigen.', $draft->editableText);
        self::assertNull($this->kind($decision)->draftFromAssistant(new Tenant(), $decision, [], 'MCP'));
    }

    #[Test]
    public function acceptingFillsOnlyTheProposalTextAndKeepsTheStatus(): void
    {
        $tenant = new Tenant();
        $decision = $this->decision($tenant);

        $result = $this->kind($decision)->apply($this->suggestion($tenant), new User(), 'Bearbeitet');

        self::assertSame($decision, $result);
        self::assertSame('Bearbeitet', $decision->getProposal());
        self::assertSame('pending', $decision->getStatus());
        self::assertNull($decision->getDecidedBy());
    }

    #[Test]
    public function aForeignDecisionOrOneWrittenMeanwhileIsStale(): void
    {
        $decision = $this->decision(new Tenant());
        try {
            $this->kind($decision)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $written = $this->decision($tenant)->setProposal('Inzwischen geschrieben.');
        $this->expectException(StaleSuggestionException::class);
        $this->kind($written)->apply($this->suggestion($tenant), new User(), null);
    }

    private function decision(Tenant $tenant): ManagementDecision
    {
        return (new ManagementDecision())
            ->setTenant($tenant)
            ->setType(ManagementDecisionType::Policy)
            ->setTitle('Informationssicherheitsleitlinie 2027');
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiDecisionRationaleKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(ManagementDecision::class, 31)
            ->setEditableText('Entwurf');
    }

    private function kind(ManagementDecision $found): AiDecisionRationaleKind
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($found);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiDecisionRationaleKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(ManagementDecisionRepository::class),
            $em,
            new SuggestionText($translator),
        );
    }
}
