<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLoggerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $requestStack;
    private MockObject $security;
    private AuditLogger $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->security = $this->createMock(Security::class);

        $this->logger = new AuditLogger(
            $this->entityManager,
            $this->requestStack,
            $this->security
        );
    }

    public function testLogCreatePersistsAuditLog(): void
    {
        $this->setupRequest('127.0.0.1', 'TestAgent');
        $this->setupUser('admin@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'create'
                    && $log->getEntityType() === 'Risk'
                    && $log->getEntityId() === 1
                    && $log->getUserName() === 'admin@example.com'
                    && $log->getIpAddress() === '127.0.0.1'
                    && $log->getUserAgent() === 'TestAgent';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->logCreate('Risk', 1, ['name' => 'Test Risk', 'level' => 'high'], 'Created new risk');
    }

    public function testLogUpdateWithChanges(): void
    {
        $this->setupRequest('192.168.1.1', 'Browser');
        $this->setupUser('user@example.com');

        $oldValues = ['name' => 'Old Name', 'level' => 'low'];
        $newValues = ['name' => 'New Name', 'level' => 'high'];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $oldJson = $log->getOldValues();
                $newJson = $log->getNewValues();
                return $log->getAction() === 'update'
                    && str_contains($oldJson, 'Old Name')
                    && str_contains($newJson, 'New Name');
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->logUpdate('Risk', 1, $oldValues, $newValues, 'Updated risk');
    }

    public function testLogUpdateWithNoChanges(): void
    {
        $this->setupRequest('127.0.0.1', 'TestAgent');
        $this->setupUser('admin@example.com');

        $oldValues = ['name' => 'Same Name', 'level' => 'high'];
        $newValues = ['name' => 'Same Name', 'level' => 'high'];

        // Should not persist because there are no changes
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->logger->logUpdate('Risk', 1, $oldValues, $newValues);
    }

    public function testLogDelete(): void
    {
        $this->setupRequest('10.0.0.1', 'CLI');
        $this->setupUser('system');

        $oldValues = ['id' => 5, 'name' => 'Deleted Asset'];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'delete'
                    && $log->getEntityType() === 'Asset'
                    && $log->getEntityId() === 5
                    && str_contains($log->getOldValues(), 'Deleted Asset');
            }));

        $this->logger->logDelete('Asset', 5, $oldValues, 'Asset removed');
    }

    public function testLogView(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('viewer@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'view'
                    && $log->getEntityType() === 'Document'
                    && $log->getEntityId() === 10
                    && $log->getOldValues() === null
                    && $log->getNewValues() === null;
            }));

        $this->logger->logView('Document', 10, 'Viewed sensitive document');
    }

    public function testLogExport(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'export'
                    && $log->getEntityType() === 'RiskReport'
                    && $log->getDescription() === 'Exported risk report to PDF';
            }));

        $this->logger->logExport('RiskReport', null, 'Exported risk report to PDF');
    }

    public function testLogImport(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'import'
                    && $log->getEntityType() === 'Control'
                    && str_contains($log->getNewValues(), '"count":50');
            }));

        $this->logger->logImport('Control', 50, 'Imported controls from CSV');
    }

    public function testLogCustomAction(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getAction() === 'approve'
                    && $log->getEntityType() === 'Workflow'
                    && $log->getEntityId() === 15;
            }));

        $this->logger->logCustom(
            'approve',
            'Workflow',
            15,
            ['status' => 'pending'],
            ['status' => 'approved'],
            'Workflow approved'
        );
    }

    public function testLogCustomActionWithCustomUserName(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getUserName() === 'external-system';
            }));

        $this->logger->logCustom(
            'sync',
            'Asset',
            1,
            null,
            ['synced' => true],
            'Synced from external system',
            'external-system'
        );
    }

    public function testSanitizesSensitiveData(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $newValues = [
            'name' => 'User',
            'password' => 'secret123',
            'token' => 'abc123',
            'apiToken' => 'xyz789',
        ];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $json = $log->getNewValues();
                // Password and token fields should be masked
                return str_contains($json, '"password":"***"')
                    && str_contains($json, '"token":"***"')
                    && str_contains($json, '"apiToken":"***"')
                    && str_contains($json, '"name":"User"');
            }));

        $this->logger->logCreate('User', 1, $newValues);
    }

    public function testConvertsDateTimeObjects(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $dateTime = new \DateTime('2024-06-15 10:30:00');
        $newValues = ['createdAt' => $dateTime, 'name' => 'Test'];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $json = $log->getNewValues();
                return str_contains($json, '2024-06-15 10:30:00');
            }));

        $this->logger->logCreate('Entity', 1, $newValues);
    }

    public function testTruncatesLongStrings(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $longString = str_repeat('x', 2000);
        $newValues = ['description' => $longString];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $json = $log->getNewValues();
                return str_contains($json, '(truncated)')
                    && !str_contains($json, str_repeat('x', 2000));
            }));

        $this->logger->logCreate('Document', 1, $newValues);
    }

    public function testHandlesDateTimeComparison(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $oldDate = new \DateTime('2024-01-01 00:00:00');
        $newDate = new \DateTime('2024-06-01 00:00:00');

        $oldValues = ['lastReview' => $oldDate];
        $newValues = ['lastReview' => $newDate];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $oldJson = $log->getOldValues();
                $newJson = $log->getNewValues();
                return str_contains($oldJson, '2024-01-01')
                    && str_contains($newJson, '2024-06-01');
            }));

        $this->logger->logUpdate('Context', 1, $oldValues, $newValues);
    }

    public function testUsesSystemUserWhenNoUserLoggedIn(): void
    {
        $this->setupRequest('127.0.0.1', 'CLI');
        $this->security->method('getUser')->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getUserName() === 'system';
            }));

        $this->logger->logCreate('Entity', 1, ['name' => 'Test']);
    }

    public function testHandlesNoRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        $this->setupUser('admin@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                return $log->getIpAddress() === null
                    && $log->getUserAgent() === null;
            }));

        $this->logger->logCreate('Entity', 1, ['name' => 'Test']);
    }

    public function testGetEntityTypeName(): void
    {
        $entity = new \App\Entity\Risk();
        $typeName = $this->logger->getEntityTypeName($entity);

        $this->assertSame('Risk', $typeName);
    }

    public function testExtractEntityValuesBasic(): void
    {
        $entity = new \App\Entity\ISMSContext();
        $entity->setOrganizationName('Test Corp');
        $entity->setIsmsScope('Global Scope');

        $values = $this->logger->extractEntityValues($entity);

        $this->assertArrayHasKey('organizationName', $values);
        $this->assertSame('Test Corp', $values['organizationName']);
        $this->assertArrayHasKey('ismsScope', $values);
        $this->assertSame('Global Scope', $values['ismsScope']);
    }

    public function testExtractEntityValuesSkipsCollections(): void
    {
        $entity = new \App\Entity\Risk();

        $values = $this->logger->extractEntityValues($entity);

        // Should not include Doctrine collections
        $this->assertArrayNotHasKey('__initializer__', $values);
        $this->assertArrayNotHasKey('__cloner__', $values);
    }

    public function testLogUpdateWithPartialChanges(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $oldValues = ['name' => 'Same', 'level' => 'low', 'status' => 'open'];
        $newValues = ['name' => 'Same', 'level' => 'high', 'status' => 'closed'];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $oldJson = $log->getOldValues();
                $newJson = $log->getNewValues();
                // Should only contain changed values (level and status)
                return !str_contains($oldJson, '"name"')
                    && str_contains($oldJson, 'low')
                    && str_contains($newJson, 'high')
                    && str_contains($oldJson, 'open')
                    && str_contains($newJson, 'closed');
            }));

        $this->logger->logUpdate('Risk', 1, $oldValues, $newValues);
    }

    public function testConvertsArraysToJson(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');
        $this->setupUser('admin@example.com');

        $newValues = [
            'tags' => ['security', 'compliance', 'audit'],
            'settings' => ['notify' => true, 'priority' => 'high'],
        ];

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (AuditLog $log) {
                $json = $log->getNewValues();
                // Arrays should be JSON encoded as strings
                return str_contains($json, 'security')
                    && str_contains($json, 'compliance');
            }));

        $this->logger->logCreate('Document', 1, $newValues);
    }

    private function setupRequest(string $ip, string $userAgent): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn($ip);

        $headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $headers->method('get')->with('User-Agent')->willReturn($userAgent);
        $request->headers = $headers;

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

    private function setupUser(string $email): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);

        $this->security->method('getUser')->willReturn($user);
    }
}
