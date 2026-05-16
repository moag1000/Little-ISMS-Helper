<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * P-9 JsonBuilder — smoke tests for `_fa_team_role_builder.html.twig`.
 * BCPlan.responseTeamMembers visual editor (ISO 22301 §8.5.3).
 */
final class FaTeamRoleBuilderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersHiddenTextareaWithProvidedName(): void
    {
        $output = $this->renderMacro([
            'name' => 'business_continuity_plan[responseTeamMembers]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-controller="team-role-builder"', $output);
        self::assertStringContainsString('name="business_continuity_plan[responseTeamMembers]"', $output);
        self::assertStringContainsString('data-team-role-builder-target="hidden"', $output);
        // role-options must include all 5 whitelist roles
        self::assertStringContainsString('value="incident_commander"', $output);
        self::assertStringContainsString('value="comms_lead"', $output);
        self::assertStringContainsString('value="recovery_lead"', $output);
        self::assertStringContainsString('value="technical_lead"', $output);
        self::assertStringContainsString('value="other"', $output);
    }

    #[Test]
    public function serialisesArrayValueAsJsonIntoHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[team]',
            'value' => [
                ['role' => 'incident_commander', 'userId' => null, 'name' => 'Alice', 'contact' => '+49 123', 'responsibilities' => 'Coordination'],
            ],
        ]);

        // Pretty-printed JSON or compact — both are valid; we just need the structural anchors.
        self::assertStringContainsString('"role"', $output);
        self::assertStringContainsString('incident_commander', $output);
        self::assertStringContainsString('Alice', $output);
    }

    #[Test]
    public function rendersEmptyStatePlaceholder(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[team]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-team-role-builder-target="emptyState"', $output);
        // Empty-state visibility is toggled by JS — markup is present, "d-none" initially
        self::assertMatchesRegularExpression('/fa-json-builder__empty[^"]*d-none/', $output);
    }

    #[Test]
    public function rendersRawJsonToggleButton(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[team]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-action="click->team-role-builder#showRawJson"', $output);
        self::assertStringContainsString('data-team-role-builder-target="rawPanel"', $output);
    }

    #[Test]
    public function rendersRowTemplate(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[team]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-team-role-builder-target="template"', $output);
        // Template carries the JS-placeholders that the controller substitutes.
        self::assertStringContainsString('__INDEX__', $output);
        self::assertStringContainsString('__NAME__', $output);
        self::assertStringContainsString('__ROLE_SEL_INCIDENT_COMMANDER__', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacro(array $props): string
    {
        $template = '{% import "_components/_fa_team_role_builder.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
