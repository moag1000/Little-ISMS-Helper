<?php

namespace App\Tests\Entity;

use App\Entity\BusinessContinuityPlan;
use App\Entity\CrisisTeam;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class CrisisTeamTest extends TestCase
{
    public function testConstructor(): void
    {
        $team = new CrisisTeam();

        $this->assertNotNull($team->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $team->getCreatedAt());
        $this->assertEquals(0, $team->getBusinessContinuityPlans()->count());
        $this->assertEquals([], $team->getMembers());
        $this->assertEquals([], $team->getEmergencyContacts());
        $this->assertEquals([], $team->getAvailableResources());
        $this->assertEquals([], $team->getDocumentation());
    }

    public function testGettersAndSetters(): void
    {
        $team = new CrisisTeam();

        $team->setTeamName('Primary Crisis Team');
        $this->assertEquals('Primary Crisis Team', $team->getTeamName());

        $team->setDescription('Main crisis management team');
        $this->assertEquals('Main crisis management team', $team->getDescription());

        $team->setPrimaryPhone('+49 123 456789');
        $this->assertEquals('+49 123 456789', $team->getPrimaryPhone());

        $team->setPrimaryEmail('crisis@example.com');
        $this->assertEquals('crisis@example.com', $team->getPrimaryEmail());

        $team->setMeetingLocation('Building A, Room 101');
        $this->assertEquals('Building A, Room 101', $team->getMeetingLocation());

        $team->setBackupMeetingLocation('Building B, Room 202');
        $this->assertEquals('Building B, Room 202', $team->getBackupMeetingLocation());

        $team->setVirtualMeetingUrl('https://teams.microsoft.com/meeting/123');
        $this->assertEquals('https://teams.microsoft.com/meeting/123', $team->getVirtualMeetingUrl());

        $team->setAlertProcedures('Call team leader, activate phone tree');
        $this->assertEquals('Call team leader, activate phone tree', $team->getAlertProcedures());

        $team->setDecisionAuthority('Team leader has final authority');
        $this->assertEquals('Team leader has final authority', $team->getDecisionAuthority());

        $team->setCommunicationProtocols('Hourly status updates required');
        $this->assertEquals('Hourly status updates required', $team->getCommunicationProtocols());

        $team->setTrainingSchedule('Quarterly exercises');
        $this->assertEquals('Quarterly exercises', $team->getTrainingSchedule());

        $team->setNotes('Additional information');
        $this->assertEquals('Additional information', $team->getNotes());
    }

    public function testTeamTypeGetterAndSetter(): void
    {
        $team = new CrisisTeam();

        // Default value
        $this->assertEquals('operational', $team->getTeamType());

        $team->setTeamType('strategic');
        $this->assertEquals('strategic', $team->getTeamType());

        $team->setTeamType('technical');
        $this->assertEquals('technical', $team->getTeamType());

        $team->setTeamType('communication');
        $this->assertEquals('communication', $team->getTeamType());
    }

    public function testIsActiveGetterAndSetter(): void
    {
        $team = new CrisisTeam();

        // Default value
        $this->assertTrue($team->isActive());

        $team->setIsActive(false);
        $this->assertFalse($team->isActive());

        $team->setIsActive(true);
        $this->assertTrue($team->isActive());
    }

    public function testTeamLeaderRelationship(): void
    {
        $team = new CrisisTeam();
        $leader = new User();
        $leader->setEmail('leader@example.com');

        $this->assertNull($team->getTeamLeader());

        $team->setTeamLeader($leader);
        $this->assertSame($leader, $team->getTeamLeader());

        $team->setTeamLeader(null);
        $this->assertNull($team->getTeamLeader());
    }

    public function testDeputyLeaderRelationship(): void
    {
        $team = new CrisisTeam();
        $deputy = new User();
        $deputy->setEmail('deputy@example.com');

        $this->assertNull($team->getDeputyLeader());

        $team->setDeputyLeader($deputy);
        $this->assertSame($deputy, $team->getDeputyLeader());

        $team->setDeputyLeader(null);
        $this->assertNull($team->getDeputyLeader());
    }

    public function testTenantRelationship(): void
    {
        $team = new CrisisTeam();
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $this->assertNull($team->getTenant());

        $team->setTenant($tenant);
        $this->assertSame($tenant, $team->getTenant());
    }

    public function testMembersManagement(): void
    {
        $team = new CrisisTeam();

        $this->assertEquals([], $team->getMembers());

        $members = [
            [
                'user_id' => 1,
                'name' => 'John Doe',
                'role' => 'Security Expert',
                'contact' => '+49 111 222333',
                'responsibilities' => 'IT Security Analysis',
            ],
            [
                'user_id' => 2,
                'name' => 'Jane Smith',
                'role' => 'Communications Lead',
                'contact' => '+49 444 555666',
                'responsibilities' => 'External communications',
            ],
        ];

        $team->setMembers($members);
        $this->assertEquals($members, $team->getMembers());

        // Add individual member
        $newMember = [
            'user_id' => 3,
            'name' => 'Bob Johnson',
            'role' => 'Technical Lead',
            'contact' => '+49 777 888999',
            'responsibilities' => 'System recovery',
        ];

        $team->addMember($newMember);
        $this->assertCount(3, $team->getMembers());

        // Remove member by index
        $team->removeMember(1);
        $this->assertCount(2, $team->getMembers());
        $this->assertEquals('John Doe', $team->getMembers()[0]['name']);
        $this->assertEquals('Bob Johnson', $team->getMembers()[1]['name']);
    }

    public function testGetMemberCount(): void
    {
        $team = new CrisisTeam();

        $this->assertEquals(0, $team->getMemberCount());

        $team->addMember(['user_id' => 1, 'name' => 'Member 1']);
        $this->assertEquals(1, $team->getMemberCount());

        $team->addMember(['user_id' => 2, 'name' => 'Member 2']);
        $team->addMember(['user_id' => 3, 'name' => 'Member 3']);
        $this->assertEquals(3, $team->getMemberCount());
    }

    public function testEmergencyContactsGetterAndSetter(): void
    {
        $team = new CrisisTeam();

        $this->assertEquals([], $team->getEmergencyContacts());

        $contacts = [
            'fire_department' => '112',
            'police' => '110',
            'facility_manager' => '+49 123 456789',
        ];

        $team->setEmergencyContacts($contacts);
        $this->assertEquals($contacts, $team->getEmergencyContacts());
    }

    public function testAvailableResourcesGetterAndSetter(): void
    {
        $team = new CrisisTeam();

        $this->assertEquals([], $team->getAvailableResources());

        $resources = [
            'laptops' => 5,
            'phones' => 10,
            'emergency_kits' => 3,
        ];

        $team->setAvailableResources($resources);
        $this->assertEquals($resources, $team->getAvailableResources());
    }

    public function testDocumentationGetterAndSetter(): void
    {
        $team = new CrisisTeam();

        $this->assertEquals([], $team->getDocumentation());

        $docs = [
            'procedures' => '/path/to/procedures.pdf',
            'contact_list' => '/path/to/contacts.xlsx',
        ];

        $team->setDocumentation($docs);
        $this->assertEquals($docs, $team->getDocumentation());
    }

    public function testDateTimeFields(): void
    {
        $team = new CrisisTeam();

        $this->assertNull($team->getLastActivatedAt());
        $this->assertNull($team->getLastTrainingAt());
        $this->assertNull($team->getNextTrainingAt());

        $lastActivated = new \DateTimeImmutable('2024-01-15 10:00:00');
        $team->setLastActivatedAt($lastActivated);
        $this->assertEquals($lastActivated, $team->getLastActivatedAt());

        $lastTraining = new \DateTimeImmutable('2024-02-01 14:00:00');
        $team->setLastTrainingAt($lastTraining);
        $this->assertEquals($lastTraining, $team->getLastTrainingAt());

        $nextTraining = new \DateTimeImmutable('2024-05-01 14:00:00');
        $team->setNextTrainingAt($nextTraining);
        $this->assertEquals($nextTraining, $team->getNextTrainingAt());
    }

    public function testTimestamps(): void
    {
        $team = new CrisisTeam();

        $this->assertNotNull($team->getCreatedAt());
        $this->assertNull($team->getUpdatedAt());

        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $team->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $team->getCreatedAt());

        $updatedAt = new \DateTimeImmutable('2024-01-02 15:00:00');
        $team->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $team->getUpdatedAt());
    }

    public function testAddAndRemoveBusinessContinuityPlan(): void
    {
        $team = new CrisisTeam();
        $plan = new BusinessContinuityPlan();

        $this->assertEquals(0, $team->getBusinessContinuityPlans()->count());

        $team->addBusinessContinuityPlan($plan);
        $this->assertEquals(1, $team->getBusinessContinuityPlans()->count());
        $this->assertTrue($team->getBusinessContinuityPlans()->contains($plan));

        // Adding the same plan again should not increase count
        $team->addBusinessContinuityPlan($plan);
        $this->assertEquals(1, $team->getBusinessContinuityPlans()->count());

        $team->removeBusinessContinuityPlan($plan);
        $this->assertEquals(0, $team->getBusinessContinuityPlans()->count());
        $this->assertFalse($team->getBusinessContinuityPlans()->contains($plan));
    }

    public function testIsTrainingOverdue(): void
    {
        $team = new CrisisTeam();

        // No next training date set
        $this->assertFalse($team->isTrainingOverdue());

        // Next training date in the future
        $team->setNextTrainingAt(new \DateTimeImmutable('+30 days'));
        $this->assertFalse($team->isTrainingOverdue());

        // Next training date in the past
        $team->setNextTrainingAt(new \DateTimeImmutable('-30 days'));
        $this->assertTrue($team->isTrainingOverdue());

        // Next training date is exactly now (may be slightly past by comparison time)
        // We'll test with tomorrow to ensure it's clearly not overdue
        $team->setNextTrainingAt(new \DateTimeImmutable('+1 day'));
        $this->assertFalse($team->isTrainingOverdue());
    }

    public function testGetDaysSinceLastTraining(): void
    {
        $team = new CrisisTeam();

        // No training date set
        $this->assertNull($team->getDaysSinceLastTraining());

        // Training was 30 days ago
        $team->setLastTrainingAt(new \DateTimeImmutable('-30 days'));
        $this->assertGreaterThanOrEqual(29, $team->getDaysSinceLastTraining());
        $this->assertLessThanOrEqual(31, $team->getDaysSinceLastTraining());

        // Training was today
        $team->setLastTrainingAt(new \DateTimeImmutable('today'));
        $this->assertEquals(0, $team->getDaysSinceLastTraining());
    }

    public function testGetTeamTypeDisplayName(): void
    {
        $team = new CrisisTeam();

        $team->setTeamType('operational');
        $this->assertEquals('Operativer Krisenstab', $team->getTeamTypeDisplayName());

        $team->setTeamType('strategic');
        $this->assertEquals('Strategischer Krisenstab', $team->getTeamTypeDisplayName());

        $team->setTeamType('technical');
        $this->assertEquals('Technischer Krisenstab', $team->getTeamTypeDisplayName());

        $team->setTeamType('communication');
        $this->assertEquals('Krisenkommunikation', $team->getTeamTypeDisplayName());

        $team->setTeamType('unknown_type');
        $this->assertEquals('Unbekannt', $team->getTeamTypeDisplayName());
    }

    public function testIsProperlyConfigured(): void
    {
        $team = new CrisisTeam();

        // Not properly configured by default (missing required fields)
        $this->assertFalse($team->isProperlyConfigured());

        // Add team leader
        $leader = new User();
        $team->setTeamLeader($leader);
        $this->assertFalse($team->isProperlyConfigured()); // Still missing other fields

        // Add members
        $team->addMember(['user_id' => 1, 'name' => 'Member 1']);
        $this->assertFalse($team->isProperlyConfigured()); // Still missing contact info

        // Add primary phone
        $team->setPrimaryPhone('+49 123 456789');
        $this->assertFalse($team->isProperlyConfigured()); // Still missing email

        // Add primary email - now properly configured
        $team->setPrimaryEmail('crisis@example.com');
        $this->assertTrue($team->isProperlyConfigured());
    }

    public function testIsProperlyConfiguredWithAllFields(): void
    {
        $team = new CrisisTeam();

        $leader = new User();
        $leader->setEmail('leader@example.com');

        $team->setTeamLeader($leader);
        $team->addMember(['user_id' => 1, 'name' => 'John Doe', 'role' => 'Expert']);
        $team->setPrimaryPhone('+49 123 456789');
        $team->setPrimaryEmail('crisis@example.com');

        $this->assertTrue($team->isProperlyConfigured());
    }

    public function testIsProperlyConfiguredWithEmptyMembers(): void
    {
        $team = new CrisisTeam();

        $leader = new User();
        $team->setTeamLeader($leader);
        $team->setPrimaryPhone('+49 123 456789');
        $team->setPrimaryEmail('crisis@example.com');

        // Members array is empty
        $this->assertFalse($team->isProperlyConfigured());
    }
}
