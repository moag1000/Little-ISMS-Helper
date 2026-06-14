<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Consolidate framework-code aliases onto their canonical spelling
 * (App\Service\Catalog\FrameworkCode). Corrects Version20260506213529, which
 * merged in the WRONG direction (toward ISO22301 / NIST-CSF-2.0 / SOC2-TYPE-II)
 * and did so naively (no requirementId de-dup, no mapping/fulfillment repoint).
 *
 * Canonical = what the UI registry + registry-bound loaders use on main
 * (App\Service\Catalog\FrameworkCode):
 *   ISO22301/ISO_22301        -> ISO-22301
 *   SOC2-TYPE-II             -> SOC2
 *   NIS2-UmsuCG / NIS2-UMSUCG -> NIS2UMSUCG
 *   KRITIS-DE                -> KRITIS
 *   BSI-GRUNDSCHUTZ(-2024)    -> BSI_GRUNDSCHUTZ
 *   ENISA-EUCS               -> EUCS
 * NOTE: NIST-CSF-2.0 is the CANONICAL code on main (carries the 2.0 suffix) — it
 * is NOT merged away. (The earlier wrong-direction 20260506213529 merged toward
 * NIST-CSF-2.0/ISO22301/SOC2-TYPE-II; this migration reverses the spelling drift
 * but keeps NIST-CSF-2.0.)
 *
 * Two cases, per alias:
 *   RENAME  — canonical row absent: just rewrite the code. Requirement ids stay,
 *             so tenant fulfillment (FK -> requirement) is untouched.
 *   MERGE   — both rows present: move alias requirements onto the canonical
 *             framework. For requirementIds that already exist on canonical,
 *             repoint mappings + fulfillment to the surviving canonical row
 *             (dropping a conflicting duplicate fulfillment first), then delete
 *             the duplicate requirement. Finally drop the alias framework.
 *
 * Pure DML — kept transactional (atomic). NOT touched: separate process
 * frameworks (BSI-GRUNDSCHUTZ-KERN/-STANDARD) and no-loader mapping refs — WS-2.2.
 */
final class Version20260614120000 extends AbstractMigration
{
    /** @var array<string,string> alias => canonical */
    private const MERGES = [
        'ISO22301' => 'ISO-22301',
        'ISO_22301' => 'ISO-22301',
        'BSI-GRUNDSCHUTZ' => 'BSI_GRUNDSCHUTZ',
        'BSI-GRUNDSCHUTZ-2024' => 'BSI_GRUNDSCHUTZ',
        'SOC2-TYPE-II' => 'SOC2',
        'NIS2-UmsuCG' => 'NIS2UMSUCG',
        'NIS2-UMSUCG' => 'NIS2UMSUCG',
        'KRITIS-DE' => 'KRITIS',
        'ENISA-EUCS' => 'EUCS',
    ];

    public function getDescription(): string
    {
        return 'Consolidate framework-code aliases onto canonical codes (corrects 20260506213529); preserves tenant fulfillment.';
    }

    public function isTransactional(): bool
    {
        return true; // pure DML — atomic merge
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        foreach (self::MERGES as $alias => $canon) {
            $aliasId = $conn->fetchOne('SELECT id FROM compliance_framework WHERE code = ?', [$alias]);
            if ($aliasId === false) {
                continue; // nothing to do
            }
            $canonId = $conn->fetchOne('SELECT id FROM compliance_framework WHERE code = ?', [$canon]);

            if ($canonId === false) {
                // RENAME — canonical does not exist yet; just rewrite the code.
                $conn->executeStatement('UPDATE compliance_framework SET code = ? WHERE id = ?', [$canon, $aliasId]);
                continue;
            }

            // MERGE — resolve requirementId duplicates first.
            $dups = $conn->fetchAllAssociative(
                'SELECT ra.id AS alias_req, rc.id AS canon_req
                 FROM compliance_requirement ra
                 JOIN compliance_requirement rc
                   ON rc.framework_id = ? AND rc.requirement_id = ra.requirement_id
                 WHERE ra.framework_id = ?',
                [$canonId, $aliasId],
            );

            foreach ($dups as $d) {
                $aliasReq = $d['alias_req'];
                $canonReq = $d['canon_req'];

                // Repoint mappings off the duplicate (FK is onDelete CASCADE — must
                // move before deleting the requirement or the mappings vanish).
                $conn->executeStatement(
                    'UPDATE compliance_mapping SET source_requirement_id = ? WHERE source_requirement_id = ?',
                    [$canonReq, $aliasReq],
                );
                $conn->executeStatement(
                    'UPDATE compliance_mapping SET target_requirement_id = ? WHERE target_requirement_id = ?',
                    [$canonReq, $aliasReq],
                );

                // Drop inheritance logs + fulfillment that would collide with an
                // existing canonical fulfillment for the same tenant.
                $conn->executeStatement(
                    'DELETE il FROM fulfillment_inheritance_log il
                     JOIN compliance_requirement_fulfillment faf ON faf.id = il.fulfillment_id
                     JOIN compliance_requirement_fulfillment fcf
                       ON fcf.requirement_id = ? AND fcf.tenant_id = faf.tenant_id
                     WHERE faf.requirement_id = ?',
                    [$canonReq, $aliasReq],
                );
                $conn->executeStatement(
                    'DELETE faf FROM compliance_requirement_fulfillment faf
                     JOIN compliance_requirement_fulfillment fcf
                       ON fcf.requirement_id = ? AND fcf.tenant_id = faf.tenant_id
                     WHERE faf.requirement_id = ?',
                    [$canonReq, $aliasReq],
                );

                // Repoint surviving fulfillment to the canonical requirement.
                $conn->executeStatement(
                    'UPDATE compliance_requirement_fulfillment SET requirement_id = ? WHERE requirement_id = ?',
                    [$canonReq, $aliasReq],
                );

                // Remove the now-redundant duplicate requirement.
                $conn->executeStatement('DELETE FROM compliance_requirement WHERE id = ?', [$aliasReq]);
            }

            // Move the remaining (unique) alias requirements onto canonical.
            $conn->executeStatement(
                'UPDATE compliance_requirement SET framework_id = ? WHERE framework_id = ?',
                [$canonId, $aliasId],
            );

            // Drop the now-empty alias framework.
            $conn->executeStatement('DELETE FROM compliance_framework WHERE id = ?', [$aliasId]);
        }
    }

    public function down(Schema $schema): void
    {
        // Non-reversible — alias spellings are deliberately retired.
    }
}
