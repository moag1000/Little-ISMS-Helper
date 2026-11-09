<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Tenant;
use App\Service\Restore\RestoreTenantGuard;
use App\Service\Upload\ProtectedUploads;
use App\Service\Upload\StoredFilePath;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S19: a file path stored on a record is data — an organisation backup, an import or the
 * API can carry '../../.env.local'. The 7th assessment found it joined onto directories
 * (audit-freeze PDF download read any server file, logo/avatar replacement deleted
 * var/setup_complete.lock and reopened /setup, the next backup zipped any file).
 *
 * This test finds every path-like column in the Doctrine metadata itself and requires it
 * in StoredFilePath::FIELDS (resolved, restore-checked) or StoredFilePath::NOT_FILE_PATHS
 * (with the reason why it never names a file); it proves the resolver refuses traversal
 * for every registered column, the restore guard drops such values, and no source joins a
 * stored path onto a directory by hand.
 */
final class StoredFilePathContractTest extends KernelTestCase
{
    private const string PATH_LIKE = '/(path|file|filename|logo|avatar|picture|image)$/i';

    /** Values a crafted backup could carry; none may survive for any registered column. */
    private const array HOSTILE = [
        '../../.env.local',
        '../var/setup_complete.lock',
        'uploads/tenants/../../../var/setup-token',
        '/etc/passwd',
        "uploads/tenants/logo.png\0.php",
        'uploads/documents/..',
    ];

