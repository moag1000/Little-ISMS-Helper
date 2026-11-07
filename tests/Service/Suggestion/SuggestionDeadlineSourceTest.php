<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Enum\SuggestionOrigin;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\Suggestion\SuggestionDeadlineSource;
use App\Service\Suggestion\SuggestionResponsibility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Open suggestions reach My Day and the digest through the deadline-source contract (Paket 2 + 5). */
final class SuggestionDeadlineSourceTest extends TestCase
{
    #[Test]
    public function aSuggestionIsADecisionDueAfterTwoWeeks(): void
    {
        $s = new Suggestion(new Tenant(), 'risk.residual', SuggestionOrigin::Rule, 'Restrisiko', 'Weil', 'k');
        $source = $this->source();

        self::assertTrue($source->supports($s));
        self::assertTrue($source->profile()->decision);
        self::assertSame('suggestions', $source->profile()->bucket);
        self::assertSame(14, (int) $s->getCreatedAt()->diff($source->dueDateOf($s))->days);
        self::assertFalse($source->isCompleted($s));

        $s->decide(SuggestionStatus::Rejected, null, null);
        self::assertTrue($source->isCompleted($s));
    }

    #[Test]
    public function theLinkOpensTheQueueFilteredOnTheRecord(): void
    {
        $s = (new Suggestion(new Tenant(), 'risk.residual', SuggestionOrigin::Rule, 'T', 'R', 'k'))->setSubject(Risk::class, 7);

        $link = $this->source()->linkOf($s);

        self::assertSame('app_suggestion_index', $link->route);
        self::assertSame(['subject' => Risk::class . '#7'], $link->parameters);
    }

    #[Test]
    public function importMatchesStayWithTheKindRoles(): void
    {
        $s = new Suggestion(new Tenant(), 'unknown.kind', SuggestionOrigin::Import, 'T', 'R', 'k');
        $responsibility = (new SuggestionResponsibility([], $this->createStub(EntityManagerInterface::class)))->of($s);

        self::assertNull($responsibility->record);
        self::assertSame(['ROLE_MANAGER'], $responsibility->roles, 'unknown kind falls back to managers');
    }

    private function source(): SuggestionDeadlineSource
    {
        return new SuggestionDeadlineSource(
            $this->createStub(SuggestionRepository::class),
            new SuggestionResponsibility([], $this->createStub(EntityManagerInterface::class)),
        );
    }
}
