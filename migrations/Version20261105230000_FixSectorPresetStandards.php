<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two sector preset bundles preselected standards the policy wizard does not know
 * ('iec62443', 'kritis'); WelcomeStandardsStep rejected the preset. Aligns existing
 * rows with SeedIndustryPresetBundlesCommand. Data-only.
 */
final class Version20261105230000_FixSectorPresetStandards extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unknown standards from the ot_iec62443 and kritis_energie preset bundles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE industry_preset_bundle SET preselected_standards = '[\"iso27001\",\"nis2\"]'"
            . " WHERE bundle_key = 'ot_iec62443'",
        );
        $this->addSql(
            "UPDATE industry_preset_bundle SET preselected_standards = '[\"iso27001\",\"bsi\",\"nis2\",\"bcm\"]'"
            . " WHERE bundle_key = 'kritis_energie'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE industry_preset_bundle SET preselected_standards = '[\"iso27001\",\"iec62443\"]'"
            . " WHERE bundle_key = 'ot_iec62443'",
        );
        $this->addSql(
            "UPDATE industry_preset_bundle SET preselected_standards = '[\"iso27001\",\"bsi\",\"kritis\",\"nis2\",\"bcm\"]'"
            . " WHERE bundle_key = 'kritis_energie'",
        );
    }
}
