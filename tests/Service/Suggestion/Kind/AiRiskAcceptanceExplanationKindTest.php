<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\Comment;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiRiskAcceptanceExplanationKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionNote;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiRiskAcceptanceExplanationKindTest extends TestCase
{
    #[Test]
    public function onlyARequestedAndUndecidedAcceptanceIsACandidate(): void
    {
        $kind = $this->kind(new Risk());

        self::assertTrue($kind->supportsRecord($this->pending(new Tenant())));
        self::assertFalse($kind->supportsRecord($this->pending(new Tenant())->setFormallyAccepted(true)), 'already accepted');
        self::assertFalse($kind->supportsRecord($this->pending(new Tenant())->setTreatmentStrategy(TreatmentStrategy::Mitigate)));
        self::assertFalse($kind->supportsRecord($this->pending(new Tenant())->setAcceptanceJustification(null)), 'nothing requested yet');
    }

    #[Test]
    public function theExplanationBecomesTheEditableText(): void
    {
        $risk = $this->pending(new Tenant());

        $draft = $this->kind($risk)->draftFromAssistant(new Tenant(), $risk, ['explanation' => 'Wenn Sie zustimmen, tragen Sie das Restrisiko.'], 'MCP');

        self::assertNotNull($draft);
        self::assertSame('Wenn Sie zustimmen, tragen Sie das Restrisiko.', $draft->editableText);
        self::assertNull($this->kind($risk)->draftFromAssistant(new Tenant(), $risk, ['explanation' => ''], 'MCP'));
    }

    #[Test]
    public function acceptingStoresACommentAndNeverAcceptsTheRisk(): void
    {
        $tenant = new Tenant();
        $risk = $this->pending($tenant);
        $user = new User();
        $comment = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($risk);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (object $o) use (&$comment): bool {
            $comment = $o;

            return true;
        }));

        $this->kind($risk, $em)->apply($this->suggestion($tenant), $user, 'Erklärung');

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('Risk', $comment->getEntityType());
        self::assertStringEndsWith("\nErklärung", $comment->getBody());
        self::assertSame($user, $comment->getAuthor());
        self::assertFalse($risk->isFormallyAccepted());
        self::assertSame(TreatmentStrategy::Accept, $risk->getTreatmentStrategy());
    }

    #[Test]
    public function aForeignRiskOrADecidedAcceptanceIsStale(): void
    {
        $risk = $this->pending(new Tenant());
        try {
            $this->kind($risk)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $decided = $this->pending($tenant)->setFormallyAccepted(true);
        $this->expectException(StaleSuggestionException::class);
        $this->kind($decided)->apply($this->suggestion($tenant), new User(), null);
    }

    private function pending(Tenant $tenant): Risk
    {
        return (new Risk())
            ->setTenant($tenant)
            ->setTitle('Ausfall des Rechenzentrums')
            ->setTreatmentStrategy(TreatmentStrategy::Accept)
            ->setAcceptanceJustification('Kosten der Redundanz stehen in keinem Verhältnis.');
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiRiskAcceptanceExplanationKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(Risk::class, 21)
            ->setEditableText('Entwurf');
    }

    private function kind(Risk $found, ?EntityManagerInterface $em = null): AiRiskAcceptanceExplanationKind
    {
        if ($em === null) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('find')->willReturn($found);
        }
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiRiskAcceptanceExplanationKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(RiskRepository::class),
            $em,
            new SuggestionNote($em),
            new SuggestionText($translator),
        );
    }
}
