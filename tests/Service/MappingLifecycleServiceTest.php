<?php

namespace App\Tests\Service;

use App\Entity\ComplianceMapping;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\MappingLifecycleService;
use App\Service\MappingQualityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class MappingLifecycleServiceTest extends TestCase
{
    private function makeService(): MappingLifecycleService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $log = $this->createMock(AuditLogger::class);
        $mqs = $this->createMock(MappingQualityScoreService::class);
        return new MappingLifecycleService($em, $log, $mqs);
    }

    private function makeUser(array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles($roles);
        return $user;
    }

    private function makeRichMapping(): ComplianceMapping
    {
        return (new ComplianceMapping())
            ->setProvenanceSource('ENISA')
            ->setMethodologyType('text_comparison_with_expert_review')
            ->setMethodologyDescription('Beschreibung.')
            ->setMappingRationale('Begründung.');
    }

    #[Test]
    public function testValidTransitionDraftToReview(): void
    {
        $svc = $this->makeService();
        $mapping = $this->makeRichMapping()->setLifecycleState('draft');
        $svc->transition($mapping, 'review', $this->makeUser());
        $this->assertSame('review', $mapping->getLifecycleState());
    }

    #[Test]
    public function testInvalidTransitionDraftToApproved(): void
    {
        $svc = $this->makeService();
        $mapping = $this->makeRichMapping()->setLifecycleState('draft');
        $this->expectException(\DomainException::class);
        $svc->transition($mapping, 'approved', $this->makeUser());
    }

    #[Test]
    public function testPublishRequiresCisoRole(): void
    {
        $svc = $this->makeService();
        $mapping = $this->makeRichMapping()->setLifecycleState('approved');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/ROLE_CISO/');
        $svc->transition($mapping, 'published', $this->makeUser(['ROLE_USER']));
    }

    #[Test]
    public function testPublishWithCisoSucceeds(): void
    {
        $svc = $this->makeService();
        $mapping = $this->makeRichMapping()->setLifecycleState('approved');
        $svc->transition($mapping, 'published', $this->makeUser(['ROLE_CISO']));
        $this->assertSame('published', $mapping->getLifecycleState());
    }

    #[Test]
    public function testApprovedRequiresProvenanceAndMethodology(): void
    {
        $svc = $this->makeService();
        $bare = (new ComplianceMapping())->setLifecycleState('review');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/missing required fields/');
        $svc->transition($bare, 'approved', $this->makeUser());
    }

    #[Test]
    public function testDeprecatedFromAnyState(): void
    {
        $svc = $this->makeService();
        // ROLE_ADMIN nötig
        $admin = $this->makeUser(['ROLE_ADMIN']);

        foreach (['draft', 'review', 'approved', 'published'] as $from) {
            $mapping = $this->makeRichMapping()->setLifecycleState($from);
            $svc->transition($mapping, 'deprecated', $admin);
            $this->assertSame('deprecated', $mapping->getLifecycleState());
        }
    }

    #[Test]
    public function testAllowedNextStates(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['review', 'deprecated'], $svc->allowedNextStates('draft'));
        $this->assertSame(['published', 'review', 'deprecated'], $svc->allowedNextStates('approved'));
        $this->assertSame([], $svc->allowedNextStates('deprecated'));
    }
}
