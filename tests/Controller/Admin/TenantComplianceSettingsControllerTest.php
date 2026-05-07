<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Form\DataTransformer\JsonArrayTransformer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * KernelTestCase coverage for Tier-1/2/3 compliance-settings persistence.
 *
 * Boots the kernel + Doctrine to verify column-mapping and round-trip
 * for the 15 new Tenant fields plus the JsonArrayTransformer used in
 * TenantComplianceSettingsType for 5 JSON-blob fields. Uses
 * KernelTestCase rather than WebTestCase because the latter is blocked
 * by an unrelated form-trait conflict in RiskType (PHP 8.5 trait
 * composition tightening).
 */
final class TenantComplianceSettingsControllerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->tenant = (new Tenant())
            ->setCode('cs-' . $suffix)
            ->setName('Compliance Settings Tenant ' . $suffix);
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen() && $this->tenant !== null && $this->tenant->getId() !== null) {
            try {
                $reload = $this->em->find(Tenant::class, $this->tenant->getId());
                if ($reload) {
                    $this->em->remove($reload);
                    $this->em->flush();
                }
            } catch (\Throwable) {
                // best-effort
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function tier1FieldsPersist(): void
    {
        $this->tenant
            ->setLocale('de_DE')
            ->setTimezone('Europe/Berlin')
            ->setFinancialYearStartMonth(1)
            ->setTlpDefault('amber')
            ->setDpoContactName('Jane DPO')
            ->setDpoContactEmail('dpo@example.test')
            ->setSupervisoryAuthorities([['name' => 'BfDI', 'jurisdiction' => 'DE-BUND']])
            ->setDataRetentionPolicies([['category' => 'access_logs', 'days' => 365]]);
        $this->em->flush();
        $this->em->clear();

        $reload = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($reload);
        self::assertSame('de_DE', $reload->getLocale());
        self::assertSame('Europe/Berlin', $reload->getTimezone());
        self::assertSame(1, $reload->getFinancialYearStartMonth());
        self::assertSame('amber', $reload->getTlpDefault());
        self::assertSame('Jane DPO', $reload->getDpoContactName());
        self::assertSame('dpo@example.test', $reload->getDpoContactEmail());
        self::assertSame([['name' => 'BfDI', 'jurisdiction' => 'DE-BUND']], $reload->getSupervisoryAuthorities());
        self::assertSame([['category' => 'access_logs', 'days' => 365]], $reload->getDataRetentionPolicies());
    }

    #[Test]
    public function tier2FieldsPersist(): void
    {
        $this->tenant
            ->setRiskMethodology('iso_27005')
            ->setRiskMatrixSize(5)
            ->setWizardMaturityTarget('enhanced')
            ->setNotificationPreferences(['incident_high' => 'email,sms'])
            ->setCsirtEndpoints(['bsi' => 'reports@bsi.bund.de'])
            ->setCrisisTeamOnCall([['name' => 'Alice', 'phone' => '+49-1234']]);
        $this->em->flush();
        $this->em->clear();

        $reload = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($reload);
        self::assertSame('iso_27005', $reload->getRiskMethodology());
        self::assertSame(5, $reload->getRiskMatrixSize());
        self::assertSame('enhanced', $reload->getWizardMaturityTarget());
        self::assertSame(['incident_high' => 'email,sms'], $reload->getNotificationPreferences());
        self::assertSame(['bsi' => 'reports@bsi.bund.de'], $reload->getCsirtEndpoints());
        self::assertSame([['name' => 'Alice', 'phone' => '+49-1234']], $reload->getCrisisTeamOnCall());
    }

    #[Test]
    public function tier3ApiRateLimitPersists(): void
    {
        $this->tenant->setApiRateLimitPerMinute(2400);
        $this->em->flush();
        $this->em->clear();

        $reload = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($reload);
        self::assertSame(2400, $reload->getApiRateLimitPerMinute());
    }

    #[Test]
    public function jsonArrayTransformerRoundTripsArray(): void
    {
        $transformer = new JsonArrayTransformer();
        $input = [['name' => 'BfDI', 'jurisdiction' => 'DE-BUND']];

        $json = $transformer->transform($input);
        self::assertJson($json);

        $back = $transformer->reverseTransform($json);
        self::assertSame($input, $back);
    }

    #[Test]
    public function jsonArrayTransformerEmptyToNull(): void
    {
        $transformer = new JsonArrayTransformer();
        self::assertSame('', $transformer->transform(null));
        self::assertSame('', $transformer->transform([]));
        self::assertNull($transformer->reverseTransform(''));
        self::assertNull($transformer->reverseTransform('   '));
    }

    #[Test]
    public function jsonArrayTransformerInvalidJsonThrows(): void
    {
        $transformer = new JsonArrayTransformer();
        $this->expectException(TransformationFailedException::class);
        $transformer->reverseTransform('{not valid json');
    }

    #[Test]
    public function jsonArrayTransformerScalarTopLevelThrows(): void
    {
        $transformer = new JsonArrayTransformer();
        $this->expectException(TransformationFailedException::class);
        $transformer->reverseTransform('"just a string"');
    }
}
