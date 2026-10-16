<?php

declare(strict_types=1);

namespace App\Tests\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for scripts/quality/check_module_gating.py.
 *
 * Wraps the analyzer in a subprocess and asserts:
 *  1. With the baseline applied, the repo is clean (regression guard for
 *     newly-added regulatory fields without a module-gate).
 *  2. On a synthetic violation snippet, the analyzer exits 1.
 *  3. Annotated violations (// @no-module-gate-required) are silenced.
 *  4. Controller pass: routed actions serving an optional module (DORA, LkSG,
 *     training, ...) need #[RequiresModule], checkModuleActive() or an
 *     annotation.
 */
final class CheckModuleGatingTest extends TestCase
{
    private string $script;
    private string $baseline;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/scripts/quality/check_module_gating.py';
        $this->baseline = $this->root . '/scripts/quality/baselines/module_gating.txt';

        if (!is_file($this->script)) {
            $this->markTestSkipped('check_module_gating.py not present');
        }
    }

    #[Test]
    public function repoIsCleanAgainstBaseline(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript([
            '--baseline', $this->baseline, '--quiet',
        ]);
        $this->assertSame(
            0,
            $exit,
            "Repo regressed against module-gating baseline.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
        );
    }

    #[Test]
    public function syntheticUngatedFieldIsFlagged(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth1Type {
                public function buildForm($builder, $options): void {
                    $builder->add('doraRelevance', TextType::class);
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(1, $exit, "Expected exit 1 for ungated DORA field, got {$exit}.\n{$stdout}");
            $this->assertStringContainsString('doraRelevance', $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function gatedFieldIsAccepted(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth2Type {
                public function buildForm($builder, $options): void {
                    if ($this->isModuleActive('nis2_dora')) {
                        $builder->add('doraRelevance', TextType::class);
                    }
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function annotationSuppressesViolation(): void
    {
        $tmp = $this->writeFixture(<<<'PHP'
            <?php
            namespace App\Form;
            class _Synth3Type {
                public function buildForm($builder, $options): void {
                    // @no-module-gate-required: abstract base class
                    $builder->add('doraRelevance', TextType::class);
                }
            }
            PHP);
        try {
            [$exit] = $this->runScript(['--paths', $tmp]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function syntheticUngatedControllerRouteIsFlagged(): void
    {
        $tmp = $this->writeControllerFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            #[IsGranted('ROLE_MANAGER')]
            final class SynthDoraExportController extends AbstractController
            {
                #[Route('/dora/register/export.csv', name: 'app_synth_dora_export', methods: ['GET'])]
                public function export(): Response
                {
                    return new Response('');
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--controller-paths', $tmp]);
            $this->assertSame(1, $exit, "Expected exit 1 for ungated DORA route, got {$exit}.\n{$stdout}");
            $this->assertStringContainsString('SynthDoraExportController::export', $stdout);
            $this->assertStringContainsString('nis2_dora', $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function invokableControllerWithClassRouteIsFlagged(): void
    {
        $tmp = $this->writeControllerFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            #[Route('/dashboards/bcm', name: 'app_synth_bcm')]
            final class SynthBcmDashboardController extends AbstractController
            {
                public function __invoke(): Response
                {
                    return new Response('');
                }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--controller-paths', $tmp]);
            $this->assertSame(1, $exit, $stdout);
            $this->assertStringContainsString('bcm', $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function classLevelRequiresModuleAcceptsAllActions(): void
    {
        $tmp = $this->writeControllerFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            #[RequiresModule('training')]
            final class SynthTrainingController extends AbstractController
            {
                #[Route('/training', name: 'app_synth_training_index')]
                public function index(): Response { return new Response(''); }

                #[Route('/training/{id}/edit', name: 'app_synth_training_edit')]
                public function edit(): Response { return new Response(''); }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--controller-paths', $tmp]);
            $this->assertSame(0, $exit, $stdout);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function methodLevelGateMustMatchTheRoutesModule(): void
    {
        $tmp = $this->writeControllerFixture(<<<'PHP'
            <?php
            namespace App\Controller;
            final class SynthIncidentController extends AbstractController
            {
                #[Route('/incident/{id}/bcm-impact', name: 'app_synth_bcm_impact')]
                #[RequiresModule('bcm')]
                public function bcmImpact(): Response { return new Response(''); }

                #[Route('/incident/{id}/nis2-report.pdf', name: 'app_synth_nis2_report')]
                public function nis2Report(): Response
                {
                    if ($redirect = $this->checkModuleActive('nis2_dora')) {
                        return $redirect;
                    }
                    return new Response('');
                }

                #[Route('/incident/{id}/gdpr', name: 'app_synth_gdpr')]
                #[RequiresModule('bcm')]
                public function gdpr(): Response { return new Response(''); }

                // @no-module-gate-required: redirect alias only
                #[Route('/incident/lksg-alias', name: 'app_synth_lksg_alias')]
                public function alias(): Response { return new Response(''); }
            }
            PHP);
        try {
            [$exit, $stdout] = $this->runScript(['--controller-paths', $tmp]);
            $this->assertSame(1, $exit, $stdout);
            $this->assertStringContainsString('SynthIncidentController::gdpr', $stdout);
            $this->assertStringNotContainsString('SynthIncidentController::bcmImpact', $stdout);
            $this->assertStringNotContainsString('SynthIncidentController::nis2Report', $stdout);
            $this->assertStringNotContainsString('SynthIncidentController::alias', $stdout);
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
        $cmd = array_merge(['python3', $this->script], $args);
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($proc)) {
            $this->fail('Could not spawn analyzer');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, $stdout, $stderr];
    }

    private function writeControllerFixture(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gatingcontroller_') . 'Controller.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }

    private function writeFixture(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gatingfixture_') . 'Type.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }
}
