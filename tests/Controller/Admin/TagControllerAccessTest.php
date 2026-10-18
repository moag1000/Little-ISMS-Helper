<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\TagController;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\TagVoter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tag master data: per-record checks run as #[IsGranted(..., subject:)]
 * attributes (CLAUDE.md rule) and keep the tenant scope of TagVoter.
 */
final class TagControllerAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $admin = null;
    /** @var list<Tag> */
    private array $tags = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('tagacc_', true);
        $this->tenant = (new Tenant())->setName('TagAccess ' . $uid)->setCode('ta_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('tagacc_' . $uid . '@x.test')
            ->setFirstName('Tag')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        try {
            foreach ($this->tags as $tag) {
                $conn->executeStatement('DELETE FROM tag WHERE id = ?', [$tag->getId()]);
            }
            $conn->executeStatement('DELETE FROM users WHERE id = ?', [$this->admin?->getId()]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenant?->getId()]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function editAndDeleteDeclareTheVoterCheckAsAttribute(): void
    {
        foreach (['edit', 'delete'] as $action) {
            $attributes = new ReflectionMethod(TagController::class, $action)->getAttributes(IsGranted::class);
            $subjects = array_map(
                static fn ($a): array => [$a->newInstance()->attribute, $a->newInstance()->subject],
                $attributes,
            );
            self::assertContains([TagVoter::TAG_MANAGE, 'tag'], $subjects, $action);
        }
    }

    #[Test]
    public function orgAdminManagesOwnTagButNotAGlobalOne(): void
    {
        $own = $this->tag($this->tenant);
        $global = $this->tag(null);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/tags/' . $own->getId() . '/edit');
        self::assertResponseIsSuccessful();

        // 403 from TagVoter, or 404 when the tenant Doctrine filter already
        // hides the global row — either way not editable.
        $this->client->request('GET', '/en/admin/tags/' . $global->getId() . '/edit');
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
    }

    private function tag(?Tenant $tenant): Tag
    {
        $tag = (new Tag())->setName(uniqid('tag_'))->setTenant($tenant);
        $this->em->persist($tag);
        $this->em->flush();
        $this->tags[] = $tag;

        return $tag;
    }
}
