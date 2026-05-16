<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * P-9 JsonBuilder — smoke tests for `_fa_resource_list.html.twig`.
 * BCPlan.requiredResources tab-builder (ISO 22301 §8.1).
 */
final class FaResourceListTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersThreeTabsForCategories(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[requiredResources]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-controller="resource-list"', $output);
        self::assertStringContainsString('data-category="personnel"', $output);
        self::assertStringContainsString('data-category="equipment"', $output);
        self::assertStringContainsString('data-category="supplies"', $output);
        // First tab is active (personnel). The macro emits `aria-selected="true"` for the
        // first tab in document order, followed eventually by `data-category="personnel"`
        // (Twig attribute order). Match within a single <button> tag.
        self::assertMatchesRegularExpression(
            '/<button[^>]*aria-selected="true"[^>]*data-category="personnel"/s',
            $output,
        );
    }

    #[Test]
    public function serialisesObjectValueAsJsonIntoHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[requiredResources]',
            'value' => [
                'personnel' => ['2 SysAdmins'],
                'equipment' => ['Laptop'],
                'supplies' => ['USB'],
            ],
        ]);

        self::assertStringContainsString('"personnel"', $output);
        self::assertStringContainsString('SysAdmins', $output);
        self::assertStringContainsString('"equipment"', $output);
        self::assertStringContainsString('Laptop', $output);
    }

    #[Test]
    public function rendersTabpanelsAndAddForms(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[requiredResources]',
            'value' => null,
        ]);

        self::assertStringContainsString('role="tablist"', $output);
        self::assertStringContainsString('role="tabpanel"', $output);
        self::assertStringContainsString('data-action="submit->resource-list#addEntry"', $output);
    }

    #[Test]
    public function rendersRawJsonToggleButton(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[requiredResources]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-action="click->resource-list#showRawJson"', $output);
        self::assertStringContainsString('data-resource-list-target="rawPanel"', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacro(array $props): string
    {
        $template = '{% import "_components/_fa_resource_list.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
