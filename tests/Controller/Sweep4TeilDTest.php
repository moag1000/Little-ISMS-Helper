<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Supplier;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** User delete with FK references must not 500; tag bulk apply must not cross tenants. */
final class Sweep4TeilDTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Tenant $other;
    private User $admin;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = uniqid('s4d', true);
        $this->tenant = (new Tenant())->setName('S4D ' . $this->uid)->setCode('d_' . substr($this->uid, -8));
        $this->other = (new Tenant())->setName('S4D2 ' . $this->uid)->setCode('e_' . substr($this->uid, -8));
        $this->em->persist($this->tenant);
        $this->em->persist($this->other);
        $this->admin = $this->user('admin', $this->tenant, ['ROLE_ADMIN']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $c = $this->em->getConnection();
        try {
            $c->executeStatement('DROP TABLE IF EXISTS zz_s4d_ref');
            $c->executeStatement('DELETE FROM entity_tag WHERE tagged_by_id = ?', [$this->admin->getId()]);
            $c->executeStatement('DELETE FROM supplier WHERE tenant_id IN (?, ?)', [$this->tenant->getId(), $this->other->getId()]);
            $c->executeStatement('DELETE FROM tag WHERE name LIKE ?', ['s4d%']);
            $c->executeStatement('DELETE FROM users WHERE email LIKE ?', ['%' . $this->uid . '@x.test']);
            $c->executeStatement('DELETE FROM tenant WHERE id IN (?, ?)', [$this->tenant->getId(), $this->other->getId()]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function deletingReferencedUserRedirectsWithFlashInsteadOf500(): void
    {
        $victim = $this->user('victim', $this->tenant, ['ROLE_USER']);
        $this->em->flush();
        $c = $this->em->getConnection();
        $c->executeStatement('CREATE TABLE zz_s4d_ref (uid INT NOT NULL, CONSTRAINT fk_zz_s4d FOREIGN KEY (uid) REFERENCES users(id))');
        $c->executeStatement('INSERT INTO zz_s4d_ref (uid) VALUES (?)', [$victim->getId()]);

        $this->client->loginUser($this->admin);
        $token = $this->token('delete' . $victim->getId());
        $this->client->request('POST', '/en/admin/users/' . $victim->getId() . '/delete', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$victim->getId()]));
    }

    #[Test]
    public function bulkApplyRejectsForeignTenantEntities(): void
    {
        $tag = (new Tag())->setName('s4d' . $this->uid)->setTenant($this->tenant);
        $foreign = (new Supplier())->setName('s4d foreign')->setServiceProvided('x')->setTenant($this->other);
        $this->em->persist($tag);
        $this->em->persist($foreign);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $token = $this->token('tag_bulk_apply');
        $this->client->request('POST', '/en/admin/tags/bulk/Supplier', [
            '_token' => $token,
            'entity_ids' => [$foreign->getId()],
            'tag_ids' => [$tag->getId()],
        ]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 403, 404]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM entity_tag WHERE tag_id = ?',
            [$tag->getId()],
        ));
    }

    private function token(string $id): string
    {
        $this->client->request('GET', '/en/admin/tags');
        $value = bin2hex(random_bytes(16));
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/' . $id, $value);
        $session->save();

        return $value;
    }

    /** @param list<string> $roles */
    private function user(string $name, Tenant $tenant, array $roles): User
    {
        $u = (new User())->setEmail($name . $this->uid . '@x.test')->setFirstName($name)->setLastName('T')
            ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);

        return $u;
    }
}
