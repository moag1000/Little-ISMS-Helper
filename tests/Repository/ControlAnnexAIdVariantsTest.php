<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\ControlRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Controls are stored as "A.8.25", wizard checks and catalogues write
 * "8.25". Without both spellings every wizard control check found nothing
 * and scored 0 %.
 */
final class ControlAnnexAIdVariantsTest extends TestCase
{
    #[Test]
    public function returnsBothSpellingsOfEveryId(): void
    {
        self::assertSame(
            ['8.25', 'A.8.25', '5.1', 'A.5.1'],
            ControlRepository::annexAIdVariants(['8.25', 'A.5.1', 'A.8.25']),
        );
    }
}
