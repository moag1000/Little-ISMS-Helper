<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S3 P-4 — Document status lifecycle.
 *
 * Verifies the FormType no longer offers the undocumented 6th choice
 * `active`. The canonical 5-stage lifecycle remains
 * draft → in_review → approved → published → archived.
 *
 * Structural (source-inspection) test pattern — DocumentType depends on
 * SystemSettingsRepository + ModuleConfigurationService and inspects
 * existing entity state, which would require a heavy mock matrix for a
 * pure TypeTestCase. Matches the ModuleGatingTest approach.
 *
 * @see \App\Lifecycle\LifecycleRegistry::STANDARD_5_STAGE
 */
final class DocumentTypeTest extends TestCase
{
    /**
     * Extracts the status `choices` array from DocumentType source.
     *
     * @return list<string> Status values in declaration order.
     */
    private static function parseStatusChoiceValues(): array
    {
        // Path-based read — robust against shared-vendor multi-worktree setups
        // where the autoloader baseDir may be pinned to a sibling worktree.
        $file = __DIR__ . '/../../src/Form/DocumentType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        // Match the ->add('status', ChoiceType::class, [...]) block.
        $matched = preg_match(
            "/->add\(\s*'status'\s*,\s*ChoiceType::class\s*,\s*\[(.+?)\]\s*\)/s",
            $source,
            $matches
        );
        self::assertSame(1, $matched, 'status ChoiceType block not found in DocumentType');

        $block = $matches[1];

        // Extract values from key/value pairs like 'document.status.draft' => 'draft'.
        preg_match_all(
            "/'document\.status\.[a-z_]+'\s*=>\s*'([a-z_]+)'/",
            $block,
            $valueMatches
        );

        return $valueMatches[1];
    }

    #[Test]
    public function statusChoicesContainCanonicalFiveStages(): void
    {
        $values = self::parseStatusChoiceValues();

        // Hard-code expected values to avoid coupling to the class-loader
        // (shared-vendor multi-worktree setups can shadow the local
        // LifecycleRegistry). The constant is asserted opportunistically.
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            $values
        );
        if (class_exists(LifecycleRegistry::class)) {
            self::assertSame(LifecycleRegistry::STANDARD_5_STAGE, $values);
        }
    }

    #[Test]
    public function statusChoicesDoNotContainLegacyActive(): void
    {
        $values = self::parseStatusChoiceValues();

        self::assertNotContains(
            'active',
            $values,
            'Legacy undocumented 6th status `active` must be removed from DocumentType — '
            . 'S3 P-4 normalised Document onto canonical 5-stage lifecycle (active → published).'
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
    public function entityDefaultStatusIsDraft(): void
    {
        // Confirm Document::$status defaults to 'draft' (not legacy 'active').
        // Path-based read — robust against shared-vendor multi-worktree setups.
        $entityFile = __DIR__ . '/../../src/Entity/Document.php';
        self::assertFileExists($entityFile);

        $source = file_get_contents($entityFile);
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            "/private string \\\$status = 'draft';/",
            $source,
            'Document::$status default must be the canonical "draft" — S3 P-4.'
        );

        self::assertStringNotContainsString(
            "private string \$status = 'active';",
            $source,
            'Legacy default `active` must be removed from Document::$status.'
        );
    }
}
