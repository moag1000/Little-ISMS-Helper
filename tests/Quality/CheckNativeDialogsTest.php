<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_native_dialogs.py (CI Gate 67:
 * native dialogs + inline JavaScript blocked by the CSP).
 */
final class CheckNativeDialogsTest extends TestCase
{
    private string $root;
    private string $script;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_native_dialogs.py';
    }

    #[Test]
    public function repoIsClean(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript(['--quiet']);

        self::assertSame(0, $exit, "Gate 67 has violations (no baseline).\n{$stdout}\n{$stderr}");
    }

    #[Test]
    public function inlineHandlersJavascriptUrlsAndUnNoncedScriptsAreFlagged(): void
    {
        [$exit, $stdout] = $this->runFixture('html.twig', <<<'TWIG'
            <button type="button" onclick="doIt()">x</button>
            <select name="a"
                    onchange="this.form.submit()"></select>
            {{ _fa_page_header.render({ actions: [{ attrs: { onclick: "go()" } }] }) }}
            {{ btn.link('javascript:history.back()', 'Back') }}
            <script>
                window.x = 1;
            </script>
            <form data-action="submit->ui-actions#confirm"></form>
            TWIG);

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString(':1: inline onclick= handler', $stdout);
        self::assertStringContainsString(':3: inline onchange= handler', $stdout);
        self::assertStringContainsString(':4: inline onclick handler in a Twig attr map', $stdout);
        self::assertStringContainsString(':5: javascript: URL', $stdout);
        self::assertStringContainsString(':6: inline <script> without nonce', $stdout);
        self::assertStringNotContainsString(':9:', $stdout);
    }

    #[Test]
    public function noncedScriptsDataBlocksAndScriptBodiesPass(): void
    {
        [$exit, $stdout] = $this->runFixture('html.twig', <<<'TWIG'
            {# <button onclick="commented()"> #}
            <script nonce="{{ csp_nonce() }}">
                img.onload = () => {};
                const options = { onchange: 'value' };
            </script>
            <script type="application/json" data-x-target="json">{"onclick": "data"}</script>
            <script type="text/php">$pdf->page_script('x');</script>
            <script src="/app.js"></script>
            <button data-action="ui-actions#print" data-online="1">Print</button>
            TWIG);

        self::assertSame(0, $exit, $stdout);
    }

    #[Test]
    public function nativeDialogsAreFlaggedEvenWhenGuarded(): void
    {
        [$exit, $stdout] = $this->runFixture('js', <<<'JS'
            if (typeof window.faConfirm === 'function') {
                window.faConfirm('x');
            } else {
                window.confirm('x');
            }
            alert('y');
            this.confirm();
            faPrompt('ok');
            // prompt('commented')
            JS);

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString(':4: native confirm()', $stdout);
        self::assertStringContainsString(':6: native alert()', $stdout);
        self::assertStringNotContainsString(':7:', $stdout);
        self::assertStringNotContainsString(':8:', $stdout);
        self::assertStringNotContainsString(':9:', $stdout);
    }

    #[Test]
    public function generatedInlineHandlersAndCssImportsInJsAreFlagged(): void
    {
        [$exit, $stdout] = $this->runFixture('js', <<<'JS'
            import TomSelect from 'tom-select';
            import 'tom-select/dist/css/tom-select.default.min.css';
            el.innerHTML = `<button class="x" onclick="run()">x</button>`;
            el.setAttribute('onclick', 'run()');
            el.addEventListener('click', run);
            JS);

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString(':2: CSS import', $stdout);
        self::assertStringContainsString(':3: inline onclick= handler in generated markup', $stdout);
        self::assertStringContainsString(':4: inline event handler set via setAttribute', $stdout);
        self::assertStringNotContainsString(':1:', $stdout);
        self::assertStringNotContainsString(':5:', $stdout);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runFixture(string $extension, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gate67_');
        $file = $tmp . '.' . $extension;
        file_put_contents($file, $content);
        try {
            return $this->runScript(['--paths', $file]);
        } finally {
            @unlink($file);
            @unlink($tmp);
        }
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function runScript(array $args): array
    {
        $proc = proc_open(
            array_merge(['python3', $this->script], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($proc)) {
            self::fail('Could not spawn analyzer');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }
}
