<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\GenerationApprovalElapsedGuard;
use App\Service\PolicyWizard\MinimumElapsedNotReachedException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the W1 audit-defang gap #2 generation-to-approval
 * elapsed-time gate. Asserts the five contract rules from
 * `07-phase4-sprint-reconciliation.md` line 215-225 (auditor "What
 * would make me NOT challenge auto-generation" item 2).
 */
#[AllowMockObjectsWithoutExpectations]
final class GenerationApprovalElapsedGuardTest extends TestCase
{
    private AuditLogger $auditLogger;
    private GenerationApprovalElapsedGuard $guard;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->guard = new GenerationApprovalElapsedGuard($this->auditLogger);
    }

    private function makeWizardDocument(int $bodyLength, DateTimeImmutable $generatedAt): Document
    {
        $template = $this->createMock(PolicyTemplate::class);
        $run = $this->createMock(WizardRun::class);

        $document = new Document();
        $document->setUploadedAt($generatedAt);
        $document->setFileSize($bodyLength);
        // Reuse generatedFrom* setters so isGuardApplicable() returns true.
        $document->setGeneratedFromTemplate($template);
        $document->setGeneratedFromWizardRun($run);
        $document->setStatus('draft');
        return $document;
    }

    private function makeUser(int $id = 7): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    #[Test]
    public function testApprovalAllowedAfterMinimum(): void
    {
        // Tiny body (1 KB) → threshold floor 180 s. Five-minute-old
        // generation should sail through.
        $document = $this->makeWizardDocument(
            bodyLength: 1024,
            generatedAt: new DateTimeImmutable('-5 minutes'),
        );
        $approver = $this->makeUser();

        $this->auditLogger->expects(self::never())->method('logCustom');

        $this->guard->assertMinimumElapsed($document, $approver);
        // No exception → pass. PHPUnit needs an explicit assertion.
        self::assertTrue(true);
    }

    #[Test]
    public function testApprovalRejectedBeforeMinimum(): void
    {
        $document = $this->makeWizardDocument(
            bodyLength: 1024,
            generatedAt: new DateTimeImmutable('-30 seconds'),
        );
        $approver = $this->makeUser(42);

        $this->auditLogger->expects(self::once())->method('logCustom')
            ->with(
                self::equalTo('min_elapsed_violation'),
                self::equalTo('Document'),
            );

        try {
            $this->guard->assertMinimumElapsed($document, $approver);
            self::fail('Expected MinimumElapsedNotReachedException');
        } catch (MinimumElapsedNotReachedException $exception) {
            self::assertSame($document, $exception->document);
            self::assertSame($approver, $exception->approver);
            self::assertSame(GenerationApprovalElapsedGuard::MIN_THRESHOLD_SECONDS, $exception->minimumRequiredSeconds);
            self::assertGreaterThan(0, $exception->getRemainingSeconds());
        }
    }

    #[Test]
    public function testThresholdScalesWithBodyLength(): void
    {
        // Big body (100 KB) → ceil(100_000 / 200) = 500 s threshold
        // (above the 180 s floor). 4-minute-old generation must fail.
        $document = $this->makeWizardDocument(
            bodyLength: 100_000,
            generatedAt: new DateTimeImmutable('-4 minutes'),
        );
        $approver = $this->makeUser();

        self::assertSame(500, $this->guard->resolveThresholdSeconds($document));

        $this->expectException(MinimumElapsedNotReachedException::class);
        $this->guard->assertMinimumElapsed($document, $approver);
    }

    #[Test]
    public function testMinThresholdEnforcedAtFloor(): void
    {
        // Tiny body (200 chars) → proportional component would be 1 s
        // but the floor pins to 180 s.
        $document = $this->makeWizardDocument(
            bodyLength: 200,
            generatedAt: new DateTimeImmutable('-10 seconds'),
        );
        $approver = $this->makeUser();

        self::assertSame(
            GenerationApprovalElapsedGuard::MIN_THRESHOLD_SECONDS,
            $this->guard->resolveThresholdSeconds($document),
        );

        $this->expectException(MinimumElapsedNotReachedException::class);
        $this->guard->assertMinimumElapsed($document, $approver);
    }

    #[Test]
    public function testGuardLogsViolations(): void
    {
        $document = $this->makeWizardDocument(
            bodyLength: 4_000,
            generatedAt: new DateTimeImmutable('-15 seconds'),
        );
        $approver = $this->makeUser(99);

        $this->auditLogger->expects(self::once())->method('logCustom')
            ->with(
                self::equalTo('min_elapsed_violation'),
                self::equalTo('Document'),
                self::isNull(),
                self::isNull(),
                self::callback(static function ($values): bool {
                    if (!is_array($values)) {
                        return false;
                    }
                    return ($values['approver_id'] ?? null) === 99
                        && ($values['tag'] ?? null) === 'policy-approval'
                        && isset($values['required_seconds'])
                        && isset($values['elapsed_seconds']);
                }),
            );

        try {
            $this->guard->assertMinimumElapsed($document, $approver);
            self::fail('Expected MinimumElapsedNotReachedException');
        } catch (MinimumElapsedNotReachedException) {
            // Expected — assertion is on the logCustom mock above.
        }
    }
}
