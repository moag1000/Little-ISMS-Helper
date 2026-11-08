<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Security\Voter\GlobalCatalogueVoter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * S3 (third assessment): an organisation admin downgraded the shared ISO → NIS-2 mappings
 * and lowered every other organisation's fulfilment. With several organisations only the
 * super admin writes global mappings; with one, nothing changes for anyone.
 */
final class GlobalCatalogueVoterTest extends TestCase
{
    #[Test]
    public function severalOrganisationsNeedTheSuperAdmin(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(superAdmin: false, tenants: 3, subject: $this->globalMapping()));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(superAdmin: false, tenants: 2, subject: null));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(superAdmin: true, tenants: 3, subject: $this->globalMapping()));
    }

    #[Test]
    public function aSingleOrganisationKeepsTheScreensRules(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(superAdmin: false, tenants: 1, subject: $this->globalMapping()));
    }

    #[Test]
    public function anOrganisationsOwnMappingIsNotGlobal(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setUploadTenant(new Tenant());
        $own = (new ComplianceMapping())->setSourceRequirement($requirement);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(superAdmin: false, tenants: 5, subject: $own));
    }

    private function globalMapping(): ComplianceMapping
    {
        return (new ComplianceMapping())
            ->setSourceRequirement(new ComplianceRequirement())
            ->setTargetRequirement(new ComplianceRequirement());
    }

    private function vote(bool $superAdmin, int $tenants, ?ComplianceMapping $subject): int
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => $attribute === 'ROLE_SUPER_ADMIN' && $superAdmin,
        );
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn((string) $tenants);
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('tenant');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($metadata);

        return (new GlobalCatalogueVoter($security, $em))
            ->vote(new NullToken(), $subject, [GlobalCatalogueVoter::WRITE]);
    }
}
