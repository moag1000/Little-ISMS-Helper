<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506213529 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge loader-created Framework duplicates into wizard-aligned codes (ISO-22301→ISO22301, NIST-CSF→NIST-CSF-2.0, SOC2→SOC2-TYPE-II)';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        $merges = [
            ['ISO-22301', 'ISO22301'],
            ['NIST-CSF', 'NIST-CSF-2.0'],
            ['SOC2', 'SOC2-TYPE-II'],
        ];
        foreach ($merges as [$from, $to]) {
            $fromQ = $this->connection->quote($from);
            $toQ = $this->connection->quote($to);
            // Move requirements from duplicate to canonical (only if both exist)
            $this->addSql("
                UPDATE compliance_requirement r
                JOIN compliance_framework f_to ON f_to.code={$toQ}
                JOIN compliance_framework f_from ON f_from.code={$fromQ}
                SET r.framework_id = f_to.id
                WHERE r.framework_id = f_from.id
            ");
            // Drop duplicate framework
            $this->addSql("DELETE FROM compliance_framework WHERE code={$fromQ}");
        }
    }

    public function down(Schema $schema): void
    {
        // Non-reversible.
    }
}
