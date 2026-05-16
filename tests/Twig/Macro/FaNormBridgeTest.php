<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Audit-S5 P-12 — render tests for `_fa_norm_bridge.html.twig`.
 *
 * The macro reads `app.user.previousQmsBackground` so a real Symfony
 * environment is required. We render through `createTemplate()` and
 * pass `app` as a stub so the macro can resolve the user without going
 * through the full security stack.
 */
final class FaNormBridgeTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersNothingWithoutUser(): void
    {
        $output = $this->render([
            'from_norm' => 'ISO 27001',
            'to_norm' => 'ISO 9001 4.2',
            'bridge_text' => 'Identisch mit Klausel 4.2',
        ], userBackground: null, hasUser: false);

        $this->assertSame('', trim($output));
    }

    #[Test]
    public function rendersNothingForSubtleWithoutMatchingBackground(): void
    {
        $output = $this->render([
            'from_norm' => 'ISO 27001',
            'to_norm' => 'ISO 9001 4.2',
            'bridge_text' => 'Identisch mit Klausel 4.2',
        ], userBackground: 'none');

        $this->assertSame('', trim($output));
    }

    #[Test]
    public function rendersForIso9001BackgroundBySubtleDefault(): void
    {
        $output = $this->render([
            'from_norm' => 'ISO 27001',
            'to_norm' => 'ISO 9001 4.2',
            'bridge_text' => 'Identisch mit Klausel 4.2',
        ], userBackground: 'iso_9001');

        $this->assertStringContainsString('fa-norm-bridge', $output);
        $this->assertStringContainsString('fa-norm-bridge--subtle', $output);
        $this->assertStringContainsString('ISO 27001 → ISO 9001 4.2', $output);
        $this->assertStringContainsString('Identisch mit Klausel 4.2', $output);
    }

    #[Test]
    public function alwaysVisibleRendersWithoutUser(): void
    {
        $output = $this->render([
            'from_norm' => 'ISO 27001',
            'to_norm' => 'ISO 9001 8.5.6',
            'bridge_text' => 'Externe Ursache',
            'prominence' => 'always_visible',
        ], userBackground: null, hasUser: false);

        $this->assertStringContainsString('fa-norm-bridge--always_visible', $output);
        $this->assertStringContainsString('Externe Ursache', $output);
    }

    #[Test]
    public function rendersForIso14001Background(): void
    {
        $output = $this->render([
            'from_norm' => 'ISO 27001',
            'to_norm' => 'ISO 14001',
            'bridge_text' => 'Umwelt-Aspekt',
        ], userBackground: 'iso_14001');

        $this->assertStringContainsString('Umwelt-Aspekt', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, ?string $userBackground, bool $hasUser = true): string
    {
        $template = <<<'TWIG'
            {% import "_components/_fa_norm_bridge.html.twig" as m %}
            {{ m.render(props) }}
        TWIG;

        // `app` is a Twig *global*, not a normal template variable. Macros
        // only see globals, so we have to override it via the existing
        // app-globals object provided by Symfony's TwigBundle.
        $user = $hasUser
            ? (object) ['previousQmsBackground' => $userBackground]
            : null;

        $previousAppGlobal = $this->twig->getGlobals()['app'] ?? null;
        $this->twig->addGlobal('app', (object) ['user' => $user]);

        try {
            return $this->twig->createTemplate($template)->render(['props' => $props]);
        } finally {
            if ($previousAppGlobal !== null) {
                $this->twig->addGlobal('app', $previousAppGlobal);
            }
        }
    }
}
