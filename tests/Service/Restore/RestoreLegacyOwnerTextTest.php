<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Service\Owner\OwnerTextResolver;
use App\Service\Restore\RestoreEntityWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An organisation backup written before the free-text owner columns were
 * dropped carries the owner as text ("contactPerson": "Max Muster"). On
 * restore it goes to the User/Person slot of the record's organisation.
 */
final class RestoreLegacyOwnerTextTest extends TestCase
{
    private function restore(OwnerTextResolver $resolver, object $entity, string $entityName, array $data): void
    {
        $writer = new RestoreEntityWriter(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $resolver,
        );
        (new \ReflectionMethod($writer, 'restoreLegacyOwnerText'))->invoke($writer, $entity, $entityName, $data);
    }

    #[Test]
    public function theOldTextKeyIsAssignedToTheOwnerSlots(): void
    {
        $tenant = new Tenant();
        $supplier = (new Supplier())->setTenant($tenant);

        $resolver = $this->createMock(OwnerTextResolver::class);
        $resolver->expects(self::once())->method('assign')
            ->with($supplier, 'contactPersonUser', 'contactPersonRef', $tenant, 'Max Muster');

        $this->restore($resolver, $supplier, 'Supplier', ['name' => 'CloudCo', 'contactPerson' => 'Max Muster']);
    }

    #[Test]
    public function aBackupWithoutTheOldKeyOrAnUnknownEntityIsLeftAlone(): void
    {
        $resolver = $this->createMock(OwnerTextResolver::class);
        $resolver->expects(self::never())->method('assign');

        $this->restore($resolver, (new Supplier())->setTenant(new Tenant()), 'Supplier', ['name' => 'CloudCo']);
        $this->restore($resolver, (new Supplier())->setTenant(new Tenant()), 'Supplier', ['contactPerson' => '  ']);
        $this->restore($resolver, new \stdClass(), 'Unknown', ['owner' => 'Max']);
    }
}
