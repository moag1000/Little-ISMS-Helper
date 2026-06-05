<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\LifecycleStuckInStatusRule;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LifecycleStuckInStatusRule.
 *
 * The hint must deep-link to EXACTLY the documents it counts: one stuck
 * document → that document's show page; several → the document index
 * pre-filtered to focus=lifecycle_stuck.
 */
#[AllowMockObjectsWithoutExpectations]
final class LifecycleStuckInStatusRuleTest extends TestCase
{
    private Tenant $tenant;
    private ?User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoStuckDocuments(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeRepo(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleStuckDocumentDeepLinksToThatDocument(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeRepo(1, firstId: 77));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_document_show', $hint->actionRoute);
        self::assertSame(['id' => 77], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalStuckDocumentsLinkToFilteredIndex(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeRepo(3));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_document_index', $hint->actionRoute);
        self::assertSame(['focus' => 'lifecycle_stuck'], $hint->actionRouteParams);
        self::assertSame('3', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function hintMetadataIsTier3WarningDismissibleManagerGet(): void
    {
        $hint = (new LifecycleStuckInStatusRule($this->makeRepo(2)))->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('alva_hint.lifecycle.stuck_in_status', $hint->key);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertTrue($hint->dismissible);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('14', $hint->bodyTranslationParams['%days%']);
    }

    #[Test]
    public function ruleConventions(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeRepo(0));
        self::assertSame('alva_hint.lifecycle.stuck_in_status', $rule->key());
        self::assertSame([], $rule->requiredModules());
        self::assertSame([], $rule->appliesToPages());
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
        $repo->method('findStuckInLifecycle')->willReturn($docs);

        return $repo;
    }
}
