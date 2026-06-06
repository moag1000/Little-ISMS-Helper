<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\ApprovedDocOhneAcknowledgementRule;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The awareness-gap hint must deep-link to EXACTLY the approved documents it
 * counts: one → that document's show page; several → the document index
 * pre-filtered to focus=no_ack.
 */
#[AllowMockObjectsWithoutExpectations]
final class ApprovedDocOhneAcknowledgementRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenAllAcknowledged(): void
    {
        $rule = new ApprovedDocOhneAcknowledgementRule($this->makeRepo(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleUnacknowledgedDeepLinksToThatDocument(): void
    {
        $rule = new ApprovedDocOhneAcknowledgementRule($this->makeRepo(1, firstId: 88));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_document_show', $hint->actionRoute);
        self::assertSame(['id' => 88], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalUnacknowledgedLinkToFilteredIndex(): void
    {
        $rule = new ApprovedDocOhneAcknowledgementRule($this->makeRepo(6));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_document_index', $hint->actionRoute);
        self::assertSame(['focus' => 'no_ack'], $hint->actionRouteParams);
        self::assertSame('6', $hint->bodyTranslationParams['%count%']);
        self::assertContains('ROLE_USER', $hint->requiredRoles);
    }

    private function makeRepo(int $count, int $firstId = 1): DocumentRepository
    {
        $docs = [];
        for ($i = 0; $i < $count; ++$i) {
            $doc = $this->createMock(Document::class);
            $doc->method('getId')->willReturn($i === 0 ? $firstId : $firstId + $i);
            $docs[] = $doc;
        }

        $repo = $this->createMock(DocumentRepository::class);
        $repo->method('findApprovedWithoutAcknowledgement')->willReturn($docs);

        return $repo;
    }
}
