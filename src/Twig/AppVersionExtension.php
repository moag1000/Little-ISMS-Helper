<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\GlobalsInterface;
use Twig\Extension\AbstractExtension;

/**
 * FairyAurora v3.0 — App-Version Twig Global
 *
 * Liest die Version aus composer.json (Single Source of Truth) und
 * stellt sie als Twig-Global `app_version` zur Verfuegung.
 *
 * Wird vom Brand-Component (_brand.html.twig) und Email-Footer genutzt.
 */
class AppVersionExtension extends AbstractExtension implements GlobalsInterface
{
    private ?string $version = null;

    public function __construct(
        private readonly string $projectDir
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'app_version' => $this->getVersion(),
        ];
    }

    public function getVersion(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $composerPath = $this->projectDir . '/composer.json';
        if (!is_readable($composerPath)) {
            return $this->version = 'dev';
        }

        $data = json_decode((string) file_get_contents($composerPath), true);
        return $this->version = (string) ($data['version'] ?? 'dev');
    }
}
