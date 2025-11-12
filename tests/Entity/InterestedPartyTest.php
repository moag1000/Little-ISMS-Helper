<?php

namespace App\Tests\Entity;

use App\Entity\InterestedParty;
use PHPUnit\Framework\TestCase;

class InterestedPartyTest extends TestCase
{
    public function testNewInterestedPartyHasDefaultValues(): void
    {
        $party = new InterestedParty();

        $this->assertNull($party->getId());
        $this->assertNull($party->getTenant());
        $this->assertNull($party->getName());
        $this->assertNull($party->getPartyType());
        $this->assertNull($party->getDescription());
        $this->assertNull($party->getContactPerson());
        $this->assertNull($party->getEmail());
        $this->assertNull($party->getPhone());
        $this->assertEquals('medium', $party->getImportance());
        $this->assertNull($party->getRequirements());
        $this->assertNull($party->getLegalRequirements());
        $this->assertNull($party->getHowAddressed());
        $this->assertEquals('as_needed', $party->getCommunicationFrequency());
        $this->assertNull($party->getCommunicationMethod());
        $this->assertNull($party->getLastCommunication());
        $this->assertNull($party->getNextCommunication());
        $this->assertNull($party->getFeedback());
        $this->assertNull($party->getSatisfactionLevel());
        $this->assertNull($party->getIssues());
        $this->assertInstanceOf(\DateTime::class, $party->getCreatedAt());
        $this->assertNull($party->getUpdatedAt());
    }

    public function testSetAndGetName(): void
    {
        $party = new InterestedParty();
        $party->setName('European Data Protection Authority');

        $this->assertEquals('European Data Protection Authority', $party->getName());
    }

    public function testSetAndGetPartyType(): void
    {
        $party = new InterestedParty();

        $party->setPartyType('regulator');
        $this->assertEquals('regulator', $party->getPartyType());

        $party->setPartyType('customer');
        $this->assertEquals('customer', $party->getPartyType());

        $party->setPartyType('employee');
        $this->assertEquals('employee', $party->getPartyType());
    }

    public function testSetAndGetContactDetails(): void
    {
        $party = new InterestedParty();
        $party->setContactPerson('John Doe');
        $party->setEmail('john.doe@example.com');
        $party->setPhone('+49 123 456789');

        $this->assertEquals('John Doe', $party->getContactPerson());
        $this->assertEquals('john.doe@example.com', $party->getEmail());
        $this->assertEquals('+49 123 456789', $party->getPhone());
    }

    public function testSetAndGetImportance(): void
    {
        $party = new InterestedParty();

        $party->setImportance('critical');
        $this->assertEquals('critical', $party->getImportance());

        $party->setImportance('low');
        $this->assertEquals('low', $party->getImportance());
    }

    public function testSetAndGetRequirements(): void
    {
        $party = new InterestedParty();
        $requirements = 'GDPR compliance, data breach notifications within 72 hours';

        $party->setRequirements($requirements);

        $this->assertEquals($requirements, $party->getRequirements());
    }

    public function testSetAndGetLegalRequirements(): void
    {
        $party = new InterestedParty();
        $legal = ['GDPR Article 33', 'GDPR Article 34', 'NIS2 Directive'];

        $party->setLegalRequirements($legal);

        $this->assertEquals($legal, $party->getLegalRequirements());
    }

    public function testSetAndGetHowAddressed(): void
    {
        $party = new InterestedParty();
        $addressed = 'Implemented ISO 27001 controls, regular audits, incident response procedures';

        $party->setHowAddressed($addressed);

        $this->assertEquals($addressed, $party->getHowAddressed());
    }

    public function testSetAndGetCommunicationFrequency(): void
    {
        $party = new InterestedParty();

        $party->setCommunicationFrequency('monthly');
        $this->assertEquals('monthly', $party->getCommunicationFrequency());

        $party->setCommunicationFrequency('quarterly');
        $this->assertEquals('quarterly', $party->getCommunicationFrequency());
    }

    public function testSetAndGetCommunicationMethod(): void
    {
        $party = new InterestedParty();
        $party->setCommunicationMethod('Email updates, quarterly meetings');

        $this->assertEquals('Email updates, quarterly meetings', $party->getCommunicationMethod());
    }

    public function testSetAndGetCommunicationDates(): void
    {
        $party = new InterestedParty();
        $lastComm = new \DateTime('2024-01-15');
        $nextComm = new \DateTime('2024-04-15');

        $party->setLastCommunication($lastComm);
        $party->setNextCommunication($nextComm);

        $this->assertEquals($lastComm, $party->getLastCommunication());
        $this->assertEquals($nextComm, $party->getNextCommunication());
    }

    public function testSetAndGetFeedback(): void
    {
        $party = new InterestedParty();
        $feedback = 'Satisfied with incident response times';

        $party->setFeedback($feedback);

        $this->assertEquals($feedback, $party->getFeedback());
    }

    public function testSetAndGetSatisfactionLevel(): void
    {
        $party = new InterestedParty();

        $party->setSatisfactionLevel(5);
        $this->assertEquals(5, $party->getSatisfactionLevel());

        $party->setSatisfactionLevel(3);
        $this->assertEquals(3, $party->getSatisfactionLevel());
    }

