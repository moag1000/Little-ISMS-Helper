<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The PWA share target cannot carry a CSRF token, so it must stay free of
 * side effects: shared files are validated, never written to disk, and no
 * server path is rendered into the page.
 */
final class ShareControllerUploadTest extends WebTestCase
{
    #[Test]
    public function sharedFilesAreValidatedAndNotStored(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Share upload');
        $tenant->setCode('share_upload_' . uniqid());
        $em->persist($tenant);
        $user = new User();
        $user->setEmail('share_' . uniqid() . '@example.com');
        $user->setFirstName('Share');
        $user->setLastName('Target');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        $shareDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/share_uploads';
        $before = is_dir($shareDir) ? count((array) scandir($shareDir)) : 0;

        $tmp = sys_get_temp_dir();
        file_put_contents($tmp . '/share_notes.txt', 'plain notes');
        file_put_contents($tmp . '/share_evil.php', '<?php echo "pwned";');

        try {
            $client->loginUser($user);
            $client->request('POST', '/en/share', ['title' => 'Shared'], ['files' => [
                new UploadedFile($tmp . '/share_notes.txt', 'notes.txt', 'text/plain', null, true),
                new UploadedFile($tmp . '/share_evil.php', 'evil.php', 'text/plain', null, true),
            ]]);

            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('notes.txt', $html);
            self::assertStringContainsString('The file "evil.php" was rejected', $html);
            self::assertStringNotContainsString('file_ids', $html);
            self::assertStringNotContainsString('share_uploads', $html);
            self::assertSame($before, is_dir($shareDir) ? count((array) scandir($shareDir)) : 0, 'shared files must not be stored');
        } finally {
            @unlink($tmp . '/share_notes.txt');
            @unlink($tmp . '/share_evil.php');
            $em->remove($em->find(User::class, $user->getId()));
            $em->remove($em->find(Tenant::class, $tenant->getId()));
            $em->flush();
        }
    }
}
