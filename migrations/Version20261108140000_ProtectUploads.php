<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Upload\ProtectedUploads;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S7: uploaded documents, document versions and training materials move from
 * public/uploads/ (served by the web server without login) to var/uploads/ (served
 * only by the download actions: voter + audit log). The stored paths on the records
 * stay as they are ('/uploads/documents/<file>'), so no SQL is needed.
 *
 * Idempotent: a file that cannot be moved stays where it is and is still found
 * (ProtectedUploads::resolve() looks in both places); running the migration again —
 * or `app:uploads:protect` — moves the rest.
 */
final class Version20261108140000_ProtectUploads extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move uploaded evidence out of the web root (public/uploads → var/uploads)';
    }

    public function up(Schema $schema): void
    {
        $result = (new ProtectedUploads(dirname(__DIR__)))->moveLegacyFiles();
        $this->write(sprintf(
            '  Uploads geschützt: %d verschoben, %d schon vorhanden, %d nicht verschiebbar%s.',
            $result['moved'],
            $result['kept'],
            $result['failed'],
            $result['blocked'] ? ' (nur public/uploads ist ein Volume, var/ nicht: var/ als Volume einbinden)' : '',
        ));
        // No schema change: a no-op statement keeps Doctrine from warning about an empty migration.
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        // Moving evidence back into the web root would undo the fix; files stay protected.
        $this->addSql('SELECT 1');
    }
}
