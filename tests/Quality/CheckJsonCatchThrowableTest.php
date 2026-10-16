<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_json_catch_throwable.py (CI Gate 63).
 */
final class CheckJsonCatchThrowableTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_json_catch_throwable.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/json_catch_throwable.txt';
    }

    #[Test]
    public function repoIsCleanAgainstBaseline(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript(['--baseline', $this->baseline, '--quiet']);

        self::assertSame(0, $exit, "Repo regressed against the baseline.\n{$stdout}\n{$stderr}");
    }

    #[Test]
    public function exceptionCatchInJsonActionIsFlagged(): void
    {
        [$exit, $stdout] = $this->runFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController {
                public function save(): JsonResponse {
                    try {
                        return $this->json(['ok' => true]);
                    } catch (\Exception $e) {
                        return $this->json(['ok' => false], 500);
                    }
                }
            }
            PHP);

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString('save()', $stdout);
    }

    #[Test]
    public function multiCatchNamingExceptionIsFlagged(): void
    {
        [$exit] = $this->runFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController {
                public function save() {
                    try {
                        return new JsonResponse([]);
                    } catch (InvalidArgumentException | Exception) {
                        return new JsonResponse([], 500);
                    }
                }
            }
            PHP);

        self::assertSame(1, $exit);
    }

    #[Test]
    public function throwableSpecificTypesHtmlActionsAndCommentsPass(): void
    {
        [$exit, $stdout] = $this->runFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController {
                public function save(): JsonResponse {
                    // a comment mentioning catch (Exception) must not count
                    try {
                        return $this->json([]);
                    } catch (InvalidArgumentException $e) {
                        return $this->json([], 400);
                    } catch (\Throwable $e) {
                        return $this->json([], 500);
                    }
                }
                public function page(): Response {
                    try {
                        return $this->render('x.html.twig');
                    } catch (\Exception $e) {
                        return $this->redirectToRoute('home');
                    }
                }
                public function nested(): JsonResponse {
                    try {
                        return $this->json([]);
                    } catch (\Foo\Exception $e) {
                        return $this->json([], 500);
                    }
                }
            }
            PHP);

        self::assertSame(0, $exit, $stdout);
    }

    #[Test]
    public function annotationSilencesViolation(): void
    {
        [$exit, $stdout] = $this->runFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            class _SynthController {
                public function save(): JsonResponse {
                    try {
                        return $this->json([]);
                    // @catch-exception-intentional: errors must reach the kernel here
                    } catch (\Exception $e) {
                        return $this->json([], 500);
                    }
                }
            }
            PHP);

        self::assertSame(0, $exit, $stdout);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runFixture(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jsoncatch_') . 'Controller.php';
        file_put_contents($tmp, $content);
        try {
            return $this->runScript(['--paths', $tmp]);
        } finally {
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
