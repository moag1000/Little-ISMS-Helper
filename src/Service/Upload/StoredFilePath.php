<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\Entity\AuditFreeze;
use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one way from a file path stored on a record to a file on disk (S19).
 *
 * A stored path is data: a form, an import, the API or an organisation backup may carry
 * it. Joining it onto a directory ('…/public/' . $logoPath, '…/var/audit_freezes/' .
 * $pdfPath) turned '../../.env.local' into a read of the secrets and '../var/
 * setup_complete.lock' into a delete that reopened the setup wizard. Every reader, every
 * unlink and every ZIP call goes through this class (or ProtectedUploads for evidence):
 * only the expected shape is accepted, and the real path must lie inside the expected
 * folder.
 *
 * FIELDS lists every column that names a file; tests/Security/StoredFilePathContractTest
 * finds path-like columns in the Doctrine metadata and fails on one that is not listed
 * here or in NOT_FILE_PATHS. The organisation restore checks the same list
 * (RestoreTenantGuard::rejectedPathFields()).
 */
final class StoredFilePath
{
    /** public/uploads/<folder>/ — shown on login and in mails, so they stay in the web root. */
    public const string TENANT_LOGOS = 'tenants';
    public const string USER_AVATARS = 'users';

    /** Kinds: evidence below var/uploads (ProtectedUploads) … */
    public const string KIND_PROTECTED = 'protected';
    /** … a public upload 'uploads/<folder>/<file>' … */
    public const string KIND_PUBLIC = 'public:';
    /** … a bare file name in a fixed directory below the project … */
    public const string KIND_DIRECTORY = 'dir:';
    /** … a bare file name nothing opens (kept for the record). */
    public const string KIND_NAME = 'name';

    /** Column => kind. */
    public const array FIELDS = [
        Document::class . '::filePath' => self::KIND_PROTECTED,
        DocumentVersion::class . '::filePath' => self::KIND_PROTECTED,
        Tenant::class . '::logoPath' => self::KIND_PUBLIC . self::TENANT_LOGOS,
        TenantBranding::class . '::logoPath' => self::KIND_PUBLIC . self::TENANT_LOGOS,
        User::class . '::profilePicture' => self::KIND_PUBLIC . self::USER_AVATARS,
        AuditFreeze::class . '::pdfPath' => self::KIND_DIRECTORY . 'var/audit_freezes',
        ImportSession::class . '::storedFilename' => self::KIND_NAME,
    ];

    /** Path-like column names that do not name a file on disk, with the reason. */
    public const array NOT_FILE_PATHS = [
        Document::class . '::filename' => 'display name; the file is found via filePath',
        Document::class . '::originalFilename' => 'name the uploader gave the file, display only',
        DocumentVersion::class . '::fileName' => 'display name; the download reads filePath (storedPath() applies basename)',
        \App\Entity\TisaxLicenseConfirmation::class . '::workbookFilename' => 'name of the uploaded workbook, display only',
        \App\Entity\BulkImportBatch::class . '::sourceFileName' => 'name the uploader gave the file, display only',
        ImportSession::class . '::originalFilename' => 'name the uploader gave the file, display only',
        \App\Entity\DoraExitPlan::class . '::migrationPath' => 'free text: the migration route of an exit plan (DORA Art. 28(8))',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Kind of a registered column, or null. */
    public static function kindOf(string $entityClass, string $field): ?string
    {
        return self::FIELDS[$entityClass . '::' . $field] ?? null;
    }

    /** Whether $name is one file name: no directory part, no '..', no NUL, not hidden. */
    public static function isFileName(?string $name): bool
    {
        return $name !== null && $name !== '' && preg_match('#^[^/\\\\\0]+$#', $name) === 1
            && !str_starts_with($name, '.') && strlen($name) <= 255;
    }

    /** Whether a stored value has the shape 'uploads/<folder>/<file>' (leading slash optional). */
    public static function isPublicUpload(?string $stored, string $folder): bool
    {
        $prefix = 'uploads/' . $folder . '/';
        $stored = ltrim((string) $stored, '/');

        return str_starts_with($stored, $prefix) && self::isFileName(substr($stored, strlen($prefix)));
    }

    /**
     * Whether a value is acceptable for a column of this kind. Evidence paths are judged
     * by ProtectedUploads::resolve() on read ('virtual:…' and other markers resolve to
     * nothing), so only traversal and absolute paths outside var/ are refused here.
     */
    public static function isValid(string $kind, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (str_contains($value, "\0")) {
            return false;
        }

        return match (true) {
            $kind === self::KIND_PROTECTED => !preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $value),
            str_starts_with($kind, self::KIND_PUBLIC) => self::isPublicUpload($value, substr($kind, strlen(self::KIND_PUBLIC))),
            default => self::isFileName($value),
        };
    }

    /** The file behind a value of a registered column, or null (never outside its folder). */
    public function resolve(string $kind, ?string $value): ?string
    {
        return match (true) {
            $kind === self::KIND_PROTECTED => (new ProtectedUploads($this->projectDir))->resolve($value),
            str_starts_with($kind, self::KIND_PUBLIC) => $this->publicUpload($value, substr($kind, strlen(self::KIND_PUBLIC))),
            str_starts_with($kind, self::KIND_DIRECTORY) => self::inDirectory(
                $this->projectDir . '/' . substr($kind, strlen(self::KIND_DIRECTORY)),
                $value,
            ),
            default => null,
        };
    }

    /** File on disk behind 'uploads/<folder>/<file>', inside public/uploads/<folder>/, or null. */
    public function publicUpload(?string $stored, string $folder): ?string
    {
        if (!self::isPublicUpload($stored, $folder)) {
            return null;
        }

        return self::inDirectory($this->projectDir . '/public/uploads/' . $folder, basename((string) $stored));
    }

    /** The file $name directly in $directory (real path checked, symlinks out refused), or null. */
    public static function inDirectory(string $directory, ?string $name): ?string
    {
        if (!self::isFileName($name)) {
            return null;
        }
        $dir = realpath($directory);
        $file = $dir === false ? false : realpath($dir . '/' . $name);
        if ($file === false || dirname($file) !== $dir || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /** Deletes the public upload behind a stored value; anything else is left alone. */
    public function deletePublicUpload(?string $stored, string $folder): bool
    {
        $file = $this->publicUpload($stored, $folder);

        return $file !== null && @unlink($file);
    }
}
