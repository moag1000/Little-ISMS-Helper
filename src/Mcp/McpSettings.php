<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opt-in of an organisation for the assistant connection (MCP). Off by
 * default: an admin switches it on under Admin > Einstellungen > KI. While
 * it is off, /mcp answers every token of the organisation with 403.
 * Writing (save_record) is a second switch, on by default once the
 * connection is on; it still needs a token that may write.
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
        return ($this->settings($tenant)['enabled'] ?? false) === true;
    }

    public function isWriteEnabled(Tenant $tenant): bool
    {
        return $this->isEnabled($tenant) && ($this->settings($tenant)['write'] ?? true) !== false;
    }

    public function setEnabled(Tenant $tenant, bool $enabled): void
    {
        $this->store($tenant, ['enabled' => $enabled]);
    }

    public function setWriteEnabled(Tenant $tenant, bool $write): void
    {
        $this->store($tenant, ['write' => $write]);
    }

    /** @return array<string, mixed> */
    private function settings(Tenant $tenant): array
    {
        return (array) (($tenant->getSettings() ?? [])[self::TENANT_KEY] ?? []);
    }

    /** @param array<string, bool> $values */
    private function store(Tenant $tenant, array $values): void
    {
        $settings = $tenant->getSettings() ?? [];
        $settings[self::TENANT_KEY] = $values + $this->settings($tenant);
        $tenant->setSettings($settings);
        $this->entityManager->flush();
    }
}
