<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\BCExercise;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\BCExerciseRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiBcExerciseEvaluationKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiBcExerciseEvaluationKindTest extends TestCase
{
    #[Test]
    public function onlyAnExerciseThatTookPlaceWithEmptyResultsIsACandidate(): void
    {
        $kind = $this->kind(new BCExercise());

        self::assertTrue($kind->supportsRecord($this->exercise('completed', '-3 days')));
        self::assertFalse($kind->supportsRecord($this->exercise('planned', '-3 days')), 'not held');
        self::assertFalse($kind->supportsRecord($this->exercise('completed', '+3 days')), 'in the future');
        $done = $this->exercise('completed', '-3 days')->setResults('a')->setWhatWentWell('b')->setLessonsLearned('c');
        self::assertFalse($kind->supportsRecord($done), 'nothing left to fill');
    }

    #[Test]
    public function theAnswerMapsToTheThreeEmptyFieldsOnly(): void
    {
        $exercise = $this->exercise('completed', '-3 days')->setResults('Schon geschrieben.');

        $draft = $this->kind($exercise)->draftFromAssistant(new Tenant(), $exercise, [
            'results' => 'Neu',
            'went_well' => 'Alarmierung klappte.',
            'lessons' => 'Kontaktliste aktualisieren.',
        ], 'MCP');

        self::assertNotNull($draft);
        self::assertSame(['whatWentWell', 'lessonsLearned'], array_keys($draft->payload['fields']));
    }

    #[Test]
    public function acceptingFillsOnlyTheFieldsStillEmpty(): void
    {
        $tenant = new Tenant();
        $exercise = $this->exercise('completed', '-3 days')->setTenant($tenant)->setResults('Vom Menschen.');

        $result = $this->kind($exercise)->apply($this->suggestion($tenant), new User(), null);

        self::assertSame($exercise, $result);
        self::assertSame('Vom Menschen.', $exercise->getResults());
        self::assertSame('Alarmierung klappte.', $exercise->getWhatWentWell());
        self::assertSame('Kontaktliste aktualisieren.', $exercise->getLessonsLearned());
    }

    #[Test]
    public function aForeignExerciseIsStale(): void
    {
        $exercise = $this->exercise('completed', '-3 days')->setTenant(new Tenant());

        $this->expectException(StaleSuggestionException::class);
        $this->kind($exercise)->apply($this->suggestion(new Tenant()), new User(), null);
    }

    private function exercise(string $status, string $when): BCExercise
    {
        return (new BCExercise())
            ->setName('Ransomware-Planspiel')
            ->setScenario('Dateiserver verschlüsselt, Wiederanlauf aus dem Backup.')
            ->setStatus($status)
            ->setExerciseDate(new \DateTimeImmutable($when));
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiBcExerciseEvaluationKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(BCExercise::class, 2)
            ->setPayload([
                'fields' => [
                    'results' => 'Entwurf Ergebnis',
                    'whatWentWell' => 'Alarmierung klappte.',
                    'lessonsLearned' => 'Kontaktliste aktualisieren.',
                ],
                'labels' => [],
            ]);
    }

    private function kind(BCExercise $found): AiBcExerciseEvaluationKind
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($found);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiBcExerciseEvaluationKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(BCExerciseRepository::class),
            $em,
            new SuggestionText($translator),
        );
    }
}
