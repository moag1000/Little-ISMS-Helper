<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BSI Grundschutz Code-Konflikt Compat-Layer.
 *
 * Hintergrund: 5 Loader-Commands haben Framework-Rows mit zwei
 * verschiedenen `code`-Werten erzeugt:
 *   - 'BSI-Grundschutz' (Bindestrich, nur LoadBsiRequirementsCommand)
 *   - 'BSI_GRUNDSCHUTZ' (Underscore, alle anderen Loader + Industry-
 *     Baselines-Folder)
 *
 * Diese Migration konsolidiert beide auf den kanonischen Code
 * 'BSI_GRUNDSCHUTZ' (Underscore). Vorgehen:
 *   1. Falls beide Frameworks existieren: Anforderungen + Mappings vom
 *      Bindestrich-Framework auf das Underscore-Framework umhaengen
 *      (per requirementId-Dedup), dann Bindestrich-Framework loeschen.
 *   2. Falls nur Bindestrich-Framework existiert: code-Update.
 *   3. Falls nur Underscore-Framework existiert: no-op.
 *
 * Idempotent + non-destructiv (Anforderungen werden gemerged, nicht
 * geloescht). Anforderungen mit identischem requirementId behalten die
 * Underscore-Variante (sie ist neuer / kanonisch).
 */
final class Version20260507212829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BSI Grundschutz: konsolidiere Framework-Code "BSI-Grundschutz" (Bindestrich) zu kanonisch "BSI_GRUNDSCHUTZ" (Underscore); merge Anforderungen ohne Datenverlust.';
    }

    public function up(Schema $schema): void
    {
        $oldId = $this->connection->fetchOne(
            "SELECT id FROM compliance_framework WHERE code = 'BSI-Grundschutz'"
        );
        $newId = $this->connection->fetchOne(
            "SELECT id FROM compliance_framework WHERE code = 'BSI_GRUNDSCHUTZ'"
        );

        if ($oldId === false) {
            return;
        }

        if ($newId === false) {
            $this->addSql(
                "UPDATE compliance_framework SET code = 'BSI_GRUNDSCHUTZ' WHERE id = :id",
                ['id' => $oldId],
            );
            return;
        }

        // Both exist — merge old → new, dedup by requirementId.
        $this->addSql(
            "UPDATE IGNORE compliance_requirement
                SET framework_id = :newId
                WHERE framework_id = :oldId",
            ['newId' => $newId, 'oldId' => $oldId],
        );
        // Surviving duplicates (same requirementId on old framework) — drop them.
        $this->addSql(
            "DELETE FROM compliance_requirement WHERE framework_id = :oldId",
            ['oldId' => $oldId],
        );
        // Drop old framework — orphaned now.
        $this->addSql(
            "DELETE FROM compliance_framework WHERE id = :oldId",
            ['oldId' => $oldId],
        );
    }

    public function down(Schema $schema): void
    {
        // Non-reversible: merge cannot be split back without per-row provenance.
        // The duplicate-Code state was a bug; downgrading would re-introduce it.
        $this->throwIrreversibleMigrationException(
            'Re-creating split BSI-Grundschutz / BSI_GRUNDSCHUTZ frameworks is not supported.'
        );
    }
}
