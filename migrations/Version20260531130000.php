<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-key the 10 thematic EU AI Act requirements (AIACT-1..10) to their
 * article-level Art.X equivalents, so they merge with the full article-level
 * catalogue (LoadEuAiActFullCommand, now the wired loader) and so the
 * eu-ai-act library mappings/decompositions — which all reference Art.X — stop
 * being orphaned.
 *
 * Fulfillments link to requirements by row id (FK), NOT by the requirementId
 * string, so re-keying the string preserves every tenant fulfillment. After
 * this runs, loading EU-AI-ACT adds the remaining ~116 articles/annexes on top
 * of these 10 (idempotent skip on the re-keyed rows).
 *
 * Mapping (active thematic id -> official article, verified against the Full
 * catalogue's ARTICLES const):
 *   AIACT-1  AI Risk Classification              -> Art.6  (high-risk classification)
 *   AIACT-2  High-Risk AI Requirements           -> Art.9  (risk management system)
 *   AIACT-3  Data Governance                     -> Art.10 (data and data governance)
 *   AIACT-4  Technical Documentation             -> Art.11
 *   AIACT-5  Transparency Obligations            -> Art.13
 *   AIACT-6  Human Oversight                     -> Art.14
 *   AIACT-7  Accuracy, Robustness, Cybersecurity -> Art.15
 *   AIACT-8  Conformity Assessment               -> Art.43
 *   AIACT-9  Post-Market Monitoring              -> Art.72
 *   AIACT-10 GPAI Model Obligations              -> Art.53
 */
final class Version20260531130000 extends AbstractMigration
{
    private const REKEY = [
        'AIACT-1' => 'Art.6',
        'AIACT-2' => 'Art.9',
        'AIACT-3' => 'Art.10',
        'AIACT-4' => 'Art.11',
        'AIACT-5' => 'Art.13',
        'AIACT-6' => 'Art.14',
        'AIACT-7' => 'Art.15',
        'AIACT-8' => 'Art.43',
        'AIACT-9' => 'Art.72',
        'AIACT-10' => 'Art.53',
    ];

    public function getDescription(): string
    {
        return 'Re-key EU AI Act AIACT-1..10 thematic requirements to Art.X article scheme (de-orphan mappings, preserve fulfillments)';
    }

    public function isTransactional(): bool
    {
        // Data-only UPDATEs — keep transactional so a partial re-key rolls back.
        return true;
    }

    public function up(Schema $schema): void
    {
        foreach (self::REKEY as $old => $new) {
            // Only re-key when the target Art.X is not already present for the
            // framework, so a DB that already loaded the full catalogue is left
            // untouched (the stale AIACT row, if any, is removed below).
            $this->addSql(
                "UPDATE compliance_requirement
                 SET requirement_id = :new
                 WHERE requirement_id = :old
                   AND framework_id IN (SELECT id FROM compliance_framework WHERE code = 'EU-AI-ACT')
                   AND NOT EXISTS (
                       SELECT 1 FROM (SELECT * FROM compliance_requirement) cr2
                       WHERE cr2.requirement_id = :new
                         AND cr2.framework_id IN (SELECT id FROM compliance_framework WHERE code = 'EU-AI-ACT')
                   )",
                ['old' => $old, 'new' => $new],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::REKEY as $old => $new) {
            $this->addSql(
                "UPDATE compliance_requirement
                 SET requirement_id = :old
                 WHERE requirement_id = :new
                   AND framework_id IN (SELECT id FROM compliance_framework WHERE code = 'EU-AI-ACT')",
                ['old' => $old, 'new' => $new],
            );
        }
    }
}
