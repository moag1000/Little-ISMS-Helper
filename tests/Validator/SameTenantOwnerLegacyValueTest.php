<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\CrisisTeam;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\CrisisTeamType;
use App\Form\ManagementReviewType;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Legacy cross-tenant links: a value the record already held stays selectable
 * and passes SameTenantOwner unchanged (no silent loss on re-save); a NEW
 * foreign assignment is still rejected.
 */
final class SameTenantOwnerLegacyValueTest extends KernelTestCase
{
    private const string MESSAGE = 'tenant.validation.owner_other_tenant';

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $own;
    private User $legacyForeign;
    private User $otherForeign;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(TenantContext::class)->setCurrentTenant(null);
        $uid = str_replace('.', '', uniqid('stl', true));

        $this->tenant = (new Tenant())->setName('STL ' . $uid)->setCode('stl_' . $uid);
        $foreign = (new Tenant())->setName('STL F ' . $uid)->setCode('stlf_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->persist($foreign);
        $this->own = $this->user('own_' . $uid, $this->tenant);
        $this->legacyForeign = $this->user('legacy_' . $uid, $foreign);
        $this->otherForeign = $this->user('other_' . $uid, $foreign);
        $this->em->flush();
    }

    private function user(string $name, Tenant $tenant): User
    {
        $user = (new User())->setEmail($name . '@example.com')->setFirstName('F')->setLastName($name)
            ->setRoles(['ROLE_USER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    /** Persists a crisis team whose leader is a user of ANOTHER tenant (legacy data) and reloads it. */
    private function legacyTeam(): CrisisTeam
    {
        $team = (new CrisisTeam())->setTenant($this->tenant)->setTeamName('T')->setTeamType('primary')
            ->setIsActive(true)->setTeamLeader($this->legacyForeign)->setDeputyLeader($this->own);
        $this->em->persist($team);
        $this->em->flush();
        $id = $team->getId();
        $this->em->clear();

        return $this->em->find(CrisisTeam::class, $id);
    }

    private function legacyReview(): ManagementReview
    {
        $review = (new ManagementReview())->setTenant($this->tenant)->setTitle('R')
            ->setReviewDate(new \DateTime())->setStatus('planned')->setReviewedBy($this->own);
        $review->addParticipant($this->legacyForeign);
        $this->em->persist($review);
        $this->em->flush();
        $id = $review->getId();
        $this->em->clear();

        return $this->em->find(ManagementReview::class, $id);
    }

    /** @return list<string> */
    private function messages(object $entity, string $property): array
    {
        $out = [];
        foreach (static::getContainer()->get(ValidatorInterface::class)->validateProperty($entity, $property) as $v) {
            $out[] = (string) $v->getMessageTemplate();
        }

        return $out;
    }

    #[Test]
    public function unchangedLegacyForeignUserPasses(): void
    {
        self::assertNotContains(self::MESSAGE, $this->messages($this->legacyTeam(), 'teamLeader'));
    }

    #[Test]
    public function newForeignUserOnAPersistedRecordIsRejected(): void
    {
        $team = $this->legacyTeam();
        $team->setTeamLeader($this->em->find(User::class, $this->otherForeign->getId()));

        self::assertContains(self::MESSAGE, $this->messages($team, 'teamLeader'));
    }

    #[Test]
    public function newRecordIsAlwaysChecked(): void
    {
        $team = (new CrisisTeam())->setTenant($this->tenant)->setTeamLeader($this->legacyForeign);

        self::assertContains(self::MESSAGE, $this->messages($team, 'teamLeader'));
    }

    #[Test]
    public function collectionKeepsLegacyElementButRejectsANewForeignOne(): void
    {
        $review = $this->legacyReview();
        self::assertNotContains(self::MESSAGE, $this->messages($review, 'participants'));

        $review->addParticipant($this->em->find(User::class, $this->otherForeign->getId()));
        self::assertContains(self::MESSAGE, $this->messages($review, 'participants'));
    }

    #[Test]
    public function pickerKeepsTheCurrentForeignValueAndReSaveKeepsIt(): void
    {
        $team = $this->legacyTeam();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(CrisisTeamType::class, $team, ['csrf_protection' => false]);
        $view = $form->createView()['teamLeader'];
        $values = array_map(static fn ($c): string => (string) $c->value, $view->vars['choices']);

        self::assertSame((string) $this->legacyForeign->getId(), $view->vars['value'], 'current value stays selected');
        self::assertContains((string) $this->own->getId(), $values);
        self::assertNotContains((string) $this->otherForeign->getId(), $values, 'other foreign users stay hidden');

        $form->submit(['teamLeader' => (string) $this->legacyForeign->getId()], false);
        self::assertTrue($form->get('teamLeader')->isValid());
        self::assertSame($this->legacyForeign->getId(), $team->getTeamLeader()?->getId());
        self::assertNotContains(self::MESSAGE, $this->messages($team, 'teamLeader'));
    }

    #[Test]
    public function pickerRejectsSwitchingToAnotherForeignUser(): void
    {
        $team = $this->legacyTeam();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(CrisisTeamType::class, $team, ['csrf_protection' => false]);

        $form->submit(['teamLeader' => (string) $this->otherForeign->getId()], false);

        self::assertFalse($form->get('teamLeader')->isValid());
    }

    #[Test]
    public function multiplePickerKeepsTheCurrentForeignElement(): void
    {
        $review = $this->legacyReview();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(ManagementReviewType::class, $review, ['csrf_protection' => false]);
        $values = array_map(static fn ($c): string => (string) $c->value, $form->createView()['participants']->vars['choices']);

        self::assertContains((string) $this->legacyForeign->getId(), $values);
        self::assertNotContains((string) $this->otherForeign->getId(), $values);

        $form->submit(['participants' => [(string) $this->legacyForeign->getId(), (string) $this->own->getId()]], false);
        self::assertTrue($form->get('participants')->isValid());
        self::assertCount(2, $review->getParticipants());
        self::assertNotContains(self::MESSAGE, $this->messages($review, 'participants'));
    }
}
