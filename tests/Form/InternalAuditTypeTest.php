<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\InternalAudit;
use App\Form\InternalAuditType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * S4 P-15 DataReuse — InternalAuditType form contract:
 *  - structured leadAuditorUser + leadAuditorPerson EntityType slots
 *  - typed auditTeamMembers Multi-Select (Collection<Person>)
 *  - legacy leadAuditor + auditTeam text fields still present (migration)
 *
 * Uses KernelTestCase so EntityType has a real Doctrine ManagerRegistry.
 */
final class InternalAuditTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    #[Test]
    public function structuredLeadAuditorSlotsExist(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        self::assertTrue($form->has('leadAuditorUser'), 'Pattern-A: leadAuditorUser slot must exist');
        self::assertTrue($form->has('leadAuditorPerson'), 'Pattern-A: leadAuditorPerson slot must exist');
    }

    #[Test]
    public function legacyLeadAuditorTextFieldStillPresent(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        self::assertTrue(
            $form->has('leadAuditor'),
            'Legacy text field must remain for migration display',
        );
    }

    #[Test]
    public function auditTeamMembersMultiSelectExists(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        self::assertTrue($form->has('auditTeamMembers'), 'P-15: typed auditTeamMembers must be present');
        $cfg = $form->get('auditTeamMembers')->getConfig();
        self::assertTrue($cfg->getOption('multiple'), 'auditTeamMembers must be a multi-select');
    }

    #[Test]
    public function legacyAuditTeamTextareaStillPresent(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        self::assertTrue($form->has('auditTeam'), 'Legacy auditTeam textarea must remain (migration display)');
    }

    #[Test]
    public function leadAuditorIsNoLongerHardRequiredOnLegacyField(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        $required = $form->get('leadAuditor')->getConfig()->getOption('required');
        self::assertFalse((bool) $required, 'Legacy leadAuditor must not be hard-required — Callback enforces "at least one".');
    }

    /**
     * C5-04 — `scopedAssets` is exposed as an EntityType multi-select wired
     * to the `conditional-fields` Stimulus controller (data-depends-on=scopeType,
     * value=asset). ISO 19011 Cl. 5.5.2: audit scope must enumerate audited
     * objects when restricted to specific assets.
     */
    #[Test]
    public function scopedAssetsMultiSelectExistsWithConditionalToggle(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        self::assertTrue($form->has('scopedAssets'), 'C5-04: scopedAssets multi-select must be exposed');

        $cfg = $form->get('scopedAssets')->getConfig();
        self::assertTrue($cfg->getOption('multiple'), 'scopedAssets must be a multi-select (M2M)');

        $attr = $cfg->getOption('attr');
        self::assertArrayHasKey('data-depends-on', $attr, 'scopedAssets must carry the conditional-fields depends-on attr');
        self::assertSame('internal_audit_scopeType', $attr['data-depends-on']);
        self::assertSame('asset', $attr['data-depends-on-value'], 'Toggle visible only when scopeType=asset');
    }

    /**
     * C5-04 — The trigger field `scopeType` must expose the stable id
     * `internal_audit_scopeType` that the conditional-fields controller uses.
     */
    #[Test]
    public function scopeTypeCarriesStableIdForConditionalFieldsTrigger(): void
    {
        $form = $this->formFactory->create(InternalAuditType::class, new InternalAudit());

        $attr = $form->get('scopeType')->getConfig()->getOption('attr');
        self::assertArrayHasKey('id', $attr, 'scopeType must declare an explicit id for conditional-fields trigger lookup');
        self::assertSame('internal_audit_scopeType', $attr['id']);
    }
}
