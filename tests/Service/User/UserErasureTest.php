<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\AlvaHintDismissal;
use App\Entity\Comment;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\User\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S29 (GDPR Art. 17 / ISO 27001 7.5.3): erasing a person keeps the evidence they
 * left. Over every foreign key on `users` in the Doctrine metadata:
 * a key with ON DELETE CASCADE belongs to a personal setting
 * ({@see UserErasure::PERSONAL_SETTINGS}, removed on erasure); every other key
 * survives the erasure, so acknowledgements, participations, comments and
 * approvals keep their (anonymous) author.
 */
final class UserErasureTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function onlyPersonalSettingsCascadeFromTheAccount(): void
    {
        $cascading = [];
        $userKeys = 0;
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if (!$mapping->isToOneOwningSide() || $mapping->targetEntity !== User::class) {
                    continue;
                }
                ++$userKeys;
                if (strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? '')) === 'CASCADE') {
                    $cascading[$metadata->getName()] = true;
                    self::assertSame('user', $field, $metadata->getName() . ': erasure removes personal settings by `user`');
                }
            }
        }

        self::assertGreaterThan(20, $userKeys);
        $expected = UserErasure::PERSONAL_SETTINGS;
        $actual = array_keys($cascading);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'A foreign key on users with ON DELETE CASCADE is either a personal setting'
            . ' (UserErasure::PERSONAL_SETTINGS) or evidence that must survive (RESTRICT / SET NULL).');
    }

    #[Test]
    public function erasureAnonymisesAndKeepsTheEvidence(): void
    {
        $this->em->beginTransaction();
        try {
            $uid = substr(uniqid('', true), -8);
            $tenant = (new Tenant())->setName('Erasure ' . $uid)->setCode('era_' . $uid);
            $this->em->persist($tenant);
            $author = $this->user($tenant, 'author_' . $uid);
            $unused = $this->user($tenant, 'unused_' . $uid);
            $comment = (new Comment())->setTenant($tenant)->setEntityType('Risk')->setEntityId(1)->setAuthor($author)->setBody('Text');
            $this->em->persist($comment);
            $dismissal = (new AlvaHintDismissal())->setUser($author)->setHintKey('k_' . $uid);
            $this->em->persist($dismissal);
            $this->em->flush();
            $authorId = $author->getId();
            $unusedId = $unused->getId();
            $commentId = $comment->getId();
            $dismissalId = $dismissal->getId();

            /** @var UserErasure $erasure */
            $erasure = self::getContainer()->get(UserErasure::class);
            self::assertFalse($erasure->erase($author), 'an author of evidence stays as anonymised account');
            self::assertTrue($erasure->erase($unused), 'an account nothing points at is deleted');
            $this->em->clear();

            $kept = $this->em->find(User::class, $authorId);
            self::assertNotNull($kept);
            self::assertSame(sprintf('erased-%d@erased.invalid', $authorId), $kept->getEmail());
            self::assertSame(UserErasure::ANONYMOUS_FIRST_NAME, $kept->getFirstName());
            self::assertFalse($kept->isActive());
            self::assertNull($kept->getPassword());
            self::assertSame($authorId, $this->em->find(Comment::class, $commentId)?->getAuthor()?->getId());
            self::assertNull($this->em->find(AlvaHintDismissal::class, $dismissalId), 'personal setting removed');
            self::assertNull($this->em->find(User::class, $unusedId));
        } finally {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
        }
    }

    private function user(Tenant $tenant, string $name): User
    {
        $user = (new User())
            ->setEmail($name . '@x.test')
            ->setFirstName('T')
            ->setLastName('U')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
