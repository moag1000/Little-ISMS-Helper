<?php

declare(strict_types=1);

namespace App\Tests\Service\Register;

use App\Service\Register\RegisterProposal;
use App\Service\Register\RegisterProposalProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterProposalParagraphsTest extends TestCase
{
    #[Test]
    public function splitsBulletsAndTitleDescriptionPairs(): void
    {
        $rows = RegisterProposalProvider::paragraphs("- Fachkräftemangel: IT-Stellen bleiben unbesetzt\n\n* Cloud-Abhängigkeit\n1. ok\nab");

        self::assertCount(2, $rows);
        self::assertSame('Fachkräftemangel', $rows[0]['title']);
        self::assertSame('IT-Stellen bleiben unbesetzt', $rows[0]['description']);
        self::assertSame('Cloud-Abhängigkeit', $rows[1]['title']);
        self::assertNull($rows[1]['description']);
    }

    #[Test]
    public function longLinesAreShortenedAndHashIsStable(): void
    {
        $line = str_repeat('Regulatorischer Druck steigt ', 6);
        $rows = RegisterProposalProvider::paragraphs($line);

        self::assertCount(1, $rows);
        self::assertLessThanOrEqual(118, mb_strlen($rows[0]['title']));
        self::assertSame(trim($line), $rows[0]['description']);
        self::assertSame($rows[0]['hash'], RegisterProposalProvider::paragraphs(mb_strtoupper($line))[0]['hash']);
    }

    #[Test]
    public function emptyTextGivesNoProposals(): void
    {
        self::assertSame([], RegisterProposalProvider::paragraphs(null));
        self::assertSame([], RegisterProposalProvider::paragraphs("\n \n"));
    }

    #[Test]
    public function proposalSurvivesTheFormRoundTrip(): void
    {
        $proposal = RegisterProposal::fromArray([
            'type' => 'issue_external', 'title' => 'T', 'description' => null, 'origin' => 'context.externalIssues#abc',
            'source' => 'register.source.context', 'ownerUserId' => null, 'category' => 'economic',
            'reference' => null, 'links' => ['risk' => [3]], 'backLinks' => [],
        ]);

        self::assertSame($proposal->toArray(), RegisterProposal::fromArray($proposal->toArray())->toArray());
    }
}
