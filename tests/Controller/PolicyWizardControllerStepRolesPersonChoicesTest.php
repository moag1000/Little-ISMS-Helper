<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PolicyWizardController;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout (2026-05-08) — verifies the new Person-picker
 * wiring on {@see PolicyWizardController::buildStepExtras()} for
 * Step 4 Roles is in place + dependency-injected.
 *
 * Behaviour-level coverage (HTTP roundtrip + DB) lives in the
 * existing {@see PolicyWizardControllerTest} once a Step-4 fixture is
 * added; this is a structural harness so a stray refactor cannot
 * silently drop the picker payload.
 */
final class PolicyWizardControllerStepRolesPersonChoicesTest extends TestCase
{
    #[Test]
    public function controllerConstructorAcceptsPersonRepository(): void
    {
        $reflection = new \ReflectionClass(PolicyWizardController::class);
        $ctor = $reflection->getConstructor();
        self::assertNotNull($ctor);

        $paramNames = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters(),
        );

        self::assertContains(
            'personRepository',
            $paramNames,
            'PolicyWizardController must inject PersonRepository for Step-4 person-pickers.',
        );
    }

    #[Test]
    public function buildStepExtrasMethodHasRolesBranchKeys(): void
    {
        $source = file_get_contents((new \ReflectionClass(PolicyWizardController::class))->getFileName());
        self::assertNotFalse($source);

        // Sanity check: STEP_ROLES branch surfaces every picker key the
        // step.html.twig include consumes. Drift here would render an
        // empty Person-picker without warning.
        foreach ([
            'ciso_person_choices',
            'isb_person_choices',
            'dpo_person_choices',
            'bcm_officer_person_choices',
            'function_owner_person_choices',
            'approval_chain_user_choices',
        ] as $expectedKey) {
            self::assertStringContainsString(
                $expectedKey,
                $source,
                sprintf('PolicyWizardController must emit %s in buildStepExtras().', $expectedKey),
            );
        }

        // Step 4 trigger constant must be referenced.
        self::assertStringContainsString(
            'WizardStepKeys::STEP_ROLES',
            $source,
            'buildStepExtras() must branch on WizardStepKeys::STEP_ROLES.',
        );
    }

    #[Test]
    public function stepRolesConstantStable(): void
    {
        // If STEP_ROLES ever changes, the controller branch + every
        // Step-4 partial breaks silently. Guard the constant value.
        self::assertSame('roles', WizardStepKeys::STEP_ROLES);
    }
}
