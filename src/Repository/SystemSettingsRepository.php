<?php

namespace App\Repository;

use App\Entity\SystemSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemSettings>
 */
class SystemSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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

        // Return decrypted value if encrypted, otherwise return value
        if ($setting->isEncrypted() && $setting->getEncryptedValue()) {
            // Decryption would happen here using Symfony's encryption service
            return $setting->getEncryptedValue(); // Placeholder
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
            // Encryption would happen here using Symfony's encryption service
            $setting->setEncryptedValue((string) $value); // Placeholder
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
