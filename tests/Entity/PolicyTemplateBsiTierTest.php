<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PolicyTemplate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W5-A — PolicyTemplate BSI tier extensions.
 *
 * Validates the new `bsiTier` enum + `linkedBsiBausteine` JSON column
 * added by Version20260508150000_policy_wizard_w5_bsi_template_extensions:
 *
 *   • getter/setter happy path for all three tier values + null
 *   • enum boundary (invalid tier rejected with InvalidArgumentException)
 *   • linkedBsiBausteine round-trip through the entity
 */
final class PolicyTemplateBsiTierTest extends TestCase
{
    #[Test]
    public function testBsiTierGetterSetter(): void
    {
        $template = new PolicyTemplate();

        // Default is null (template applies regardless of tier).
        self::assertNull($template->getBsiTier());

        $template->setBsiTier(PolicyTemplate::BSI_TIER_BASIS);
        self::assertSame('basis', $template->getBsiTier());

        $template->setBsiTier(PolicyTemplate::BSI_TIER_STANDARD);
        self::assertSame('standard', $template->getBsiTier());

        $template->setBsiTier(PolicyTemplate::BSI_TIER_KERN);
        self::assertSame('kern', $template->getBsiTier());

        // Re-setting to null is allowed (e.g. when reclassifying a row).
        $template->setBsiTier(null);
        self::assertNull($template->getBsiTier());

        // Constants exposed for downstream consumers.
        self::assertSame('basis', PolicyTemplate::BSI_TIER_BASIS);
        self::assertSame('standard', PolicyTemplate::BSI_TIER_STANDARD);
        self::assertSame('kern', PolicyTemplate::BSI_TIER_KERN);
        self::assertSame(['basis', 'standard', 'kern'], PolicyTemplate::BSI_TIERS);
    }

    #[Test]
    public function testBsiTierEnumBoundaryRejectsUnknownValues(): void
    {
        $template = new PolicyTemplate();

        $rejected = ['BASIS', 'standard ', 'core', 'kern_full', '', 'high'];
        foreach ($rejected as $bad) {
            try {
                $template->setBsiTier($bad);
                self::fail(sprintf('expected InvalidArgumentException for tier "%s"', $bad));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Unknown BSI tier', $e->getMessage());
                self::assertStringContainsString($bad, $e->getMessage());
            }
        }

        // Confirm the entity state was not mutated by the failed sets.
        self::assertNull($template->getBsiTier());
    }

    #[Test]
    public function testLinkedBsiBausteineJsonRoundTrip(): void
    {
        $template = new PolicyTemplate();

        // Default is null.
        self::assertNull($template->getLinkedBsiBausteine());

        $anchors = ['ISMS.1.A4', 'ISMS.1.A5', 'ORP.4.A1', 'ORP.4.A22', 'DER.4.A4'];
        $template->setLinkedBsiBausteine($anchors);

        $hydrated = $template->getLinkedBsiBausteine();
        self::assertSame($anchors, $hydrated, 'list ordering must round-trip');
        self::assertCount(5, $hydrated);

        // Empty array is preserved (distinct from null — caller may want
        // to express "no anchors but field has been set").
        $template->setLinkedBsiBausteine([]);
        self::assertSame([], $template->getLinkedBsiBausteine());

        // Null clears the column.
        $template->setLinkedBsiBausteine(null);
        self::assertNull($template->getLinkedBsiBausteine());

        // Distinct from `linkedBausteine` (root-level Baustein-IDs).
        $template->setLinkedBsiBausteine(['ORP.4.A1', 'ORP.4.A22']);
        $template->setLinkedBausteine(['ORP.4']);
        self::assertSame(['ORP.4.A1', 'ORP.4.A22'], $template->getLinkedBsiBausteine());
        self::assertSame(['ORP.4'], $template->getLinkedBausteine());
    }
}
