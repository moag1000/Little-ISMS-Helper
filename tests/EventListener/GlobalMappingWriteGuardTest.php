<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\EventListener\GlobalMappingWriteGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
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

        $this->addToAssertionCount(2);
    }

    private function flush(bool $signedIn, bool $granted): void
    {
        $mapping = (new ComplianceMapping())
            ->setSourceRequirement(new ComplianceRequirement())
            ->setTargetRequirement(new ComplianceRequirement());

        $security = $this->createStub(Security::class);
        $security->method('getToken')->willReturn($signedIn ? new NullToken() : null);
        $security->method('isGranted')->willReturn($granted);

        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$mapping]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        (new GlobalMappingWriteGuard($security))->onFlush(new OnFlushEventArgs($em));
    }
}
