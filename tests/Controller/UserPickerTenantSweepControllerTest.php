<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\CrisisTeam;
use App\Entity\DataBreach;
use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * User-picker tenant sweep, through the real controllers: the edit pages list
 * no user of another organisation, and a tampered POST carrying one is
 * rejected (422) without persisting it.
 */
final class UserPickerTenantSweepControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;
    private User $colleague;
    private User $foreigner;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('ups', true));

        $this->tenant = (new Tenant())->setName('UPS Tenant ' . $this->uid)->setCode('upst_' . $this->uid);
        $foreignTenant = (new Tenant())->setName('UPS Foreign ' . $this->uid)->setCode('upsf_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->persist($foreignTenant);

        $this->admin = $this->makeUser('Admin', 'Sweepadmin' . $this->uid, ['ROLE_ADMIN'], $this->tenant);
        $this->colleague = $this->makeUser('Colleague', 'Sweepcolleague' . $this->uid, ['ROLE_USER'], $this->tenant);
        $this->foreigner = $this->makeUser('Foreigner', 'Sweepforeigner' . $this->uid, ['ROLE_USER'], $foreignTenant);
        $this->em->flush();
    }

    /** @param list<string> $roles */
    private function makeUser(string $first, string $last, array $roles, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail(strtolower($first) . '_' . $this->uid . '@example.com')
            ->setFirstName($first)
            ->setLastName($last)
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    /**
     * GETs the edit page, asserts the picker lists the colleague but not the
     * foreigner, submits the foreigner and expects a 422.
     */
    private function assertTamperedPostRejected(string $url, string $field, bool $multiple): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $select = $crawler->filter(sprintf('select[name$="[%s]"], select[name$="[%s][]"]', $field, $field));
        self::assertCount(1, $select, sprintf('picker "%s" rendered', $field));
        $options = $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertContains((string) $this->colleague->getId(), $options, 'user of the organisation is listed');
        self::assertNotContains((string) $this->foreigner->getId(), $options, 'user of another organisation is listed');
        self::assertStringNotContainsString('Sweepforeigner' . $this->uid, (string) $this->client->getResponse()->getContent());

        $form = $select->closest('form')->form();
        $name = preg_replace('/\[\]$/', '', (string) $select->attr('name'));
        $form[$name]->disableValidation()->select($multiple ? [(string) $this->foreigner->getId()] : (string) $this->foreigner->getId());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);

        // Control: the same form with a user of the organisation saves, so the
        // 422 above is caused by the foreign user alone.
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->filter(sprintf('select[name$="[%s]"], select[name$="[%s][]"]', $field, $field))->closest('form')->form();
        $form[$name]->select($multiple ? [(string) $this->colleague->getId()] : (string) $this->colleague->getId());
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    #[Test]
    public function crisisTeamLeaderOfAnotherOrganisationIsRejected(): void
    {
        $team = (new CrisisTeam())->setTenant($this->tenant)->setTeamName('Team ' . $this->uid)->setTeamType('primary')->setIsActive(true)
            ->setDeputyLeader($this->admin);
        $id = $this->persist($team)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/crisis-team/%d/edit', $id), 'teamLeader', false);

        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->em->find(CrisisTeam::class, $id)->getTeamLeader()?->getId());
    }

    #[Test]
    public function managementReviewParticipantOfAnotherOrganisationIsRejected(): void
    {
        $review = (new ManagementReview())->setTenant($this->tenant)->setTitle('Review ' . $this->uid)
            ->setReviewDate(new \DateTime())->setStatus('planned')->setReviewedBy($this->admin);
        $id = $this->persist($review)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/management-review/%d/edit', $id), 'participants', true);

        $this->em->clear();
        $ids = $this->em->find(ManagementReview::class, $id)->getParticipants()->map(static fn (User $u): ?int => $u->getId())->toArray();
        self::assertSame([$this->colleague->getId()], array_values($ids));
    }

    #[Test]
    public function personLinkedUserOfAnotherOrganisationIsRejected(): void
    {
        $person = (new Person())->setTenant($this->tenant)->setFullName('Person ' . $this->uid)
            ->setPersonType('employee')->setEmail('person_' . $this->uid . '@example.com');
        $id = $this->persist($person)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/person/%d/edit', $id), 'linkedUser', false);

        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->em->find(Person::class, $id)->getLinkedUser()?->getId());
    }

    #[Test]
    public function fourEyesApproverOfAnotherOrganisationIsRejected(): void
    {
        $request = (new FourEyesApprovalRequest())->setTenant($this->tenant)
            ->setActionType(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE)
            ->setPayload(['subject_id' => 1])->setRequestedBy($this->admin);
        $id = $this->persist($request)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/four-eyes/%d/edit', $id), 'requestedApprover', false);

        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->em->find(FourEyesApprovalRequest::class, $id)->getRequestedApprover()?->getId());
    }

    #[Test]
    public function riskOwnerOfAnotherOrganisationIsRejected(): void
    {
        $asset = (new Asset())->setName('Asset ' . $this->uid)->setAssetType('hardware')->setOwner('IT')
            ->setStatus('active')->setTenant($this->tenant)->setConfidentialityValue(3)
            ->setIntegrityValue(3)->setAvailabilityValue(3);
        $this->em->persist($asset);
        $risk = (new Risk())->setTitle('Risk ' . $this->uid)->setCategory('security')->setDescription('d')
            ->setThreat('t')->setVulnerability('v')->setAsset($asset)->setProbability(3)
            ->setLikelihoodJustification('likelihood justification')->setImpact(4)
            ->setImpactJustification('impact justification')->setResidualProbability(2)->setResidualImpact(2)
            ->setTreatmentStrategy(\App\Enum\TreatmentStrategy::Mitigate)->setStatus(\App\Enum\RiskStatus::Identified)
            ->setRiskOwner($this->admin)->setTenant($this->tenant);
        $id = $this->persist($risk)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/risk/%d/edit', $id), 'riskOwner', false);

        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->em->find(Risk::class, $id)->getRiskOwner()?->getId());
    }

    #[Test]
    public function dataBreachDpoOfAnotherOrganisationIsRejected(): void
    {
        $breach = (new DataBreach())->setTenant($this->tenant)->setReferenceNumber('BR-' . $this->uid)
            ->setTitle('Breach ' . $this->uid)->setStatus('draft')->setSeverity('high')
            ->setDetectedAt(new \DateTimeImmutable())->setDataCategories(['personal_identification'])
            ->setDataSubjectCategories(['customers'])->setBreachNature('nature')->setLikelyConsequences('consequences')
            ->setMeasuresTaken('measures')->setAffectedDataSubjects(10)->setRequiresAuthorityNotification(true)
            ->setRequiresSubjectNotification(false)->setRiskLevel('high')
            ->setAssessor($this->admin);
        $id = $this->persist($breach)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/data-breach/%d/edit', $id), 'dataProtectionOfficer', false);

        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->em->find(DataBreach::class, $id)->getDataProtectionOfficer()?->getId());
    }

    #[Test]
    public function documentAudienceMemberOfAnotherOrganisationIsRejected(): void
    {
        $doc = (new Document())->setTenant($this->tenant)->setFilename('d.md')->setOriginalFilename('d.md')
            ->setMimeType('text/markdown')->setFileSize(1)->setFilePath('virtual:test')->setCategory('policy')
            ->setUploadedAt(new \DateTimeImmutable());
        $doc->setStatus('draft');
        $id = $this->persist($doc)->getId();

        $this->assertTamperedPostRejected(sprintf('/en/document/%d/edit', $id), 'acknowledgementAudience', true);

        $this->em->clear();
        $ids = $this->em->find(Document::class, $id)->getAcknowledgementAudience()->map(static fn (User $u): ?int => $u->getId())->toArray();
        self::assertSame([$this->colleague->getId()], array_values($ids));
    }

    #[Test]
    public function existingForeignLeaderSurvivesAReSave(): void
    {
        $team = (new CrisisTeam())->setTenant($this->tenant)->setTeamName('Legacy ' . $this->uid)->setTeamType('primary')
            ->setIsActive(true)->setTeamLeader($this->foreigner)->setDeputyLeader($this->admin);
        $id = $this->persist($team)->getId();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', sprintf('/en/crisis-team/%d/edit', $id));
        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name$="[teamLeader]"]');
        self::assertSame((string) $this->foreigner->getId(), $select->filter('option[selected]')->attr('value'), 'current value shown');

        $this->client->submit($select->closest('form')->form());
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertSame($this->foreigner->getId(), $this->em->find(CrisisTeam::class, $id)->getTeamLeader()?->getId());
    }

    #[Test]
    public function complianceQuickUpdateAcceptsOnlyUsersOfTheOrganisation(): void
    {
        $framework = (new ComplianceFramework())->setCode('UPS-' . $this->uid)->setName('UPS FW ' . $this->uid)
            ->setVersion('1.0')->setApplicableIndustry('all')->setRegulatoryBody('Body')->setMandatory(false)->setActive(true);
        $this->em->persist($framework);
        $requirement = (new ComplianceRequirement())->setFramework($framework)->setRequirementId('UPS-' . $this->uid)
            ->setTitle('Req ' . $this->uid)->setDescription('d')->setCategory('test')->setPriority('medium')->setRequirementType('core');
        $id = $this->persist($requirement)->getId();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', sprintf('/en/compliance/requirement/%d', $id));
        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="responsiblePersonUserId"]');
        self::assertCount(1, $select);
        $options = $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertNotContains((string) $this->foreigner->getId(), $options);

        $form = $select->closest('form')->form();
        $form['responsiblePersonUserId']->disableValidation()->select((string) $this->foreigner->getId());
        $this->client->submit($form);
        $this->em->clear();
        self::assertNull($this->fulfillment($requirement)?->getResponsiblePersonUser(), 'foreign user persisted');

        $crawler = $this->client->request('GET', sprintf('/en/compliance/requirement/%d', $id));
        $form = $crawler->filter('select[name="responsiblePersonUserId"]')->closest('form')->form();
        $form['responsiblePersonUserId']->select((string) $this->colleague->getId());
        $this->client->submit($form);
        $this->em->clear();
        self::assertSame($this->colleague->getId(), $this->fulfillment($requirement)?->getResponsiblePersonUser()?->getId());
    }

    private function fulfillment(ComplianceRequirement $requirement): ?ComplianceRequirementFulfillment
    {
        return $this->em->getRepository(ComplianceRequirementFulfillment::class)->findOneBy([
            'requirement' => $requirement->getId(),
            'tenant' => $this->tenant->getId(),
        ]);
    }
}
