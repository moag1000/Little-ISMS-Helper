<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\BCExerciseType;
use App\Form\BusinessContinuityPlanType;
use App\Form\SectionMapInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S4 Foundation P-2 SectionPolicy.
 *
 * Structural test pattern — verifies that:
 *  - FormTypes implementing {@see SectionMapInterface} declare a parseable
 *    section-map.
 *  - Each builder->add('<field>') call references a field that appears in
 *    exactly one section (no catch-all leakage, no duplicates, no dead
 *    section entries).
 *
 * Mirrors the static-analysis logic of
 * `scripts/quality/check_form_sections.py` so test failures surface the
 * same regression that would block CI.
 */
final class SectionPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<SectionMapInterface>, string}>
     */
    public static function sectionMapFormTypes(): iterable
    {
        yield 'BusinessContinuityPlanType' => [
            BusinessContinuityPlanType::class,
            __DIR__ . '/../../src/Form/BusinessContinuityPlanType.php',
        ];
        yield 'BCExerciseType' => [
            BCExerciseType::class,
            __DIR__ . '/../../src/Form/BCExerciseType.php',
        ];
    }

    /**
     * @param class-string<SectionMapInterface> $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sectionMapFormTypes')]
    public function formTypeImplementsSectionMapInterface(string $class, string $sourcePath): void
    {
        self::assertTrue(
            is_subclass_of($class, SectionMapInterface::class),
            sprintf('%s must implement %s', $class, SectionMapInterface::class)
        );
    }

    /**
     * @param class-string<SectionMapInterface> $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sectionMapFormTypes')]
    public function sectionMapIsNonEmpty(string $class, string $sourcePath): void
    {
        $map = $class::getSectionMap();
        self::assertNotEmpty(
            $map,
            sprintf('%s::getSectionMap() must declare at least one section', $class)
        );
        foreach ($map as $key => $fields) {
            self::assertIsString($key, 'Section keys must be strings');
            self::assertIsArray($fields, sprintf('Section "%s" must map to a list of fields', $key));
            self::assertNotEmpty($fields, sprintf('Section "%s" must not be empty', $key));
        }
    }

    /**
     * @param class-string<SectionMapInterface> $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sectionMapFormTypes')]
    public function noDuplicateFieldsAcrossSections(string $class, string $sourcePath): void
    {
        $map = $class::getSectionMap();
        $seen = [];
        foreach ($map as $sectionKey => $fields) {
            foreach ($fields as $field) {
                self::assertArrayNotHasKey(
                    $field,
                    $seen,
                    sprintf(
                        'Field "%s" appears in both "%s" and "%s" in %s',
                        $field,
                        $seen[$field] ?? '?',
                        $sectionKey,
                        $class
                    )
                );
                $seen[$field] = $sectionKey;
            }
        }
    }

    /**
     * Every field referenced in the section-map must be added via
     * builder->add(...) in buildForm(). Otherwise the section entry is
     * dead — fields silently disappear from the rendered form.
     *
     * @param class-string<SectionMapInterface> $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sectionMapFormTypes')]
    public function sectionFieldsAreRegisteredOnBuilder(string $class, string $sourcePath): void
    {
        self::assertFileExists($sourcePath);
        $source = file_get_contents($sourcePath);
        self::assertIsString($source);

        $builderFields = self::parseBuilderFields($source);
        self::assertNotEmpty($builderFields, sprintf('No builder->add() calls found in %s', $sourcePath));

        $map = $class::getSectionMap();
        foreach ($map as $sectionKey => $fields) {
            foreach ($fields as $field) {
                self::assertContains(
                    $field,
                    $builderFields,
                    sprintf(
                        'Section "%s" references field "%s" but no builder->add(\'%s\', ...) found in %s',
                        $sectionKey,
                        $field,
                        $field,
                        basename($sourcePath)
                    )
                );
            }
        }
    }

    /**
     * Every builder field must appear in exactly one section — otherwise it
     * leaks into the legacy catch-all "Sonstiges" bucket which is the
     * regulatory anti-pattern this convention exists to eliminate.
     *
     * @param class-string<SectionMapInterface> $class
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sectionMapFormTypes')]
    public function everyBuilderFieldIsCoveredBySection(string $class, string $sourcePath): void
    {
        self::assertFileExists($sourcePath);
        $source = file_get_contents($sourcePath);
        self::assertIsString($source);

        $builderFields = self::parseBuilderFields($source);
        $map = $class::getSectionMap();
        $covered = [];
        foreach ($map as $fields) {
            foreach ($fields as $f) {
                $covered[$f] = true;
            }
        }

        foreach ($builderFields as $field) {
            self::assertArrayHasKey(
                $field,
                $covered,
                sprintf(
                    'Builder field "%s" is not covered by any section in %s::getSectionMap() — it would leak to the "Sonstiges" catch-all bucket.',
                    $field,
                    $class
                )
            );
        }
    }

    /**
     * BCPlan-specific assertion — RTO/RPO must live in the `recovery`
     * section (ISO 22301 Cl. 8.2.2 / 8.4.2).
     */
    #[Test]
    public function bcPlanRtoRpoLiveInRecoverySection(): void
    {
        $map = BusinessContinuityPlanType::getSectionMap();
        self::assertArrayHasKey('recovery', $map, 'BCPlan must declare a "recovery" section');
        self::assertContains('rto', $map['recovery'], 'BCPlan.rto must live in the "recovery" section');
        self::assertContains('rpo', $map['recovery'], 'BCPlan.rpo must live in the "recovery" section');
        self::assertContains(
            'criticalAssets',
            $map['recovery'],
            'BCPlan.criticalAssets must live in the "recovery" section'
        );
    }

    /**
     * BCExercise-specific assertion — Result fields must live in the
     * `results` section (ISO 22301 §8.5.4).
     */
    #[Test]
    public function bcExerciseResultFieldsLiveInResultsSection(): void
    {
        $map = BCExerciseType::getSectionMap();
        self::assertArrayHasKey('results', $map, 'BCExercise must declare a "results" section');
        self::assertContains('actualRtoAchieved', $map['results']);
        self::assertContains('actualRpoAchieved', $map['results']);
        self::assertContains('successCriteria', $map['results']);
        self::assertContains('evidenceArtifacts', $map['results']);
    }

    /**
     * Parse `->add('<fieldName>', ...)` calls from FormType source.
     *
     * Additionally synthesises the four fields contributed by the
     * OwnerPickerFormTrait helper `$this->addOwnerPicker($builder, [...])` —
     * `user_field`, `person_field`, `deputies_field`, `legacy_field`.
     * Those land on the builder via the trait but the static parser
     * doesn't see literal `->add()` calls for them.
     *
     * @return list<string>
     */
    private static function parseBuilderFields(string $source): array
    {
        preg_match_all(
            "/->add\(\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]/",
            $source,
            $matches
        );
        $fields = $matches[1];

        // Capture addOwnerPicker(...) config-array fields.
        preg_match_all(
            "/addOwnerPicker\(\s*\\\$builder\s*,\s*\[(.*?)\]\s*\)/s",
            $source,
            $pickerCalls
        );
        foreach ($pickerCalls[1] ?? [] as $configBody) {
            foreach (['user_field', 'person_field', 'deputies_field', 'legacy_field'] as $key) {
                if (preg_match("/['\"]" . $key . "['\"]\s*=>\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]/", $configBody, $m)) {
                    $fields[] = $m[1];
                }
            }
        }

        return array_values(array_unique($fields));
    }
}
