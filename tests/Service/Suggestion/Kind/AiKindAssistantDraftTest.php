<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\IncidentRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiRiskFromIncidentKind;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's own assistant (MCP) drafts in place of the configured model:
 * it gets the kind's instruction and facts, and its answer goes through the
 * same mapping and checks — without calling the organisation's AI.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiKindAssistantDraftTest extends TestCase
{
    #[Test]
    public function theAssistantGetsTheModelsInstructionAndFacts(): void
    {
        $brief = $this->kind()->assistantBrief($this->incident());

        self::assertNotNull($brief);
        self::assertStringContainsString('"probability"', $brief['instructions']);
        self::assertStringContainsString('Ransomware auf dem Dateiserver', $brief['facts']);
    }

    #[Test]
    public function theAnswerBecomesADraftNamingTheAssistant(): void
    {
        $tenant = new Tenant();
        $draft = $this->kind()->draftFromAssistant($tenant, $this->incident(), [
            'title' => 'Verschlüsselung des Dateiservers',
            'description' => 'Ransomware verschlüsselt Projektdaten; Arbeitsausfall mehrerer Tage.',
            'probability' => 9,
            'impact' => 4,
        ], 'MCP: Laptop');

        self::assertNotNull($draft);
        self::assertSame('MCP: Laptop', $draft->model);
        self::assertSame(5, $draft->payload['probability'], 'values are clamped like a model answer');
        self::assertSame('Ransomware verschlüsselt Projektdaten; Arbeitsausfall mehrerer Tage.', $draft->editableText);
    }

    #[Test]
    public function anIncompleteAnswerOrAFinishedRecordGivesNoDraft(): void
    {
        $kind = $this->kind();

        self::assertNull($kind->draftFromAssistant(new Tenant(), $this->incident(), ['title' => 'Nur Titel'], 'MCP'));
        $short = (new Incident())->setTitle('Kurz')->setDescription('zu kurz');
        self::assertNull($kind->draftFromAssistant(new Tenant(), $short, ['title' => 'x', 'description' => 'y'], 'MCP'));
    }

    private function incident(): Incident
    {
        return (new Incident())
            ->setTitle('Ransomware auf dem Dateiserver')
            ->setDescription('Am Morgen waren alle Projektordner verschlüsselt, eine Lösegeldforderung lag bei.');
    }

    private function kind(): AiRiskFromIncidentKind
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $ai = $this->createMock(AiAssistant::class);
        $ai->expects(self::never())->method('proposeJson');

        return new AiRiskFromIncidentKind(
            $ai,
            $this->createStub(SuggestionRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new SuggestionText($translator),
        );
    }
}
