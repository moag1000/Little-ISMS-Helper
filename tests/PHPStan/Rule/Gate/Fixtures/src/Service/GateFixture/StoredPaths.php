<?php

declare(strict_types=1);

namespace App\Service\GateFixture;

use App\Entity\AuditFreeze;
use App\Entity\Tenant;
use App\Service\Upload\StoredFilePath;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Gate 94 fixture (T4: B3, B15–B20 of the counter-check). Every `expect:` line is a mutation the rule must report. */
final class StoredPaths
{
    public function __construct(
        private readonly StoredFilePath $storedFilePath,
        private readonly Filesystem $filesystem,
        private readonly string $uploadsDirectory = '/srv/public/uploads',
    ) {
    }

    /** @return list<mixed> */
    public function joins(Tenant $tenant, AuditFreeze $freeze, string $dir): array
    {
        return [
            $dir . '/' . $tenant->getLogoPath(), // expect: gate94.storedPath
            sprintf('%s/%s', $dir, $tenant->getLogoPath()), // expect: gate94.storedPath
            implode('/', [$dir, $freeze->getPdfPath()]), // expect: gate94.storedPath
            Path::join($dir, $freeze->getPdfPath()), // expect: gate94.storedPath
            "{$dir}/{$tenant->getLogoPath()}", // expect: gate94.storedPath
            $dir . // expect: gate94.storedPath
                $tenant->getLogoPath(),
            $dir . $tenant->{'getLogoPath'}(), // expect: gate94.storedPath
            $this->storedFilePath->publicUpload($tenant->getLogoPath(), StoredFilePath::TENANT_LOGOS),
            $dir . '/' . basename((string) $tenant->getLogoPath()),
            $tenant->getLogoPath() !== null,
        ];
    }

    public function alias(AuditFreeze $freeze, string $dir): string
    {
        $p = $freeze->getPdfPath();
        $q = $p;

        return $dir . '/' . $q; // expect: gate94.storedPath
    }

    public function rawFiles(Tenant $tenant, AuditFreeze $freeze): mixed
    {
        unlink((string) $tenant->getLogoPath()); // expect: gate94.storedPath
        $this->filesystem->remove((string) $tenant->getLogoPath()); // expect: gate94.storedPath
        new BinaryFileResponse((string) $freeze->getPdfPath()); // expect: gate94.storedPath

        return file_get_contents((string) $freeze->getPdfPath()); // expect: gate94.storedPath
    }

    public function reviewed(Tenant $tenant, string $dir): string
    {
        // @stored-path-ok: fixture of a reviewed join
        return $dir . $tenant->getLogoPath();
    }

    public function markerWithoutReason(Tenant $tenant, string $dir): string
    {
        // @stored-path-ok:
        return $dir . $tenant->getLogoPath(); // expect: gate94.storedPath
    }

    public function publicFolder(string $name): string
    {
        $fixed = __DIR__ . '/public/' . $this->uploadsDirectory;

        return $fixed . __DIR__ . '/public/' . $name; // expect: gate94.storedPath
    }
}
