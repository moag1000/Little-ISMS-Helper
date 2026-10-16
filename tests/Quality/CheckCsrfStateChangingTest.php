<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_csrf_state_changing.py (CI gate).
 */
final class CheckCsrfStateChangingTest extends TestCase
{
    private string $root;
    private string $script;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_csrf_state_changing.py';
        if (trim((string) shell_exec('command -v python3')) === '') {
            self::markTestSkipped('python3 not available');
        }
    }

    #[Test]
    public function repoIsCleanAgainstBaseline(): void
    {
        [$exit, $out] = $this->run_(['--baseline', $this->root . '/scripts/quality/baselines/csrf_state_changing.txt', '--quiet']);
        self::assertSame(0, $exit, $out);
    }

    #[Test]
    public function unprotectedPostActionIsFlagged(): void
    {
        [$exit, $out] = $this->scanFixture(<<<'PHP'
            <?php
            class _CsrfSynthController {
                #[Route('/x/delete', name: 'x_delete', methods: ['POST'])]
                public function delete(): void
                {
                    $this->service->deleteEverything();
                }
            }
            PHP);
        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('::delete', $out);
    }

    #[Test]
    public function literalPlaceholderInStaticTokenIdIsFlagged(): void
    {
        [$exit, $out] = $this->scanFixture(<<<'PHP'
            <?php
            class _CsrfSynthLiteral {
                #[Route('/{id}/delete', name: 'x_delete', methods: ['POST'])]
                #[IsCsrfTokenValid('x_delete_{id}')]
                public function delete(): void
                {
                }
            }
            PHP);
        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('::delete#literal-id', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function protectedVariants(): iterable
    {
        yield 'attribute' => ["#[IsCsrfTokenValid('x')]\n    #[Route('/x', name: 'x', methods: ['POST'])]\n    public function a(): void { \$this->s->go(); }"];
        yield 'expression id' => ["#[IsCsrfTokenValid(new Expression('\"x_\" ~ args[\"e\"].getId()'))]\n    #[Route('/{id}', name: 'x', methods: ['POST'])]\n    public function a(): void { \$this->s->go(); }"];
        yield 'inline check' => ["#[Route('/x', name: 'x', methods: ['PATCH'])]\n    public function a(): void { if (!\$this->isCsrfTokenValid('x', \$t)) { return; } }"];
        yield 'symfony form' => ["#[Route('/x', name: 'x', methods: ['GET', 'POST'])]\n    public function a(): void { \$f = \$this->createForm(X::class); }"];
        yield 'exempt marker' => ["// @csrf-exempt: pure computation\n    #[Route('/x', name: 'x', methods: ['POST'])]\n    public function a(): void { \$this->calc(); }"];
        yield 'read-only GET' => ["#[Route('/x', name: 'x', methods: ['GET'])]\n    public function a(): void { \$this->s->go(); }"];
        yield 'checked in private helper' => ["#[Route('/x', name: 'x', methods: ['POST'])]\n    public function a(): void { \$this->guard(); }\n\n    private function guard(): void { \$this->isCsrfTokenValid('x', ''); }"];
    }

    #[Test]
    #[DataProvider('protectedVariants')]
    public function protectedOrExemptActionsPass(string $method): void
    {
        [$exit, $out] = $this->scanFixture("<?php\nclass _CsrfSynthOk {\n    {$method}\n}\n");
        self::assertSame(0, $exit, $out);
    }

    /** @return array{int, string} */
    private function scanFixture(string $php): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csrf_gate_') . '.php';
        file_put_contents($tmp, $php);
        try {
            return $this->run_(['--paths', $tmp]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function run_(array $args): array
    {
        $cmd = array_merge(['python3', $this->script], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), (string) $out];
    }
}