    private string $sandbox = '';

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
        parent::tearDown();
    }

    #[Test]
    public function everyPathLikeColumnIsRegistered(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $found = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            foreach ($meta->getFieldNames() as $field) {
                $type = $meta->getTypeOfField($field);
                if (in_array($type, [Types::STRING, Types::TEXT], true) && preg_match(self::PATH_LIKE, $field) === 1) {
                    $found[] = $meta->getName() . '::' . $field;
                }
            }
        }
        $registered = [...array_keys(StoredFilePath::FIELDS), ...array_keys(StoredFilePath::NOT_FILE_PATHS)];

        self::assertSame(
            [],
            array_values(array_diff($found, $registered)),
            'A column that names a file goes into StoredFilePath::FIELDS (and is read only through the resolver); '
            . 'one that never names a file goes into NOT_FILE_PATHS with the reason.',
        );
        self::assertSame([], array_values(array_diff($registered, $found)), 'Stale entries in StoredFilePath.');
        self::assertSame([], array_intersect(array_keys(StoredFilePath::FIELDS), array_keys(StoredFilePath::NOT_FILE_PATHS)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function registeredColumns(): iterable
    {
        foreach (StoredFilePath::FIELDS as $column => $kind) {
            yield $column => [$column, $kind];
        }
    }

    #[Test]
    #[DataProvider('registeredColumns')]
    public function hostileValuesAreRefusedForEveryColumn(string $column, string $kind): void
    {
        self::bootKernel();
        $guard = new RestoreTenantGuard(self::getContainer()->get(EntityManagerInterface::class));
        [$class, $field] = explode('::', $column, 2);
        $files = new StoredFilePath($this->sandbox());

        foreach (self::HOSTILE as $value) {
            self::assertNull($files->resolve($kind, $value), sprintf('%s resolves %s', $column, $value));
            // ProtectedUploads judges evidence paths on read; the others are refused by shape too.
            if ($kind !== StoredFilePath::KIND_PROTECTED || str_contains($value, '..') || str_contains($value, "\0")) {
                self::assertFalse(StoredFilePath::isValid($kind, $value), sprintf('%s accepts %s', $column, $value));
                foreach ([[], [1]] as $scope) {
                    self::assertSame(
                        [$field],
                        $guard->rejectedPathFields($class, [$field => $value], $scope),
                        sprintf('a restore takes %s = %s from the file', $column, $value),
                    );
                }
            }
        }
    }

    #[Test]
    public function resolverStaysInsideItsFolder(): void
    {
        $root = $this->sandbox();
        $files = new StoredFilePath($root);
        $uploads = new ProtectedUploads($root);

        self::assertSame(realpath($root . '/public/uploads/tenants/logo.png'), $files->publicUpload('uploads/tenants/logo.png', StoredFilePath::TENANT_LOGOS));
        self::assertSame(realpath($root . '/public/uploads/tenants/logo.png'), $files->publicUpload('/uploads/tenants/logo.png', StoredFilePath::TENANT_LOGOS));
        self::assertNull($files->publicUpload('uploads/tenants/escape.png', StoredFilePath::TENANT_LOGOS), 'symlink out of the folder');
        self::assertNull($files->publicUpload('uploads/users/logo.png', StoredFilePath::TENANT_LOGOS), 'other folder');
        self::assertNull(StoredFilePath::inDirectory($root . '/var/audit_freezes', '../setup_complete.lock'));
        self::assertSame(realpath($root . '/var/audit_freezes/1.pdf'), StoredFilePath::inDirectory($root . '/var/audit_freezes', '1.pdf'));

        self::assertFalse($files->deletePublicUpload('uploads/tenants/../../../var/setup_complete.lock', StoredFilePath::TENANT_LOGOS));
        self::assertFileExists($root . '/var/setup_complete.lock');
        self::assertTrue($files->deletePublicUpload('uploads/tenants/logo.png', StoredFilePath::TENANT_LOGOS));
        self::assertFileDoesNotExist($root . '/public/uploads/tenants/logo.png');

        // Evidence: absolute paths only below var/uploads and var/import-uploads.
        self::assertNull($uploads->resolve($root . '/var/setup_complete.lock'));
        self::assertNull($uploads->resolve($root . '/var/log/prod.log'));
        self::assertNotNull($uploads->resolve($root . '/var/import-uploads/batch.csv'));
        self::assertNotNull($uploads->resolve('/uploads/documents/a.pdf'));
    }

    /**
     * Reading, deleting or zipping a stored path goes through StoredFilePath or
     * ProtectedUploads — never a hand-made join of the stored value onto a directory.
     */
    #[Test]
    public function noSourceJoinsAStoredPathOntoADirectory(): void
    {
        $getters = 'get(?:LogoPath|ProfilePicture|PdfPath|FilePath|StoredFilename)\(\)';
        $pattern = '/\.\s*\$[\w>-]*->' . $getters . '|->' . $getters . '\s*\.(?!=)|\.\s*\$(?:logoPath|avatarPath|pdfPath|filePath|oldLogoPath|oldAvatarPath)\b/';
        $offenders = [];
        $src = dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            foreach (file((string) $file) ?: [] as $no => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }
                if (preg_match($pattern, $line) === 1) {
                    $offenders[] = substr((string) $file, strlen($src) + 1) . ':' . ($no + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Resolve stored paths with StoredFilePath / ProtectedUploads (S19).');
    }

    #[Test]
    public function tenantBrandingHtmlIsNotTakenFromAnOrganisationBackup(): void
    {
        self::assertTrue(RestoreTenantGuard::isProtectedField('TenantBranding', 'headerHtml', [1]));
        self::assertTrue(RestoreTenantGuard::isProtectedField('TenantBranding', 'footerHtml', [1]));
        self::assertFalse(RestoreTenantGuard::isProtectedField('TenantBranding', 'headerHtml', []), 'instance restore unchanged');
        self::assertArrayHasKey(Tenant::class . '::logoPath', StoredFilePath::FIELDS);
    }

    private function sandbox(): string
    {
        if ($this->sandbox !== '') {
            return $this->sandbox;
        }
        $root = sys_get_temp_dir() . '/s19-' . bin2hex(random_bytes(4));
        foreach (['public/uploads/tenants', 'public/uploads/users', 'var/audit_freezes', 'var/uploads/documents', 'var/import-uploads', 'var/log'] as $dir) {
            mkdir($root . '/' . $dir, 0777, true);
        }
        foreach (['public/uploads/tenants/logo.png', 'var/audit_freezes/1.pdf', 'var/uploads/documents/a.pdf', 'var/import-uploads/batch.csv', 'var/log/prod.log', 'var/setup_complete.lock', 'var/setup-token', '.env.local'] as $file) {
            file_put_contents($root . '/' . $file, 'x');
        }
        symlink($root . '/.env.local', $root . '/public/uploads/tenants/escape.png');

        return $this->sandbox = $root;
    }
}
