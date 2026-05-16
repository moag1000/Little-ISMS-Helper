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
}
