<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Service\Import\EvidenceFieldFilter;
use App\Service\Import\Schema\ImportSchemaRegistry;
use App\Service\WriteRule\WriteRules;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S22 (W9 sweep 2, M2): the bulk import inherited every gap of the forms at
 * spreadsheet scale — a backdated response time, completion date or verification
 * in a row fed `deadline_adherence` and `training_participation`.
 *
 * Every importable column whose name says "something was done then" (a date of a
 * response, review, verification, completion, …) must carry a rule — evidence
 * written by its step (`#[ApiReadOnlyEvidence]`), a create-only value or a
 * manager/responsible field — or be reviewed below with the reason why typing it
 * is the fact itself. A new column fails until somebody decided.
 *
 * Second half: every importer service is classified, so a new one is looked at.
 */
final class ImportEvidenceContractTest extends KernelTestCase
{
    private const string DONE_DATE = '/(At|Date)$/';
    private const string DONE_WORD = '/approv|report|notif|verif|complet|remediat|review|consult|response|granted|tested|accepted|closed|deploy|resolv|signed|actual|last|conduct|perform|submitt/i';

    /** type::field => reason it may be typed in a reviewed import */
    private const array REVIEWED = [
        'Control::nextReviewDate' => 'planning date; "Review bestätigen" moves it on',
        'CorrectiveAction::plannedCompletionDate' => 'planning date, the due date of the action',
        'DataProtectionImpactAssessment::nextReviewDate' => 'planning date of the next DPIA review',
        'ISMSContext::lastReviewDate' => 'context edit is IsmsSteeringVoter (steering roles); the import needs ROLE_MANAGER',
        'ISMSContext::nextReviewDate' => 'planning date',
        'InternalAudit::actualDate' => 'the day the audit took place, a fact of the audit plan; report and approval are stamped (S21)',
        'ManagementReview::reviewDate' => 'the meeting date; review edit and import are ROLE_MANAGER, decisions go through ManagementDecisionService',
        'ManagementReview::nextReviewDate' => 'planning date',
        'ProcessingActivity::nextReviewDate' => 'planning date of the next VVT review',
        'Risk::reviewDate' => 'next review date (planning); the review itself is "Review bestätigen"',
    ];

    /** Importer services and what they write. class => kind */
    private const array IMPORTERS = [
        'App\Service\Import\BsiKompendiumXmlImporter' => 'global-catalogue (console, operator)',
        'App\Service\Import\BsiProfileXmlImporter' => 'global-catalogue (super admin on multi-org)',
        'App\Service\Import\CrossFrameworkMappingImporter' => 'global-catalogue',
        'App\Service\Import\DoraRegisterOfInformationImporter' => 'tenant-data (console, --tenant required)',
        'App\Service\Import\GstoolXmlImporter' => 'tenant-data (ADMIN_OWN_TENANT; availability keeps the BIA floor)',
        'App\Service\Library\BsiKompendiumImporter' => 'global-catalogue (library, super admin)',
        'App\Service\Library\VdaIsaImporter' => 'global-catalogue (library, super admin)',
        'App\Service\User\UserCsvImporter' => 'accounts (UserVoter::CREATE)',
    ];

    #[Test]
    public function everyDoneDateColumnCarriesARuleOrIsReviewed(): void
    {
        $registry = self::getContainer()->get(ImportSchemaRegistry::class);
        $problems = [];
        $seen = [];
        foreach ($registry->supportedEntityTypes() as $type) {
            $schema = $registry->getSchemaFor($type);
            if ($schema === null) {
                continue;
            }
            $ruled = [...EvidenceFieldFilter::importProtectedFields($schema->entityClass), ...array_keys(WriteRules::of($schema->entityClass))];
            foreach ($schema->fields() as $field) {
                if (preg_match(self::DONE_DATE, $field->name) !== 1 || preg_match(self::DONE_WORD, $field->name) !== 1) {
                    continue;
                }
                $key = $type . '::' . $field->name;
                $seen[$key] = true;
                if (!in_array($field->name, $ruled, true) && !isset(self::REVIEWED[$key])) {
                    $problems[] = $key . ' is importable and looks like evidence: mark it (#[ApiReadOnlyEvidence], #[CreateOnly], '
                        . '#[ManagerOrResponsibleField]) or review it into REVIEWED with the reason';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(20, count($seen), 'the name pattern no longer finds the known columns');
        foreach (array_keys(self::REVIEWED) as $key) {
            self::assertArrayHasKey($key, $seen, $key . ' is no longer an import column — drop it from REVIEWED');
        }
    }

    #[Test]
    public function everyImporterIsClassified(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Service', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Importer.php')) {
                $found[] = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            }
        }
        sort($found);
        $known = array_keys(self::IMPORTERS);
        sort($known);

        self::assertSame($known, $found, 'a new importer needs its classification here (tenant-data / global-catalogue / accounts) — '
            . 'tenant-data importers write through ImportEntityWriter or check the write rules themselves');
    }
}
