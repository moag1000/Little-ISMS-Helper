<?php

declare(strict_types=1);

namespace App\Tests\System;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class WorkflowDumpTest extends KernelTestCase
{
    public function testDocumentLifecycleDumpsToGraphviz(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $exit = $tester->run(['command' => 'workflow:dump', 'name' => 'document_lifecycle']);

        $this->assertSame(0, $exit, 'workflow:dump should exit 0');
        $output = $tester->getDisplay();
        $this->assertStringContainsString('digraph workflow', $output);
        $this->assertStringContainsString('draft', $output);
        $this->assertStringContainsString('archived', $output);
        $this->assertStringContainsString('submit_for_review', $output);
    }

    /**
     * Lifecycle X.1 — ProcessingActivity state-machine dump.
     */
    public function testProcessingActivityLifecycleDumpsToGraphviz(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $exit = $tester->run(['command' => 'workflow:dump', 'name' => 'processing_activity_lifecycle']);

        $this->assertSame(0, $exit, 'workflow:dump should exit 0 for processing_activity_lifecycle');
        $output = $tester->getDisplay();
        $this->assertStringContainsString('digraph workflow', $output);
        $this->assertStringContainsString('draft', $output);
        $this->assertStringContainsString('published', $output);
        $this->assertStringContainsString('archived', $output);
        $this->assertStringContainsString('submit_for_review', $output);
    }

    /**
     * Lifecycle X.1 — ISMSObjective state-machine dump.
     */
    public function testIsmsObjectiveLifecycleDumpsToGraphviz(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $exit = $tester->run(['command' => 'workflow:dump', 'name' => 'isms_objective_lifecycle']);

        $this->assertSame(0, $exit, 'workflow:dump should exit 0 for isms_objective_lifecycle');
        $output = $tester->getDisplay();
        $this->assertStringContainsString('digraph workflow', $output);
        $this->assertStringContainsString('not_started', $output);
        $this->assertStringContainsString('in_progress', $output);
        $this->assertStringContainsString('achieved', $output);
        $this->assertStringContainsString('start', $output);
    }
}
