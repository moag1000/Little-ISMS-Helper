<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PolicyAcknowledgementTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $ack = new PolicyAcknowledgement();
        $tenant = new Tenant();
        $document = new Document();
        $user = new User();

        $ack->setTenant($tenant)
            ->setDocument($document)
            ->setUser($user)
            ->setAcknowledgementMethod('web_click')
            ->setDocumentVersion('v3')
            ->setIpAddress('203.0.113.42');

        $this->assertSame($tenant, $ack->getTenant());
        $this->assertSame($document, $ack->getDocument());
        $this->assertSame($user, $ack->getUser());
        $this->assertSame('web_click', $ack->getAcknowledgementMethod());
        $this->assertSame('v3', $ack->getDocumentVersion());
        $this->assertSame('203.0.113.42', $ack->getIpAddress());
        $this->assertNotNull($ack->getAcknowledgedAt());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        $ack = new PolicyAcknowledgement();
        $tenant = new Tenant();
        $tenant->setName('ACME GmbH');

        $ack->setTenant($tenant);

        $this->assertSame($tenant, $ack->getTenant());
        $this->assertNull($ack->getTenantId(),
            'in-memory tenant has null id; getTenantId proxies safely');
    }

    #[Test]
    public function testUniqueConstraintFiveTuple(): void
    {
        // The (tenant, document, user, documentVersion) UNIQUE constraint
        // is asserted at the schema level via the migration's
        // uq_policy_acknowledgement_tenant_doc_user_ver index. At the
        // entity level, this test documents that all five components of
        // the uniqueness contract are independently mutable getters/setters
        // so a service can construct the lookup tuple without reflection.
        $ack = new PolicyAcknowledgement();
        $tenant = new Tenant();
        $document = new Document();
        $user = new User();

        $ack->setTenant($tenant)
            ->setDocument($document)
            ->setUser($user)
            ->setDocumentVersion('v1')
            ->setAcknowledgementMethod('email_token');

        // All four uniqueness components must round-trip.
        $this->assertSame($tenant, $ack->getTenant());
        $this->assertSame($document, $ack->getDocument());
        $this->assertSame($user, $ack->getUser());
        $this->assertSame('v1', $ack->getDocumentVersion());

        // A re-version captures a different documentVersion → conceptually
        // a new row in the unique-tuple space.
        $ack2 = new PolicyAcknowledgement();
        $ack2->setTenant($tenant)
            ->setDocument($document)
            ->setUser($user)
            ->setDocumentVersion('v2')
            ->setAcknowledgementMethod('email_token');

        $this->assertNotSame($ack->getDocumentVersion(), $ack2->getDocumentVersion(),
            'a re-version does not collide with the existing acknowledgement under the unique tuple');
    }
}
