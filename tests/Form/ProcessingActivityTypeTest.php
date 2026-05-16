<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S3 P-4 — ProcessingActivity (VVT) status lifecycle.
 *
 * Verifies the FormType now exposes the canonical 5-stage lifecycle
 * (draft → in_review → approved → published → archived) and no longer
 * offers the legacy 3-stage `active` choice.
 *
 * Structural (source-inspection) test pattern — ProcessingActivityType has
 * 10+ EntityType fields which would require a full DoctrineExtension
 * mocking matrix. The structural approach matches ModuleGatingTest.
 *
 * @see \App\Lifecycle\LifecycleRegistry::STANDARD_5_STAGE
 */
final class ProcessingActivityTypeTest extends TestCase
{
    private static function getFormTypeSource(): string
    {
        // Path-based read (not ReflectionClass::getFileName()) — robust against
        // shared-vendor + multi-worktree setups where the autoloader baseDir
        // can be pinned to a sibling worktree.
        $file = __DIR__ . '/../../src/Form/ProcessingActivityType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    /**
     * Extracts the status `choices` array from ProcessingActivityType source.
     *
     * @return list<string> Status values in declaration order.
     */
    private static function parseStatusChoiceValues(): array
    {
        $source = self::getFormTypeSource();

        // Match the ->add('status', ChoiceType::class, [...]) block.
        $matched = preg_match(
            "/->add\(\s*'status'\s*,\s*ChoiceType::class\s*,\s*\[(.+?)\]\s*\)/s",
            $source,
            $matches
        );
        self::assertSame(1, $matched, 'status ChoiceType block not found in ProcessingActivityType');

        $block = $matches[1];

        // Extract values from key/value pairs like 'processing_activity.status.draft' => 'draft'.
        preg_match_all(
            "/'processing_activity\.status\.[a-z_]+'\s*=>\s*'([a-z_]+)'/",
            $block,
            $valueMatches
        );

        return $valueMatches[1];
    }

    #[Test]
    public function statusChoicesContainCanonicalFiveStages(): void
    {
        $values = self::parseStatusChoiceValues();

        // Hard-code expected values to avoid coupling the assertion to the
        // class-loader (shared-vendor multi-worktree setups can shadow the
        // local LifecycleRegistry). The constant is asserted elsewhere.
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            $values
        );
        // Sanity check: constant matches the canonical list when available.
        if (class_exists(LifecycleRegistry::class)) {
            self::assertSame(array_keys(LifecycleRegistry::STANDARD_5_STAGE), $values);
        }
    }

    #[Test]
    public function statusChoicesDoNotContainLegacyActive(): void
    {
        $values = self::parseStatusChoiceValues();

        self::assertNotContains(
            'active',
            $values,
            'Legacy 3-stage `active` status must be removed — S3 P-4 normalised VVT '
            . 'onto canonical 5-stage lifecycle (active → published).'
        );
    }