    public function testSetAndGetIssues(): void
    {
        $party = new InterestedParty();
        $issues = 'Concerned about recent security incidents';

        $party->setIssues($issues);

        $this->assertEquals($issues, $party->getIssues());
    }

    public function testIsCommunicationOverdueReturnsFalseWhenNoNextCommunication(): void
    {
        $party = new InterestedParty();

        $this->assertFalse($party->isCommunicationOverdue());
    }

    public function testIsCommunicationOverdueReturnsTrueWhenPastDue(): void
    {
        $party = new InterestedParty();
        $pastDate = new \DateTime('-1 day');
        $party->setNextCommunication($pastDate);

        $this->assertTrue($party->isCommunicationOverdue());
    }

    public function testIsCommunicationOverdueReturnsFalseWhenNotYetDue(): void
    {
        $party = new InterestedParty();
        $futureDate = new \DateTime('+1 day');
        $party->setNextCommunication($futureDate);

        $this->assertFalse($party->isCommunicationOverdue());
    }

    public function testGetCommunicationStatusReturnsNeverCommunicatedInitially(): void
    {
        $party = new InterestedParty();

        $this->assertEquals('never_communicated', $party->getCommunicationStatus());
    }

    public function testGetCommunicationStatusReturnsOverdueWhenPastDue(): void
    {
        $party = new InterestedParty();
        $party->setLastCommunication(new \DateTime('-30 days'));
        $party->setNextCommunication(new \DateTime('-1 day'));

        $this->assertEquals('overdue', $party->getCommunicationStatus());
    }

    public function testGetCommunicationStatusReturnsDueSoonWhenWithin7Days(): void
    {
        $party = new InterestedParty();
        $party->setLastCommunication(new \DateTime('-30 days'));
        $party->setNextCommunication(new \DateTime('+5 days'));

        $this->assertEquals('due_soon', $party->getCommunicationStatus());
    }

    public function testGetCommunicationStatusReturnsCurrentWhenNotDueSoon(): void
    {
        $party = new InterestedParty();
        $party->setLastCommunication(new \DateTime('-10 days'));
        $party->setNextCommunication(new \DateTime('+30 days'));

        $this->assertEquals('current', $party->getCommunicationStatus());
    }

    public function testGetEngagementScoreReturnsZeroInitially(): void
    {
        $party = new InterestedParty();

        $this->assertEquals(0, $party->getEngagementScore());
    }

    public function testGetEngagementScoreCalculatesFromSatisfaction(): void
    {
        $party = new InterestedParty();
        $party->setSatisfactionLevel(5); // 5/5 * 50 = 50

        $this->assertEquals(50, $party->getEngagementScore());
    }

    public function testGetEngagementScoreIncludesRecentCommunication(): void
    {
        $party = new InterestedParty();
        $party->setSatisfactionLevel(5); // 50 points
        $party->setLastCommunication(new \DateTime('-15 days')); // +30 points (within 30 days)

        $this->assertEquals(80, $party->getEngagementScore());
    }

    public function testGetEngagementScoreIncludesNoIssues(): void
    {
        $party = new InterestedParty();
        $party->setSatisfactionLevel(5); // 50 points
        $party->setLastCommunication(new \DateTime('-15 days')); // +30 points
        $party->setIssues(null); // +20 points (no issues)

        $this->assertEquals(100, $party->getEngagementScore());
    }

    public function testGetEngagementScoreDecreasesWithOldCommunication(): void
    {
        $party = new InterestedParty();
        $party->setSatisfactionLevel(5); // 50 points
        $party->setLastCommunication(new \DateTime('-60 days')); // +20 points (31-90 days)

        $this->assertEquals(70, $party->getEngagementScore());
    }

    public function testGetEngagementScoreDecreasesWithOutstandingIssues(): void
    {
        $party = new InterestedParty();
        $party->setSatisfactionLevel(5); // 50 points
        $party->setLastCommunication(new \DateTime('-15 days')); // +30 points
        $party->setIssues('Some concerns'); // 0 points (has issues)

        $this->assertEquals(80, $party->getEngagementScore());
    }

    public function testInterestedPartyCanStoreCompleteStakeholderProfile(): void
    {
        $party = new InterestedParty();

        $party->setName('Key Customer Corp');
        $party->setPartyType('customer');
        $party->setImportance('critical');
        $party->setRequirements('99.9% uptime, GDPR compliance, 24/7 support');
        $party->setHowAddressed('SLA contracts, ISO 27001 certified, dedicated support team');
        $party->setCommunicationFrequency('monthly');
        $party->setSatisfactionLevel(5);
        $party->setLastCommunication(new \DateTime('-10 days'));
        $party->setNextCommunication(new \DateTime('+20 days'));

        $this->assertEquals('Key Customer Corp', $party->getName());
        $this->assertEquals('customer', $party->getPartyType());
        $this->assertEquals('critical', $party->getImportance());
        $this->assertEquals('current', $party->getCommunicationStatus());
        $this->assertEquals(100, $party->getEngagementScore()); // 50 + 30 + 20
    }
}
