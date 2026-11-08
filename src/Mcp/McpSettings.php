<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opt-in of an organisation for the assistant connection (MCP). Off by
 * default: an admin switches it on under Admin > Einstellungen > KI. While
 * it is off, /mcp answers every token of the organisation with 403.
 * Stored in Tenant.settings['mcp'].
 */
class McpSettings
{
    private const string TENANT_KEY = 'mcp';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function isEnabled(Tenant $tenant): bool
    {
        $settings = (array) (($tenant->getSettings() ?? [])[self::TENANT_KEY] ?? []);

        return ($settings['enabled'] ?? false) === true;
    }

    public function setEnabled(Tenant $tenant, bool $enabled): void
    {
        $settings = $tenant->getSettings() ?? [];
        $settings[self::TENANT_KEY] = ['enabled' => $enabled];
        $tenant->setSettings($settings);
        $this->entityManager->flush();
    }
}
