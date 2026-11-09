<?php

declare(strict_types=1);

namespace App\Tests\Service\ManagementReview;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Enum\RegisterType;
use App\Repository\ActionItemRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementDecisionRepository;
use App\Service\ManagementReview\ReviewInputNarratives;
use App\Service\Register\RegisterChanges;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReviewInputNarrativesTest extends TestCase
{
    #[Test]
    public function contextChangesListsRegisterEntriesChangedSinceThePreviousReview(): void
    {
        $since = new DateTimeImmutable('2026-01-01');

        $legal = (new IsmsRegisterEntry(RegisterType::LEGAL_REQUIREMENT))->setTitle('NIS2UmsuCG');
        $archived = (new IsmsRegisterEntry(RegisterType::ISSUE_EXTERNAL))->setTitle('Lieferengpass');
        $archived->archive(new DateTimeImmutable());

        $entries = $this->createMock(IsmsRegisterEntryRepository::class);
        $entries->expects(self::once())->method('changedSince')
            ->with(
                self::isInstanceOf(Tenant::class),
                $since,
                RegisterType::ISSUE_EXTERNAL,
                RegisterType::ISSUE_INTERNAL,
                RegisterType::LEGAL_REQUIREMENT,
                RegisterType::SCOPE_INTERFACE,
            )
            ->willReturn([$legal, $archived]);

        $text = $this->narratives($entries)->contextChanges(new Tenant(), $since, 'de');

        self::assertStringContainsString('b.no_context', $text);
        self::assertStringContainsString('b.registers_since', $text);
        self::assertStringContainsString('- register.type.legal_requirement.title: NIS2UmsuCG (b.entry_new)', $text);
        self::assertStringContainsString('- register.type.issue_external.title: Lieferengpass (b.entry_archived)', $text);
    }

    #[Test]
    public function contextChangesWithoutRegisterChangesStaysAsItWas(): void
    {
        $entries = $this->createStub(IsmsRegisterEntryRepository::class);
        $entries->method('changedSince')->willReturn([]);

        $text = $this->narratives($entries)->contextChanges(new Tenant(), new DateTimeImmutable('2026-01-01'), 'de');

        self::assertSame('b.no_context', $text);
    }

    private function narratives(IsmsRegisterEntryRepository $entries): ReviewInputNarratives
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => str_replace('management_review.input.', '', $id),
        );
        $context = $this->createStub(ISMSContextRepository::class);
        $context->method('getContextForTenant')->willReturn(null);

        return new ReviewInputNarratives(
            $translator,
            $this->createStub(ActionItemRepository::class),
            $this->createStub(ManagementDecisionRepository::class),
            $context,
            $this->createStub(InterestedPartyRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ISMSObjectiveRepository::class),
            new RegisterChanges($entries),
            new \App\Twig\AppDateFormatExtension($this->createStub(\App\Repository\SystemSettingsRepository::class)),
        );
    }
}
