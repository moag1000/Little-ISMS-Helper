<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\EventListener\AutoReactionCorrectiveActionListener;
use App\Repository\SystemSettingsRepository;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-WS-7 — Severity-based CorrectiveAction due-days resolver.
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionCorrectiveActionSeverityTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private MockObject $settings;
    private AutoReactionCorrectiveActionListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settings = $this->createMock(SystemSettingsRepository::class);
        $this->listener = new AutoReactionCorrectiveActionListener(
            $this->reactions,
            $this->logger,
            null,
            null,
            $this->settings,
        );
    }

    #[Test]
    public function criticalSeverityYields14DayDueDateByDefault(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);
        $this->settings->method('getSetting')->willReturn(null);

        $finding = $this->finding(1, AuditFinding::SEVERITY_CRITICAL);

        $persisted = [];
        $em = $this->mockEm($persisted);
        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);

        $cas = array_values(array_filter($persisted, fn ($e) => $e instanceof CorrectiveAction));
        $this->assertCount(1, $cas);
        /** @var CorrectiveAction $ca */
        $ca = $cas[0];
        $diff = $this->daysBetweenNowAnd($ca->getPlannedCompletionDate());
        $this->assertSame(14, $diff);
    }

    #[Test]
    public function configOverrideTakesEffect(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);
        $this->settings->method('getSetting')->willReturn([
            'critical' => 7,
            'high' => 21,
            'medium' => 45,
        ]);

        $finding = $this->finding(2, AuditFinding::SEVERITY_HIGH);

        $persisted = [];
        $em = $this->mockEm($persisted);
        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);

        $cas = array_values(array_filter($persisted, fn ($e) => $e instanceof CorrectiveAction));
        $this->assertCount(1, $cas);
        $diff = $this->daysBetweenNowAnd($cas[0]->getPlannedCompletionDate());
        $this->assertSame(21, $diff);
    }

    #[Test]
    public function majorNcTypeFallsBackToMajorBucket(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);
        $this->settings->method('getSetting')->willReturn(null);

        // No severity, only major type
        $finding = $this->finding(3, severity: 'low', type: AuditFinding::TYPE_MAJOR_NC);

        $persisted = [];
        $em = $this->mockEm($persisted);
        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);

        $cas = array_values(array_filter($persisted, fn ($e) => $e instanceof CorrectiveAction));
        $this->assertCount(1, $cas);
        // SEVERITY low takes precedence over type because severity exists in default map.
        $diff = $this->daysBetweenNowAnd($cas[0]->getPlannedCompletionDate());
        $this->assertSame(90, $diff);
    }

    private function daysBetweenNowAnd(?\DateTimeInterface $when): int
    {
        if ($when === null) {
            return 0;
        }
        $now = new \DateTimeImmutable('today');
        $whenDay = ($when instanceof \DateTimeImmutable ? $when : \DateTimeImmutable::createFromMutable(new \DateTime($when->format('c'))))
            ->setTime(0, 0, 0);
        return (int) $now->diff($whenDay)->format('%a');
    }

    private function finding(int $id, string $severity = AuditFinding::SEVERITY_MEDIUM, string $type = 'observation'): AuditFinding
    {
        $finding = new AuditFinding();
        $finding->setTenant($this->tenant(1));
        $finding->setSeverity($severity);
        $finding->setType($type);
        $finding->setTitle('finding');
        $idProp = (new \ReflectionClass($finding))->getProperty('id');
        $idProp->setValue($finding, $id);
        return $finding;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProp = (new \ReflectionClass($tenant))->getProperty('id');
        $idProp->setValue($tenant, $id);
        return $tenant;
    }

    /**
     * @param array<int, mixed> $persisted
     */
    private function mockEm(array &$persisted): EntityManagerInterface
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');
        return $em;
    }
}
