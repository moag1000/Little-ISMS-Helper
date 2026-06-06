<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning\Source;

use App\Entity\ActionItem;
use App\Entity\SourceConversionConfig;
use App\Entity\Tenant;
use App\Repository\ActionItemReferenceRepository;
use App\Repository\SourceConversionConfigRepository;
use App\Service\ModuleConfigurationService;
use App\Service\Planning\Source\ActionItemConversionService;
use App\Service\Planning\Source\SourceAdapter;
use App\Service\Planning\Source\SourceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionItemConversionServiceTest extends TestCase
{
    private function adapter(string $slug, object $item, bool $completed = false): SourceAdapter
    {
        return new class($slug, $item, $completed) implements SourceAdapter {
            public function __construct(
                private string $slug,
                private object $item,
                private bool $completed,
            ) {
            }
            public function slug(): string { return $this->slug; }
            public function label(): string { return 'Test'; }
            public function requiredModule(): ?string { return null; }
            public function findConvertible(Tenant $tenant): iterable { return [$this->item]; }
            public function dueDateOf(object $item): ?\DateTimeInterface { return new \DateTimeImmutable('2026-02-01'); }
            public function titleOf(object $item): string { return 'Converted item'; }
            public function isCompleted(object $item): bool { return $this->completed; }
            public function ownsRecurrence(): bool { return false; }
            public function refId(object $item): int { return 99; }
        };
    }

    private function config(string $slug, bool $enabled, int $offset = 0, ?string $effort = null): SourceConversionConfig
    {
        return (new SourceConversionConfig())
            ->setSourceSlug($slug)
            ->setEnabled($enabled)
            ->setDueOffsetDays($offset)
            ->setDefaultEffortPt($effort);
    }

    #[Test]
    public function disabledSourceCreatesNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $configRepo = $this->createStub(SourceConversionConfigRepository::class);
        $configRepo->method('findForTenantKeyedBySlug')->willReturn(['foo' => $this->config('foo', false)]);

        $service = new ActionItemConversionService(
            new SourceAdapterRegistry([$this->adapter('foo', new \stdClass())]),
            $configRepo,
            $this->createStub(ActionItemReferenceRepository::class),
            $this->createStub(ModuleConfigurationService::class),
            $em,
        );

        $this->assertSame([], $service->convertForTenant(new Tenant()));
    }

    #[Test]
    public function enabledSourceCreatesActionItemWithOffsetAndProvenance(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->expects($this->once())->method('flush');

        $configRepo = $this->createStub(SourceConversionConfigRepository::class);
        $configRepo->method('findForTenantKeyedBySlug')->willReturn(['foo' => $this->config('foo', true, 7, '2.0')]);

        $refRepo = $this->createStub(ActionItemReferenceRepository::class);
        $refRepo->method('existsForTarget')->willReturn(false);

        $module = $this->createStub(ModuleConfigurationService::class);
        $module->method('isModuleActive')->willReturn(true);

        $service = new ActionItemConversionService(
            new SourceAdapterRegistry([$this->adapter('foo', new \stdClass())]),
            $configRepo,
            $refRepo,
            $module,
            $em,
        );

        $created = $service->convertForTenant(new Tenant());

        $this->assertSame(['foo' => 1], $created);
        $this->assertCount(1, $persisted);
        $item = $persisted[0];
        $this->assertInstanceOf(ActionItem::class, $item);
        $this->assertSame('foo', $item->getOrigin());
        $this->assertSame('Converted item', $item->getTitle());
        $this->assertSame('2.0', $item->getPlannedEffortPt());
        // 2026-02-01 + 7 days.
        $this->assertSame('2026-02-08', $item->getDueDate()?->format('Y-m-d'));
        $this->assertCount(1, $item->getReferences());
        $ref = $item->getReferences()->first();
        $this->assertSame('foo', $ref->getRefType());
        $this->assertSame(99, $ref->getRefId());
    }

    #[Test]
    public function alreadyReferencedTargetIsSkippedIdempotently(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $configRepo = $this->createStub(SourceConversionConfigRepository::class);
        $configRepo->method('findForTenantKeyedBySlug')->willReturn(['foo' => $this->config('foo', true)]);

        $refRepo = $this->createStub(ActionItemReferenceRepository::class);
        $refRepo->method('existsForTarget')->willReturn(true);

        $module = $this->createStub(ModuleConfigurationService::class);
        $module->method('isModuleActive')->willReturn(true);

        $service = new ActionItemConversionService(
            new SourceAdapterRegistry([$this->adapter('foo', new \stdClass())]),
            $configRepo,
            $refRepo,
            $module,
            $em,
        );

        $this->assertSame([], $service->convertForTenant(new Tenant()));
    }

    #[Test]
    public function completedSourceItemIsSkipped(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $configRepo = $this->createStub(SourceConversionConfigRepository::class);
        $configRepo->method('findForTenantKeyedBySlug')->willReturn(['foo' => $this->config('foo', true)]);

        $refRepo = $this->createStub(ActionItemReferenceRepository::class);
        $refRepo->method('existsForTarget')->willReturn(false);

        $module = $this->createStub(ModuleConfigurationService::class);
        $module->method('isModuleActive')->willReturn(true);

        $service = new ActionItemConversionService(
            new SourceAdapterRegistry([$this->adapter('foo', new \stdClass(), completed: true)]),
            $configRepo,
            $refRepo,
            $module,
            $em,
        );

        $this->assertSame([], $service->convertForTenant(new Tenant()));
    }
}
