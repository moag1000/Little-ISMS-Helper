<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\ManagementDecision;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Decisions of top management (ISO 27001 5.1, 9.3.3; NIS-2 Art. 20): pending → approved | rejected | withdrawn; approved → superseded. */
final class ManagementDecisionWorkflowTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/management_decision.yaml';

    #[Test]
    public function aNewDecisionIsPendingAndVersioned(): void
    {
        $decision = new ManagementDecision();

        self::assertSame('pending', $decision->getStatus());
        self::assertTrue($decision->isPending());
        self::assertSame(0, $decision->getLockVersion());
    }

    #[Test]
    public function slugIsRegistered(): void
    {
        $entry = (new EntityTypeRegistry())->lookup('management-decision');

        self::assertNotNull($entry);
        self::assertSame(ManagementDecision::class, $entry['class']);
        self::assertSame('management_decision_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function onlyTopManagementDecidesAndRejectionNeedsAReason(): void
    {
        $transitions = $this->config()['transitions'];

        self::assertSame(['ROLE_TOP_MGMT'], $transitions['approve']['metadata']['roles']);
        self::assertSame(['ROLE_TOP_MGMT'], $transitions['reject']['metadata']['roles']);
        self::assertTrue($transitions['reject']['metadata']['reason_required']);
        self::assertSame('approved', $transitions['supersede']['from']);
        self::assertSame([], $transitions['supersede']['metadata']['roles'], 'superseding is done by the service only');
    }

    #[Test]
    public function workflowIsImported(): void
    {
        self::assertStringContainsString(
            'workflows/management_decision.yaml',
            (string) file_get_contents(__DIR__ . '/../../config/packages/workflow.yaml'),
        );
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return Yaml::parseFile(self::YAML_PATH)['framework']['workflows']['management_decision_lifecycle'];
    }
}
