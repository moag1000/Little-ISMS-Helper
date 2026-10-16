<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * The mapping CSV upload goes through FileUploadSecurityService: a file that
 * is not a genuine CSV never reaches the importer.
 */
final class ComplianceMappingImportUploadTest extends WebTestCase
{
    #[Test]
    public function nonCsvUploadIsRejectedBeforeImport(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Mapping import');
        $tenant->setCode('mapping_import_' . uniqid());
        $em->persist($tenant);
        $user = new User();
        $user->setEmail('mapping_import_' . uniqid() . '@example.com');
        $user->setFirstName('Mapping');
        $user->setLastName('Import');
        $user->setRoles(['ROLE_MANAGER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        $tmp = tempnam(sys_get_temp_dir(), 'mapping_import_');
        file_put_contents($tmp, '<?php echo "not a csv";');

        try {
            $client->loginUser($user);
            $client->request('GET', '/en/compliance/mapping/import');
            $session = $client->getRequest()->getSession();
            $token = (new UriSafeTokenGenerator())->generateToken();
            $session->set('_csrf/mapping_import', $token);
            $session->save();

            $client->request('POST', '/en/compliance/mapping/import', [
                '_token' => $token,
                'source_framework_code' => 'ISO27001',
                'target_framework_code' => 'NIS2',
            ], [
                'csv_file' => new UploadedFile($tmp, 'mapping.php', 'text/csv', null, true),
            ]);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Only CSV files are allowed.', (string) $client->getResponse()->getContent());
        } finally {
            @unlink($tmp);
            $em->remove($em->find(User::class, $user->getId()));
            $em->remove($em->find(Tenant::class, $tenant->getId()));
            $em->flush();
        }
    }
}
