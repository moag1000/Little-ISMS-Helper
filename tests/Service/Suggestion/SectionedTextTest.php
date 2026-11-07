<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Service\Suggestion\SectionedText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectionedTextTest extends TestCase
{
    private const array LABELS = ['nature' => 'Art der Verletzung', 'measures' => 'Maßnahmen'];

    #[Test]
    public function composedTextParsesBackIntoItsFields(): void
    {
        $text = SectionedText::compose(['Art der Verletzung' => "Ein Laptop\nwurde gestohlen.", 'Maßnahmen' => 'Gerät gesperrt.']);

        self::assertSame(
            ['nature' => "Ein Laptop\nwurde gestohlen.", 'measures' => 'Gerät gesperrt.'],
            SectionedText::parse($text, self::LABELS),
        );
    }

    #[Test]
    public function editedBlocksWinAndARemovedHeadingIsLeftOut(): void
    {
        $edited = "maßnahmen:\n  Passwörter zurückgesetzt.  \n";

        self::assertSame(['measures' => 'Passwörter zurückgesetzt.'], SectionedText::parse($edited, self::LABELS));
    }

    #[Test]
    public function emptySectionsAreNotComposed(): void
    {
        self::assertSame("Maßnahmen:\nX", SectionedText::compose(['Art der Verletzung' => '  ', 'Maßnahmen' => 'X']));
    }
}
