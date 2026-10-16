<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\AccessReviewCampaign;
use App\Entity\DocumentSection;
use App\Entity\SupplierQuestionnaire;
use App\Entity\ThreatLedPenetrationTest;
use App\Entity\TransferImpactAssessment;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Tests\Lifecycle\Support\YamlWorkflowLifecycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Smoke tests for the five staged-status entities that moved onto the
 * lifecycle (DocumentSection, TransferImpactAssessment,
 * ThreatLedPenetrationTest, SupplierQuestionnaire, AccessReviewCampaign),
 * plus the registry contract the PHPStan rule NoDirectSetStatusRule relies
 * on.
 */
final class LifecycleCompletionTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** @return iterable<string, array{string, class-string, string, string}> */
    public static function entities(): iterable
    {
        yield 'document section' => ['document-section', DocumentSection::class, 'document_section_lifecycle', 'draft'];
        yield 'transfer impact assessment' => ['transfer-impact-assessment', TransferImpactAssessment::class, 'transfer_impact_assessment_lifecycle', 'draft'];
        yield 'TLPT' => ['threat-led-penetration-test', ThreatLedPenetrationTest::class, 'threat_led_penetration_test_lifecycle', 'planned'];
        yield 'supplier questionnaire' => ['supplier-questionnaire', SupplierQuestionnaire::class, 'supplier_questionnaire_lifecycle', 'draft'];
        yield 'access review campaign' => ['access-review-campaign', AccessReviewCampaign::class, 'access_review_campaign_lifecycle', 'open'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entities')]
    public function entityIsRegisteredImportedAndStartsAtInitialMarking(
        string $slug,
        string $class,
        string $workflow,
        string $initial,
    ): void {
        $entry = (new EntityTypeRegistry())->lookup($slug);
        self::assertSame(['class' => $class, 'workflow' => $workflow], $entry);
        self::assertContains($class, EntityTypeRegistry::managedEntityClasses());

        $config = $this->workflowConfig($workflow);
        self::assertSame($initial, $config['initial_marking']);
        self::assertContains($class, $config['supports']);
        self::assertSame(['type' => 'method', 'property' => 'status'], $config['marking_store']);

        $entity = new $class();
        self::assertSame($initial, $entity->getStatus(), 'entity default must equal initial_marking');
        self::assertSame(0, $entity->getLockVersion(), 'lifecycle entities need #[ORM\Version] lockVersion');
    }

    #[Test]
    public function everyWorkflowSubjectIsInTheRegistry(): void
    {
        // NoDirectSetStatusRule derives its entity list from the registry, so
        // an entity with a workflow YAML but no registry entry would escape it.
        // ComplianceMapping is global catalogue data: it must not be reachable
        // through the tenant-overridable generic transition endpoint, so it
        // is deliberately left out (ComplianceMappingController enforces the
        // static YAML roles itself).
        $managed = [...EntityTypeRegistry::managedEntityClasses(), \App\Entity\ComplianceMapping::class];
        $missing = [];
        foreach (glob(self::ROOT . '/config/workflows/*.yaml') ?: [] as $file) {
            foreach (Yaml::parseFile($file)['framework']['workflows'] ?? [] as $name => $wf) {
                foreach ($wf['supports'] ?? [] as $class) {
                    if (!in_array($class, $managed, true)) {
                        $missing[] = $name . ' → ' . $class;
                    }
                }
            }
        }
        self::assertSame([], $missing);
    }

    #[Test]
    public function tlptRunsThroughDoraStagesAndCancelNeedsReason(): void
    {
        $lifecycle = new YamlWorkflowLifecycle();
        $tlpt = new ThreatLedPenetrationTest();
        foreach (['start_scoping', 'start_red_team', 'start_reporting'] as $t) {
            $lifecycle->transition($tlpt, 'threat_led_penetration_test_lifecycle', $t);
        }
        self::assertSame('reporting', $tlpt->getStatus());

        $this->expectException(ReasonRequiredException::class);
        $lifecycle->transition($tlpt, 'threat_led_penetration_test_lifecycle', 'cancel');
    }

    #[Test]
    public function questionnaireCannotBeCompletedBeforeItWasSent(): void
    {
        $this->expectException(InvalidTransitionException::class);
        (new YamlWorkflowLifecycle())->transition(new SupplierQuestionnaire(), 'supplier_questionnaire_lifecycle', 'complete');
    }

    #[Test]
    public function sectionAndQuestionnaireCompletionAreClosedToTheGenericEndpoint(): void
    {
        // Empty roles = LifecycleVoter denies the generic transition endpoint;
        // only the owning service (with its own checks) moves these.
        foreach ($this->workflowConfig('document_section_lifecycle')['transitions'] as $name => $t) {
            self::assertSame([], $t['metadata']['roles'], 'document_section.' . $name);
        }
        self::assertSame([], $this->workflowConfig('supplier_questionnaire_lifecycle')['transitions']['complete']['metadata']['roles']);
    }

    #[Test]
    public function industryPresetsDoNotDictateDocumentStatus(): void
    {
        // New preset documents start at the document_lifecycle initial
        // marking; a preset must not be able to seed them as `published`.
        foreach (glob(self::ROOT . '/fixtures/library/presets/*.yaml') ?: [] as $file) {
            foreach (Yaml::parseFile($file)['initial_documents'] ?? [] as $doc) {
                self::assertArrayNotHasKey('status', $doc, basename($file) . ': ' . ($doc['title'] ?? '?'));
            }
        }
        foreach (['src/Controller/Admin/IndustryPresetController.php', 'src/Command/LoadIndustryPresetCommand.php'] as $file) {
            self::assertStringNotContainsString('$doc->setStatus(', (string) file_get_contents(self::ROOT . '/' . $file));
        }
    }

    /** @return array<string, mixed> */
    private function workflowConfig(string $workflow): array
    {
        $imports = Yaml::parseFile(self::ROOT . '/config/packages/workflow.yaml')['imports'];
        foreach ($imports as $import) {
            $parsed = Yaml::parseFile(self::ROOT . '/config/packages/' . $import['resource']);
            if (isset($parsed['framework']['workflows'][$workflow])) {
                return $parsed['framework']['workflows'][$workflow];
            }
        }
        self::fail(sprintf('Workflow %s is not imported in config/packages/workflow.yaml', $workflow));
    }
}
