<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SystemSettings;
use App\Service\MfaEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemSettings>
 */
class SystemSettingsRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MfaEncryptionService $cipher,
    ) {
        parent::__construct($registry, SystemSettings::class);
    }

    /**
     * Get a setting value by category and key
     */
    public function getSetting(string $category, string $key, mixed $default = null): mixed
    {
        $setting = $this->findOneBy(['category' => $category, 'key' => $key]);

        if (!$setting instanceof SystemSettings) {
            return $default;
        }

        // Decrypt stored ciphertext (sodium secretbox via MfaEncryptionService).
        if ($setting->isEncrypted() && $setting->getEncryptedValue()) {
            return $this->cipher->decrypt($setting->getEncryptedValue());
        }

        return $setting->getValue();
    }

    /**
     * Set a setting value by category and key
     */
    public function setSetting(string $category, string $key, mixed $value, bool $isEncrypted = false, ?string $description = null, ?string $updatedBy = null): SystemSettings
    {
        $setting = $this->findOneBy(['category' => $category, 'key' => $key]);

        if (!$setting instanceof SystemSettings) {
            $setting = new SystemSettings();
            $setting->setCategory($category);
            $setting->setKey($key);
            $setting->setDescription($description);
        }

        if ($isEncrypted) {
            // Encrypt at rest (sodium secretbox) — previously the plaintext was
            // stored verbatim in encrypted_value, so "encrypted" settings were
            // not actually encrypted.
            $setting->setEncryptedValue($this->cipher->encrypt((string) $value));
            $setting->setIsEncrypted(true);
            $setting->setValue(null); // Clear plain value
        } else {
            $setting->setValue($value);
            $setting->setEncryptedValue(null);
            $setting->setIsEncrypted(false);
        }

        if ($updatedBy) {
            $setting->setUpdatedBy($updatedBy);
        }

        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();

        return $setting;
    }

    /**
     * Get all settings for a specific category
     *
     * @return SystemSettings[]
     */
    public function getSettingsByCategory(string $category): array
    {
        return $this->findBy(['category' => $category], ['key' => 'ASC']);
    }

    /**
     * Get all settings as an associative array [category => [key => value]]
     */
    public function getAllSettingsArray(): array
    {
        $settings = $this->findAll();
        $result = [];

        foreach ($settings as $setting) {
            $category = $setting->getCategory();
            $key = $setting->getKey();

            if (!isset($result[$category])) {
                $result[$category] = [];
            }

            if ($setting->isEncrypted() && $setting->getEncryptedValue()) {
                $result[$category][$key] = '***ENCRYPTED***';
            } else {
                $result[$category][$key] = $setting->getValue();
            }
        }

        return $result;
    }

    /**
     * Delete a setting
     */
    public function deleteSetting(string $category, string $key): bool
    {
        $setting = $this->findOneBy(['category' => $category, 'key' => $key]);

        if (!$setting instanceof SystemSettings) {
            return false;
        }

        $this->getEntityManager()->remove($setting);
        $this->getEntityManager()->flush();

        return true;
    }
}