    #[Test]
    public function statusChoicesPreserveCanonicalOrdering(): void
    {
        $values = self::parseStatusChoiceValues();

        // Order is significant — forward progression is left-to-right.
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            $values,
        );
    }

    #[Test]
    public function entityAssertChoiceUsesCanonicalLifecycle(): void
    {
        // Belt-and-suspenders: confirm ProcessingActivity::$status now constrains
        // values to LifecycleRegistry::STANDARD_5_STAGE rather than the legacy
        // 3-stage list. Reads the entity source directly via path (not Reflection)
        // to remain robust against shared-vendor multi-worktree setups.
        $entityFile = __DIR__ . '/../../src/Entity/ProcessingActivity.php';
        self::assertFileExists($entityFile);

        $source = file_get_contents($entityFile);
        self::assertIsString($source);

        self::assertStringContainsString(
            'Assert\Choice(choices: LifecycleRegistry::STANDARD_5_STAGE)',
            $source,
            'ProcessingActivity::$status Assert\\Choice must reference the canonical registry constant.'
        );

        // Reject leftover legacy hard-coded list.
        self::assertStringNotContainsString(
            "Assert\\Choice(choices: ['draft', 'active', 'archived'])",
            $source,
            'Legacy hard-coded 3-stage Assert\\Choice must be removed.'
        );
    }

    // ─── S4 P-1 Wave-2 — OwnerPicker rollout assertions ───────────────────────

    #[Test]
    public function usesOwnerPickerFormTrait(): void
    {
        $source = self::getFormTypeSource();
        self::assertStringContainsString(
            'use OwnerPickerFormTrait;',
            $source,
            'ProcessingActivityType must use OwnerPickerFormTrait to wire contact + DPO slots.'
        );
    }

    #[Test]
    public function contactSlotIsWiredViaAddOwnerPicker(): void
    {
        $source = self::getFormTypeSource();
        self::assertStringContainsString(
            "'user_field'     => 'contactPersonUser',",
            $source,
            'Contact-Person slot must keep contactPersonUser as the User-slot field.'
        );
        self::assertStringContainsString(
            "'person_field'   => 'contactPerson',",
            $source,
            'Contact-Person fallback slot must keep contactPerson as the Person-slot field.'
        );
        self::assertStringContainsString(
            "'deputies_field' => 'contactDeputyPersons',",
            $source,
            'Contact deputies must remain contactDeputyPersons.'
        );
    }

    #[Test]
    public function dpoSlotIsModuleGatedAndWiredViaAddOwnerPicker(): void
    {
        $source = self::getFormTypeSource();
        // DPO slot must be wrapped in isModuleActive('privacy') guard.
        self::assertMatchesRegularExpression(
            "/isModuleActive\('privacy'\)[^}]*?addOwnerPicker[^}]*?'dataProtectionOfficer'/s",
            $source,
            'DPO slot must be wired via addOwnerPicker INSIDE an isModuleActive(privacy) guard.'
        );
        self::assertStringContainsString(
            "'user_field'     => 'dataProtectionOfficer',",
            $source,
            'DPO User-slot must remain dataProtectionOfficer.'
        );
        self::assertStringContainsString(
            "'person_field'   => 'dataProtectionOfficerPerson',",
            $source,
            'DPO Person fallback slot must remain dataProtectionOfficerPerson.'
        );
        self::assertStringContainsString(
            "'deputies_field' => 'dataProtectionOfficerDeputyPersons',",
            $source,
            'DPO deputies must remain dataProtectionOfficerDeputyPersons.'
        );
    }

    #[Test]
    public function validateContactPersonSlotIsRetained(): void
    {
        $source = self::getFormTypeSource();
        self::assertStringContainsString(
            'public function validateContactPersonSlot(',
            $source,
            'ProcessingActivityType must retain the validateContactPersonSlot validator.'
        );
    }

    #[Test]
    public function validateDpoSlotIsModuleAwareSinceP1(): void
    {
        $source = self::getFormTypeSource();
        // The validator must short-circuit when privacy module is off, otherwise
        // it would fire on tenants without GDPR — false-positive.
        self::assertStringContainsString(
            "if (!\$this->isModuleActive('privacy')) {",
            $source,
            'validateDpoSlot must short-circuit when privacy module is not active (S4 P-1).'
        );
    }

    #[Test]
    public function legacyInlineEntityTypeFieldsAreRemoved(): void
    {
        $source = self::getFormTypeSource();
        // After P-1 rollout, the contactPersonUser / dataProtectionOfficer
        // EntityType::class blocks should be wired exclusively through
        // addOwnerPicker — no inline ->add('contactPersonUser', EntityType::class)
        // duplications.
        self::assertStringNotContainsString(
            "->add('contactPersonUser', EntityType::class",
            $source,
            'contactPersonUser must be wired exclusively via addOwnerPicker, not as an inline ->add() call.'
        );
        self::assertStringNotContainsString(
            "->add('dataProtectionOfficerDeputyPersons', EntityType::class",
            $source,
            'dataProtectionOfficerDeputyPersons must be wired exclusively via addOwnerPicker.'
        );
    }
}
