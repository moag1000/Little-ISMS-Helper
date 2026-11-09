<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\MappingGapItem;
use App\Entity\Tenant;
use App\EventListener\GlobalMappingWriteGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Whatever screen, import, API resource or assistant tool changes a shared mapping:
 * the flush stops it for a signed-in user without the global-catalogue right (S3).
 */
final class GlobalMappingWriteGuardTest extends TestCase
{
    #[Test]
    public function aSignedInUserWithoutTheRightCannotFlushAGlobalMapping(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->flush(signedIn: true, granted: false);
    }

    #[Test]
    public function theRightOrNoSignedInUserLetsTheFlushPass(): void
    {
        $this->flush(signedIn: true, granted: true);
        $this->flush(signedIn: false, granted: false);
        $this->flush(signedIn: true, granted: false, inRequest: false);

        $this->addToAssertionCount(3);
    }

    /** S13: the rest of the shared catalogue — loaders, seeds, presets, gap items. */
    #[Test]
    public function sharedCatalogueRowsAreRefusedWithoutTheRight(): void
    {
        $globalMapping = (new ComplianceMapping())
            ->setSourceRequirement(new ComplianceRequirement())
            ->setTargetRequirement(new ComplianceRequirement());
        $gap = new MappingGapItem();
        $gap->setMapping($globalMapping);
        $cases = [
            'new shared requirement' => [[new ComplianceRequirement()], [], []],
            'deleted shared requirement' => [[], [], [new ComplianceRequirement()]],
            'changed framework' => [[], [new ComplianceFramework()], []],
            'deleted framework' => [[], [], [new ComplianceFramework()]],
            'gap of a global mapping' => [[], [$gap], []],
        ];
        foreach ($cases as $label => [$inserts, $updates, $deletes]) {
            try {
                $this->flush(signedIn: true, granted: false, inserts: $inserts, updates: $updates, deletes: $deletes);
                self::fail($label . ' passed the backstop');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
            $this->flush(signedIn: true, granted: true, inserts: $inserts, updates: $updates, deletes: $deletes);
        }
    }

    /** S18: an organisation's Reifegrad never lands on the shared requirement row. */
    #[Test]
    public function maturityOnASharedRequirementIsRefusedWithoutTheRight(): void
    {
        foreach (['maturityCurrent', 'maturityTarget', 'maturityReviewedAt'] as $field) {
            try {
                $this->flush(signedIn: true, granted: false, updates: [new ComplianceRequirement()], changeSet: [$field => [null, 'x']]);
                self::fail($field . ' on a shared requirement passed the backstop');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        $own = (new ComplianceRequirement())->setUploadTenant(new Tenant());
        $this->flush(signedIn: true, granted: false, updates: [$own], changeSet: ['maturityCurrent' => [null, 'managed']]);
    }

    #[Test]
    public function rowsOfTheOrganisationPass(): void
    {
        $own = (new ComplianceRequirement())->setUploadTenant(new Tenant());
        // A text update of a shared requirement passes (its screens guard it).
        $this->flush(signedIn: true, granted: false, inserts: [$own], updates: [new ComplianceRequirement()], deletes: [$own]);
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<object>|null $inserts
     * @param list<object>|null $updates
     * @param list<object>|null $deletes
     */
    private function flush(
        bool $signedIn,
        bool $granted,
        bool $inRequest = true,
        ?array $inserts = null,
        ?array $updates = null,
        ?array $deletes = null,
        array $changeSet = [],
    ): void {
        $mapping = (new ComplianceMapping())
            ->setSourceRequirement(new ComplianceRequirement())
            ->setTargetRequirement(new ComplianceRequirement());

        $security = $this->createStub(Security::class);
        $security->method('getToken')->willReturn($signedIn ? new NullToken() : null);
        $security->method('isGranted')->willReturn($granted);

        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($inserts ?? []);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates ?? [$mapping]);
        $uow->method('getScheduledEntityDeletions')->willReturn($deletes ?? []);
        $uow->method('getEntityChangeSet')->willReturn($changeSet);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $requestStack = new RequestStack();
        if ($inRequest) {
            $requestStack->push(new Request());
        }

        (new GlobalMappingWriteGuard($security, $requestStack))->onFlush(new OnFlushEventArgs($em));
    }
}
