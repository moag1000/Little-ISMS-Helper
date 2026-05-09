<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Person-Rollout (2026-05-08) — smoke tests for the
 * `_fa_person_picker.html.twig` macro used by Policy-Wizard Step 4
 * + (in Phase B) every long-term-owner form across the app.
 */
final class FaPersonPickerMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersTomSelectControlAndOptionForEachPerson(): void
    {
        $output = $this->renderMacro([
            'id' => 'role-ciso',
            'name' => 'roles[ciso]',
            'choices' => [
                $this->makePersonRow(id: 11, fullName: 'Alice Anderson', jobTitle: 'CISO', personType: 'employee', linked: true),
                $this->makePersonRow(id: 12, fullName: 'Bob Berger', jobTitle: 'DPO', personType: 'consultant', linked: false),
            ],
            'selectedId' => 12,
            'placeholder' => 'Select…',
        ]);

        self::assertStringContainsString('data-controller="tom-select"', $output);
        self::assertStringContainsString('id="role-ciso"', $output);
        self::assertStringContainsString('name="roles[ciso]"', $output);
        self::assertStringContainsString('Alice Anderson', $output);
        self::assertStringContainsString('Bob Berger', $output);
        // Selected option marker on the right id.
        self::assertMatchesRegularExpression('/<option value="12"\s+selected>/', $output);
        // [EXT] tag for consultant, ✓ for linked user.
        self::assertStringContainsString('[EXT]', $output);
        self::assertStringContainsString('✓', $output);
    }

    #[Test]
    public function rendersEmptyStateWhenNoChoices(): void
    {
        $output = $this->renderMacro([
            'id' => 'role-bcm',
            'name' => 'roles[bcm_officer]',
            'choices' => [],
        ]);

        self::assertStringContainsString('fa-person-picker__empty', $output);
        // Default empty-state pulls the messages-domain string. Match
        // either locale (DE is default in this kernel, EN is fallback).
        self::assertMatchesRegularExpression(
            '/(No persons in tenant|Keine Personen im Mandanten)/',
            $output,
        );
    }

    #[Test]
    public function multipleModeAddsArraySuffixAndMultipleAttribute(): void
    {
        $output = $this->renderMacro([
            'id' => 'fn-deputies',
            'name' => 'function_owners_deputies',
            'choices' => [
                $this->makePersonRow(id: 21, fullName: 'Carol Cain', jobTitle: null, personType: 'employee', linked: false),
                $this->makePersonRow(id: 22, fullName: 'Dan Diaz', jobTitle: null, personType: 'employee', linked: false),
            ],
            'selectedIds' => [22],
            'multiple' => true,
        ]);

        self::assertStringContainsString('name="function_owners_deputies[]"', $output);
        self::assertStringContainsString('multiple', $output);
        self::assertMatchesRegularExpression('/<option value="22"\s+selected>/', $output);
        // No empty placeholder option in multi-select.
        self::assertStringNotContainsString('<option value="">', $output);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function renderMacro(array $args): string
    {
        $template = '{% import "_components/_fa_person_picker.html.twig" as m %}{{ m.render(args) }}';
        return $this->twig->createTemplate($template)->render(['args' => $args]);
    }

    /**
     * Returns a stdClass that quacks like a {@see \App\Entity\Person} for
     * the macro (only the property shape is read, no entity behaviour).
     */
    private function makePersonRow(int $id, string $fullName, ?string $jobTitle, string $personType, bool $linked): object
    {
        $obj = new \stdClass();
        $obj->id = $id;
        $obj->fullName = $fullName;
        $obj->jobTitle = $jobTitle;
        $obj->company = null;
        $obj->personType = $personType;
        $obj->linkedUser = $linked ? new \stdClass() : null;
        return $obj;
    }
}
