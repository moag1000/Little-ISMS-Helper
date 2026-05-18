<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Enum\DocumentStatus;
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
 * Since the status field migrated to `EnumType` + `class => DocumentStatus`
 * (status-enum drift reconciliation), the canonical list lives in the enum;
 * the form filters out the terminal `Deleted` state. This test mirrors that.
 *
 * @see \App\Lifecycle\LifecycleRegistry::STANDARD_5_STAGE
 * @see \App\Enum\DocumentStatus
 */
final class DocumentTypeTest extends TestCase
{
    /**
     * Canonical 5-stage values offered by the DocumentType `status` widget,
     * derived from `DocumentStatus::cases()` minus the terminal soft-delete
     * state that is only writable via the `soft_delete` workflow transition.
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

        // Confirm the FormType binds to DocumentStatus via EnumType.
        self::assertMatchesRegularExpression(
            "/->add\(\s*'status'\s*,\s*EnumType::class\b/",
            $source,
            'status field must use EnumType::class for drift-proof binding to DocumentStatus.',
        );
        self::assertStringContainsString(
            "'class' => DocumentStatus::class",
            $source,
            'EnumType must declare class => DocumentStatus::class.',
        );
        // The form filters out the terminal soft-delete case.
        self::assertStringContainsString(
            'DocumentStatus::Deleted',
            $source,
            'Deleted case must be filtered out of the user-facing choices.',
        );

        $values = array_map(
            static fn(DocumentStatus $s): string => $s->value,
            array_values(array_filter(
                DocumentStatus::cases(),
                static fn(DocumentStatus $s): bool => $s !== DocumentStatus::Deleted,
            )),
        );

        return $values;
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
