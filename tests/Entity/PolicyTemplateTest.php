<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PolicyTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PolicyTemplateTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $template = new PolicyTemplate();
        $template->setKey('iso27001.access_control')
            ->setStandard('iso27001')
            ->setTopic('access_control')
            ->setDocumentType('policy')
            ->setNormRef('A.5.15')
            ->setTitleTranslationKey('policy.iso27001.access_control.v1.title')
            ->setBodyTranslationKey('policy.iso27001.access_control.v1.body')
            ->setRequiredVariables([['key' => 'tenant_legal_name', 'type' => 'string', 'label_t_key' => 'wizard.label.legal_name', 'required' => true]])
            ->setLinkedAnnexAControls(['A.5.15', 'A.5.18'])
            ->setLinkedBausteine(['ORP.4'])
            ->setLinkedDoraArticles(['Art. 9.4'])
            ->setAffectedFunctions(['IT_OPERATIONS', 'HR'])
            ->setApprovalChain(['ROLE_CISO', 'ROLE_TOP_MGMT'])
            ->setReviewIntervalMonths(12)
            ->setClimateChangeWording(true)
            ->setDpoSectionRequired(false);

        $this->assertSame('iso27001.access_control', $template->getKey());
        $this->assertSame('iso27001', $template->getStandard());
        $this->assertSame('access_control', $template->getTopic());
        $this->assertSame('policy', $template->getDocumentType());
        $this->assertSame('A.5.15', $template->getNormRef());
        $this->assertSame(['A.5.15', 'A.5.18'], $template->getLinkedAnnexAControls());
        $this->assertSame(['ORP.4'], $template->getLinkedBausteine());
        $this->assertSame(['Art. 9.4'], $template->getLinkedDoraArticles());
        $this->assertSame(['IT_OPERATIONS', 'HR'], $template->getAffectedFunctions());
        $this->assertSame(['ROLE_CISO', 'ROLE_TOP_MGMT'], $template->getApprovalChain());
        $this->assertSame(12, $template->getReviewIntervalMonths());
        $this->assertTrue($template->isClimateChangeWording());
        $this->assertFalse($template->isDpoSectionRequired());
        $this->assertTrue($template->isActive());
        $this->assertSame(1, $template->getVersion());
        $this->assertNotNull($template->getCreatedAt());
        $this->assertNull($template->getUpdatedAt());
        $this->assertNull($template->getSupersededBy());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        // PolicyTemplate is system-shared (no tenant_id) — this test
        // documents that contract by asserting the entity exposes no
        // tenant accessor, while still being instantiable.
        $template = new PolicyTemplate();
        $this->assertFalse(method_exists($template, 'getTenant'),
            'PolicyTemplate is system-shared and must NOT carry a Tenant relation');
        $this->assertFalse(method_exists($template, 'getTenantId'),
            'PolicyTemplate is system-shared and must NOT expose tenantId');
    }

    #[Test]
    public function testVersionIncrementCreatesSupersedesLink(): void
    {
        $v1 = new PolicyTemplate();
        $v1->setKey('iso27001.access_control')
            ->setStandard('iso27001')
            ->setTopic('access_control')
            ->setDocumentType('policy')
            ->setTitleTranslationKey('policy.iso27001.access_control.v1.title')
            ->setBodyTranslationKey('policy.iso27001.access_control.v1.body')
            ->setVersion(1);

        $v2 = new PolicyTemplate();
        $v2->setKey('iso27001.access_control.v2')
            ->setStandard('iso27001')
            ->setTopic('access_control')
            ->setDocumentType('policy')
            ->setTitleTranslationKey('policy.iso27001.access_control.v2.title')
            ->setBodyTranslationKey('policy.iso27001.access_control.v2.body')
            ->setVersion(2);

        // Mark v1 as superseded by v2.
        $v1->setSupersededBy($v2);
        $v1->setIsActive(false);

        $this->assertSame($v2, $v1->getSupersededBy());
        $this->assertSame(1, $v1->getVersion());
        $this->assertSame(2, $v2->getVersion());
        $this->assertFalse($v1->isActive());
        $this->assertTrue($v2->isActive());
        $this->assertNull($v2->getSupersededBy());
    }
}
