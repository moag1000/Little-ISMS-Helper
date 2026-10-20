<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A four-eyes approver of another organisation on inheritance confirm /
 * override is refused with a flash (redirect), not an HTTP 500, and the log
 * stays pending.
 */
final class ComplianceInheritanceForeignApproverTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $manager;
    private User $foreignManager;
    private int $logId;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('cif', true));

        $this->tenant = (new Tenant())->setName('CIF ' . $this->uid)->setCode('cif_' . $this->uid);
        $foreign = (new Tenant())->setName('CIF F ' . $this->uid)->setCode('ciff_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->persist($foreign);
        $this->manager = $this->user('manager', $this->tenant);
        $this->foreignManager = $this->user('foreign', $foreign);

        $framework = (new ComplianceFramework())->setCode('CIF_' . strtoupper(substr(md5($this->uid), 0, 8)))
            ->setName('CIF FW')->setVersion('1.0')->setApplicableIndustry('all')->setRegulatoryBody('test');
        $this->em->persist($framework);
        $source = $this->requirement($framework, 'SRC');
        $target = $this->requirement($framework, 'TGT');
        $mapping = (new ComplianceMapping())->setSourceRequirement($source)->setTargetRequirement($target)
            ->setMappingType('full')->setMappingPercentage(100)->setConfidence('high');
        $this->em->persist($mapping);
        $fulfillment = (new ComplianceRequirementFulfillment())->setTenant($this->tenant)->setRequirement($target)
            ->setFulfillmentPercentage(0);
        $this->em->persist($fulfillment);
        $log = (new FulfillmentInheritanceLog())->setTenant($this->tenant)->setFulfillment($fulfillment)
            ->setDerivedFromMapping($mapping)->setSuggestedPercentage(100);
        $this->em->persist($log);
        $this->em->flush();
        $this->logId = (int) $log->getId();
    }

    private function user(string $name, Tenant $tenant): User
    {
        $user = (new User())->setEmail($name . '_' . $this->uid . '@example.com')->setFirstName('F')
            ->setLastName($name)->setRoles(['ROLE_MANAGER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function requirement(ComplianceFramework $framework, string $id): ComplianceRequirement
    {
        $req = (new ComplianceRequirement())->setFramework($framework)->setRequirementId($id . '-' . $this->uid)
            ->setTitle($id)->setCategory('test')->setDescription('test')->setPriority('medium')
            ->setRequirementType('control');
        $this->em->persist($req);

        return $req;
    }

    private function assertStillPending(): void
    {
        $this->em->clear();
        self::assertSame(
            FulfillmentInheritanceLog::STATUS_PENDING_REVIEW,
            $this->em->find(FulfillmentInheritanceLog::class, $this->logId)?->getReviewStatus(),
        );
    }

    #[Test]
    public function confirmWithAForeignApproverIsRefusedWithAFlash(): void
    {
        $this->client->loginUser($this->manager);
        $token = $this->seedSessionCsrfToken($this->client, 'inheritance_confirm_' . $this->logId);

        $this->client->request('POST', '/en/compliance/inheritance/' . $this->logId . '/confirm', [
            '_token' => $token,
            'comment' => str_repeat('Reviewed and confirmed. ', 5),
            'request_implemented' => '1',
            'four_eyes_approver_id' => (string) $this->foreignManager->getId(),
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString(
            'Approver must belong to the same organisation.',
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertStillPending();
    }

    #[Test]
    public function overrideWithAForeignApproverIsRefusedWithAFlash(): void
    {
        $this->client->loginUser($this->manager);
        $token = $this->seedSessionCsrfToken($this->client, 'inheritance_override_' . $this->logId);

        $this->client->request('POST', '/en/compliance/inheritance/' . $this->logId . '/override', [
            '_token' => $token,
            'value' => '80',
            'reason' => str_repeat('Override because of local evidence. ', 5),
            'four_eyes_approver_id' => (string) $this->foreignManager->getId(),
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString(
            'Approver must belong to the same organisation.',
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertStillPending();
    }
}
