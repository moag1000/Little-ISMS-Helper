<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\Comment;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ControlRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiEvidenceFitKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionNote;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiEvidenceFitKindTest extends TestCase
{
    #[Test]
    public function onlyAControlWithEvidenceIsACandidate(): void
    {
        $kind = $this->kind(new Control());

        self::assertFalse($kind->supportsRecord($this->control(new Tenant(), [])));
        self::assertTrue($kind->supportsRecord($this->control(new Tenant(), [5])));
        self::assertFalse($kind->supportsRecord($this->control(new Tenant(), [5])->setApplicable(false)), 'excluded in the SoA');
    }

    #[Test]
    public function theAnswerNeedsAVerdictAndANote(): void
    {
        $control = $this->control(new Tenant(), [5, 6]);
        $kind = $this->kind($control);

        $draft = $kind->draftFromAssistant(new Tenant(), $control, ['fits' => 'Partly', 'note' => 'Richtlinie vorhanden, Freigabenachweis fehlt.'], 'MCP');

        self::assertNotNull($draft);
        self::assertSame('partly', $draft->payload['fits']);
        self::assertSame([5, 6], $draft->payload['documents']);
        self::assertStringContainsString('Freigabenachweis fehlt.', (string) $draft->editableText);
        self::assertNull($kind->draftFromAssistant(new Tenant(), $control, ['fits' => 'maybe', 'note' => 'x'], 'MCP'));
        self::assertNull($kind->draftFromAssistant(new Tenant(), $control, ['fits' => 'yes', 'note' => ''], 'MCP'));
    }

    #[Test]
    public function acceptingAddsACommentAndLeavesTheControlAlone(): void
    {
        $tenant = new Tenant();
        $control = $this->control($tenant, [5]);
        $user = new User();
        $comment = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($control);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (object $o) use (&$comment): bool {
            $comment = $o;

            return true;
        }));

        $result = $this->kind($control, $em)->apply($this->suggestion($tenant, [5]), $user, 'Bearbeitete Notiz');

        self::assertSame($control, $result);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('Control', $comment->getEntityType());
        self::assertSame('Bearbeitete Notiz', $comment->getBody());
        self::assertSame($user, $comment->getAuthor());
        self::assertSame($tenant, $comment->getTenant());
        self::assertSame('implemented', $control->getImplementationStatus(), 'status untouched');
    }

    #[Test]
    public function aForeignControlOrChangedEvidenceIsStale(): void
    {
        $control = $this->control(new Tenant(), [5]);
        try {
            $this->kind($control)->apply($this->suggestion(new Tenant(), [5]), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $own = $this->control($tenant, [5, 7]);
        $this->expectException(StaleSuggestionException::class);
        $this->kind($own)->apply($this->suggestion($tenant, [5]), new User(), null);
    }

    /** @param list<int> $documentIds */
    private function control(Tenant $tenant, array $documentIds): Control
    {
        $control = (new Control())->setTenant($tenant)->setControlId('A.5.1')->setName('Richtlinien')->setApplicable(true)
            ->setImplementationStatus('implemented');
        foreach ($documentIds as $id) {
            $document = new Document();
            (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);
            $control->addEvidenceDocument($document);
        }

        return $control;
    }

    /** @param list<int> $documents */
    private function suggestion(Tenant $tenant, array $documents): Suggestion
    {
        return (new Suggestion($tenant, AiEvidenceFitKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(Control::class, 9)
            ->setPayload(['fits' => 'partly', 'documents' => $documents])
            ->setEditableText('Entwurfsnotiz');
    }

    private function kind(Control $found, ?EntityManagerInterface $em = null): AiEvidenceFitKind
    {
        if ($em === null) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('find')->willReturn($found);
        }
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiEvidenceFitKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(ControlRepository::class),
            $em,
            new SuggestionNote($em),
            new SuggestionText($translator),
        );
    }
}
