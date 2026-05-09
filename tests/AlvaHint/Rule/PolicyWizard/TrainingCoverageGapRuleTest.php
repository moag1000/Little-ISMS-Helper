<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\TrainingCoverageGapRule;
use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-D — TrainingCoverageGapRule unit tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class TrainingCoverageGapRuleTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PolicyAcknowledgementRepository&MockObject $acknowledgementRepository;
    private UserRepository&MockObject $userRepository;
    private User $user;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->acknowledgementRepository = $this->createMock(PolicyAcknowledgementRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->user = new User();
    }

    #[Test]
    public function testFiresWhenConditionsMet(): void
    {
        $tenant = $this->makeTenant(7);
        $policy = $this->makeDocument(101, 'AUP.pdf');

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$policy]));
        // 100 active users, 10 acknowledgements → 10% (well below 80%).
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 100, new User()));
        $this->acknowledgementRepository->method('findByDocument')
            ->willReturn(array_fill(0, 10, new PolicyAcknowledgement()));

        $rule = new TrainingCoverageGapRule(
            $this->documentRepository,
            $this->acknowledgementRepository,
            $this->userRepository,
        );
        self::assertTrue($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenConditionsNotMet(): void
    {
        $tenant = $this->makeTenant(7);
        $policy = $this->makeDocument(101, 'Policy.pdf');

        // Case 1: coverage above the threshold (100/100 = 100%).
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$policy]));
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 100, new User()));
        $this->acknowledgementRepository->method('findByDocument')
            ->willReturn(array_fill(0, 100, new PolicyAcknowledgement()));

        $rule = new TrainingCoverageGapRule(
            $this->documentRepository,
            $this->acknowledgementRepository,
            $this->userRepository,
        );
        self::assertFalse($rule->appliesTo($tenant, $this->user));

        // Case 2: no published policies at all.
        $emptyDocs = $this->createMock(DocumentRepository::class);
        $emptyDocs->method('createQueryBuilder')->willReturn($this->stubResultQueryBuilder([]));
        $emptyAcks = $this->createMock(PolicyAcknowledgementRepository::class);
        $emptyUsers = $this->createMock(UserRepository::class);
        $emptyRule = new TrainingCoverageGapRule($emptyDocs, $emptyAcks, $emptyUsers);
        self::assertFalse($emptyRule->appliesTo($tenant, $this->user));

        // Case 3: brand-new tenant — zero active users → no audience to chase.
        $noUsersDocs = $this->createMock(DocumentRepository::class);
        $noUsersDocs->method('createQueryBuilder')->willReturn($this->stubResultQueryBuilder([$policy]));
        $noUsersAcks = $this->createMock(PolicyAcknowledgementRepository::class);
        $noUsersUsers = $this->createMock(UserRepository::class);
        $noUsersUsers->method('findActiveUsers')->willReturn([]);
        $noUsersRule = new TrainingCoverageGapRule($noUsersDocs, $noUsersAcks, $noUsersUsers);
        self::assertFalse($noUsersRule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenWrongRole(): void
    {
        $tenant = $this->makeTenant(7);
        $policy = $this->makeDocument(101, 'AUP.pdf');

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$policy]));
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 100, new User()));
        $this->acknowledgementRepository->method('findByDocument')
            ->willReturn(array_fill(0, 5, new PolicyAcknowledgement()));

        $rule = new TrainingCoverageGapRule(
            $this->documentRepository,
            $this->acknowledgementRepository,
            $this->userRepository,
        );
        $hint = $rule->build($tenant, $this->user);

        self::assertSame(['ROLE_ADMIN', 'ROLE_GROUP_CISO'], $hint->requiredRoles);
        self::assertNotContains('ROLE_USER', $hint->requiredRoles);
        self::assertNotContains('ROLE_AUDITOR', $hint->requiredRoles);
    }

    #[Test]
    public function testSkipsWhenModuleDisabled(): void
    {
        $rule = new TrainingCoverageGapRule(
            $this->documentRepository,
            $this->acknowledgementRepository,
            $this->userRepository,
        );
        self::assertContains('policy_wizard', $rule->requiredModules());
    }

    #[Test]
    public function testRenderAndDismissTelemetry(): void
    {
        $tenant = $this->makeTenant(42);
        $policy = $this->makeDocument(101, 'AcceptableUse.pdf');

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$policy]));
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 100, new User()));
        $this->acknowledgementRepository->method('findByDocument')
            ->willReturn(array_fill(0, 25, new PolicyAcknowledgement()));

        $rule = new TrainingCoverageGapRule(
            $this->documentRepository,
            $this->acknowledgementRepository,
            $this->userRepository,
        );
        $hint = $rule->build($tenant, $this->user);

        self::assertSame('policy_wizard.training_coverage_gap', $hint->key);
        self::assertSame(TrainingCoverageGapRule::VERSION, $hint->version);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible, 'Tier-2 hint should be dismissible');
        self::assertSame('Tenant', $hint->entityType);
        self::assertSame(42, $hint->entityId);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('alva_hint.training_coverage_gap.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.training_coverage_gap.body', $hint->bodyTranslationKey);
        self::assertSame('alva_hint.training_coverage_gap.cta_label', $hint->actionLabelTranslationKey);
        self::assertSame('app_policy_ack_inbox', $hint->actionRoute);
        self::assertSame([], $hint->actionRouteParams);
        self::assertSame('AcceptableUse.pdf', $hint->bodyTranslationParams['%document_title%'] ?? null);
        self::assertSame('25', $hint->bodyTranslationParams['%coverage_percent%'] ?? null);
        self::assertSame((string) TrainingCoverageGapRule::THRESHOLD_PERCENT, $hint->bodyTranslationParams['%threshold_percent%'] ?? null);
    }

    private function makeTenant(int $id): Tenant&MockObject
    {
        $stub = $this->createMock(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeDocument(int $id, string $originalFilename): Document&MockObject
    {
        $stub = $this->createMock(Document::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getOriginalFilename')->willReturn($originalFilename);
        $stub->method('getFilename')->willReturn($originalFilename);
        return $stub;
    }

    /**
     * @param list<object> $result
     */
    private function stubResultQueryBuilder(array $result): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($result);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
