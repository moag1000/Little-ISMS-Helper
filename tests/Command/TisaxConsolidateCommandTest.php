<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * T2 (from S18): app:tisax:consolidate moved an organisation's assessment onto the matching row
 * of the shared TISAX catalogue — that row then carried the first organisation as upload tenant
 * and its maturity for everybody. The move targets the organisation's own rows only; without
 * one, the organisation's legacy row moves into the canonical framework itself.
 */
final class TisaxConsolidateCommandTest extends KernelTestCase
{
    #[Test]
    public function anOrganisationsAssessmentNeverLandsOnTheSharedCatalogueRow(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(4));
        $org = (new Tenant())->setName('Tisax Org ' . $id)->setCode('tx_' . $id);
        $em->persist($org);
        $factory = new ContractRecordFactory($em);

        $canonical = $this->framework($em, $factory, $org, 'TISAX');
        $legacy = $this->framework($em, $factory, $org, 'TISAX-VDA-ISA-6');
        $shared = $this->requirement($em, $factory, $org, $canonical, '9.9.' . random_int(100, 999), null);
        $own = $this->requirement($em, $factory, $org, $legacy, $shared->getRequirementId(), $org);
        $own->setMaturityCurrent('3');
        $em->flush();
        $sharedId = $shared->getId();
        $ownId = $own->getId();

        $tester = new CommandTester(new Application(self::$kernel)->find('app:tisax:consolidate'));
        $tester->execute(['--force' => true, '--tenant' => (string) $org->getId()]);

        $em->clear();
        $shared = $em->find(ComplianceRequirement::class, $sharedId);
        $own = $em->find(ComplianceRequirement::class, $ownId);
        self::assertNotNull($shared);
        self::assertNull($shared->getUploadTenant(), 'the shared catalogue row belongs to nobody');
        self::assertNull($shared->getMaturityCurrent(), 'no organisation\'s maturity on the shared row');
        self::assertNotNull($own);
        self::assertSame($org->getId(), $own->getUploadTenant()?->getId());
        self::assertSame('TISAX', $own->getFramework()?->getCode(), 'the organisation\'s row moved into the canonical framework');
        self::assertSame('3', $own->getMaturityCurrent());
    }

    private function framework(EntityManagerInterface $em, ContractRecordFactory $factory, Tenant $org, string $code): ComplianceFramework
    {
        $existing = $em->getRepository(ComplianceFramework::class)->findOneBy(['code' => $code]);
        if ($existing instanceof ComplianceFramework) {
            return $existing;
        }
        $framework = $factory->create(ComplianceFramework::class, $org, 'tisax');
        \assert($framework instanceof ComplianceFramework);
        $framework->setCode($code);
        $em->persist($framework);

        return $framework;
    }

    private function requirement(
        EntityManagerInterface $em,
        ContractRecordFactory $factory,
        Tenant $org,
        ComplianceFramework $framework,
        ?string $requirementId,
        ?Tenant $uploadTenant,
    ): ComplianceRequirement {
        $requirement = $factory->create(ComplianceRequirement::class, $org, 'tisax');
        \assert($requirement instanceof ComplianceRequirement);
        $requirement->setFramework($framework)->setRequirementId((string) $requirementId)
            ->setUploadTenant($uploadTenant)->setRequirementSource($uploadTenant !== null ? 'tenant_upload' : 'system')
            ->setMaturityCurrent(null)->setMaturityTarget(null)->setMaturityReviewedAt(null)
            ->setAssessmentValue(null)->setAssessmentStateDp(null)->setDataSourceMapping(null);
        $em->persist($requirement);

        return $requirement;
    }
}
