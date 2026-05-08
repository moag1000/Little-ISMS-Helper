<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Mode;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Mode\SandboxModeHandler;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-C — Sandbox-mode handler unit tests.
 *
 * Exercises the four behavioural contracts of architecture §6.4:
 *   - status flips to 'sandbox' on start
 *   - per-step persistence accumulates a preview snapshot
 *   - complete()/generate() returns no document_ids and a non-null
 *     preview
 *   - 7-day expiry semantics surface via repository cut-off (here we
 *     verify the cut-off computation; the SQL-side filter is covered
 *     by the command test).
 */
final class SandboxModeHandlerTest extends TestCase
{
    private function makeRun(): WizardRun
    {
        $run = new WizardRun();
        $run->setMode(WizardStepKeys::MODE_SANDBOX);
        $run->setStep(WizardStepKeys::STEP_WELCOME);
        $run->setStatus(WizardStepKeys::STATUS_IN_PROGRESS);
        $run->setInputs([]);
        return $run;
    }

    #[Test]
    public function testSandboxStartSetsStatus(): void
    {
        $handler = new SandboxModeHandler();
        $run = $this->makeRun();
        // Pretend the orchestrator started the run with the default
        // 'in_progress' status — sandbox handler must override.
        $run->setStatus(WizardStepKeys::STATUS_IN_PROGRESS);

        $handler->onStart($run);

        self::assertSame(WizardStepKeys::STATUS_SANDBOX, $run->getStatus());
        self::assertSame(WizardStepKeys::MODE_SANDBOX, $run->getMode());

        // Empty preview slot scaffolded.
        $bag = $run->getInputs();
        self::assertIsArray($bag);
        self::assertArrayHasKey(SandboxModeHandler::PREVIEW_SLOT, $bag);
        self::assertSame([], $bag[SandboxModeHandler::PREVIEW_SLOT]['steps']);
    }

    #[Test]
    public function testSandboxStepProgressionStoresPreview(): void
    {
        $handler = new SandboxModeHandler();
        $run = $this->makeRun();
        $handler->onStart($run);

        // Simulate the StepInterface having written its slot first
        // (orchestrator order: step.persist() → handler.onAfterStep()).
        $bag = $run->getInputs();
        $bag[WizardStepKeys::STEP_ORG_SCOPE] = ['legal_name' => 'Acme GmbH'];
        $run->setInputs($bag);

        $handler->onAfterStep($run, WizardStepKeys::STEP_ORG_SCOPE);

        $preview = $run->getInputs()[SandboxModeHandler::PREVIEW_SLOT];
        self::assertIsArray($preview);
        self::assertArrayHasKey('steps', $preview);
        self::assertArrayHasKey(WizardStepKeys::STEP_ORG_SCOPE, $preview['steps']);
        self::assertSame(
            ['legal_name' => 'Acme GmbH'],
            $preview['steps'][WizardStepKeys::STEP_ORG_SCOPE],
        );
        self::assertNotEmpty($preview['updated_at'] ?? null);

        // A second step adds without clobbering the first.
        $bag = $run->getInputs();
        $bag[WizardStepKeys::STEP_ROLES] = ['roles' => ['ciso' => 1]];
        $run->setInputs($bag);
        $handler->onAfterStep($run, WizardStepKeys::STEP_ROLES);

        $preview = $run->getInputs()[SandboxModeHandler::PREVIEW_SLOT];
        self::assertCount(2, $preview['steps']);
        self::assertSame(['ciso' => 1], $preview['steps'][WizardStepKeys::STEP_ROLES]['roles']);
    }

    #[Test]
    public function testSandboxCompleteDoesNotPersistDocuments(): void
    {
        $handler = new SandboxModeHandler();
        $run = $this->makeRun();
        $handler->onStart($run);

        // Walk a couple of steps so the preview has content.
        $bag = $run->getInputs();
        $bag[WizardStepKeys::STEP_ORG_SCOPE] = ['legal_name' => 'Acme GmbH'];
        $run->setInputs($bag);
        $handler->onAfterStep($run, WizardStepKeys::STEP_ORG_SCOPE);

        // generate() MUST return an empty document list and a
        // non-null preview payload.
        $result = $handler->generate($run);

        self::assertIsArray($result);
        self::assertSame([], $result['document_ids']);
        self::assertIsArray($result['sandbox_preview']);
        self::assertSame(WizardStepKeys::MODE_SANDBOX, $result['sandbox_preview']['mode']);
        self::assertNotNull($result['sandbox_preview']['generated_at']);
        self::assertArrayHasKey(WizardStepKeys::STEP_ORG_SCOPE, $result['sandbox_preview']['steps']);

        // onComplete must keep the run in sandbox status and force
        // generated_document_ids back to empty.
        $run->setStatus(WizardStepKeys::STATUS_COMPLETED);
        $run->setGeneratedDocumentIds([42, 43]);
        $handler->onComplete($run);

        self::assertSame(WizardStepKeys::STATUS_SANDBOX, $run->getStatus());
        self::assertSame([], $run->getGeneratedDocumentIds());
    }

    #[Test]
    public function testSandboxRunsExpireAfterSevenDays(): void
    {
        // The handler exposes the 7-day window as a public class
        // constant so the purge command + tests share the source.
        self::assertSame(7, SandboxModeHandler::PURGE_AFTER_DAYS);

        // Simulate the cut-off math the purge command performs and
        // verify a 6-day-old run survives, an 8-day-old run dies.
        $now = new DateTimeImmutable('2026-05-06 12:00:00');
        $cutoff = $now->modify(sprintf('-%d days', SandboxModeHandler::PURGE_AFTER_DAYS));

        $survivor = new DateTimeImmutable('2026-05-01 12:00:00'); // 5 days old
        $boundary = new DateTimeImmutable('2026-04-29 12:00:00'); // exactly 7 days old
        $expired = new DateTimeImmutable('2026-04-27 12:00:00');  // 9 days old

        self::assertGreaterThan($cutoff, $survivor, '5-day-old run is within retention.');
        // Boundary case: cutoff is "<", so something exactly 7 days
        // old at the same wall-clock time as the cutoff is NOT
        // purged (matches the WizardRunRepository::findSandboxOlderThan
        // strict-less-than semantics).
        self::assertEquals($cutoff, $boundary);
        self::assertLessThan($cutoff, $expired, '9-day-old run must be purged.');
    }
}
