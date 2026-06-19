<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Service\Certificate\CertificateFieldExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test — no DB, no kernel, no I/O.
 * Tests the heuristic OCR-text → draft fields extraction.
 */
final class CertificateFieldExtractorTest extends TestCase
{
    private CertificateFieldExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CertificateFieldExtractor();
    }

    // -------------------------------------------------------------------------
    // TC-1: Realistic EN ISO 27001 cert block (TÜV SÜD)
    // -------------------------------------------------------------------------

    #[Test]
    public function extractsFullEnglishIso27001Certificate(): void
    {
        $text = <<<CERT
        TÜV SÜD Management Service GmbH
        hereby certifies that

        Acme GmbH
        Musterstraße 1, 80333 München

        has established and applies a Management System for

        Information Security

        in accordance with

        ISO/IEC 27001:2022

        Certificate No.: 12 345 67890

        Valid from: 2024-03-15
        Valid until: 2027-03-14

        Issued to: Acme GmbH

        Date of issue: 2024-03-15

        Munich, 15 March 2024
        CERT;

        $result = $this->extractor->extract($text);

        $this->assertSame('TÜV SÜD', $result['certBody']);
        $this->assertSame('12 345 67890', $result['certNumber']);
        $this->assertSame('2027-03-14', $result['validUntil']);
        $this->assertSame('2024-03-15', $result['issueDate']);
        $this->assertSame('Acme GmbH', $result['holder']);
        $this->assertSame('ISO27001', $result['frameworkGuess']);
        $this->assertSame(1.0, $result['confidence']);
    }

    // -------------------------------------------------------------------------
    // TC-2: German DE block (DEKRA, dd.mm.yyyy dates)
    // -------------------------------------------------------------------------

    #[Test]
    public function extractsFullGermanIso27001CertificateWithDeDates(): void
    {
        $text = <<<CERT
        DEKRA Certification GmbH

        bescheinigt hiermit, dass

        Muster AG
        Beispielweg 42, 10115 Berlin

        ein Managementsystem für

        Informationssicherheit

        gemäß

        ISO/IEC 27001:2022

        aufgebaut hat und anwendet.

        Zertifikat-Nr. ABC-2024-001

        Ausstellungsdatum 15.03.2024
        gültig bis 14.03.2027

        ausgestellt für Muster AG

        Berlin, 15.03.2024
        CERT;

        $result = $this->extractor->extract($text);

        $this->assertSame('DEKRA', $result['certBody']);
        $this->assertSame('ABC-2024-001', $result['certNumber']);
        $this->assertSame('2027-03-14', $result['validUntil']);
        $this->assertSame('2024-03-15', $result['issueDate']);
        $this->assertSame('Muster AG', $result['holder']);
        $this->assertSame('ISO27001', $result['frameworkGuess']);
        $this->assertSame(1.0, $result['confidence']);
    }

    // -------------------------------------------------------------------------
    // TC-3: Sparse / garbage text → all nulls, confidence 0.0
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsAllNullsForGarbageText(): void
    {
        $text = 'random words no cert here lorem ipsum dolor sit amet';

        $result = $this->extractor->extract($text);

        $this->assertNull($result['certBody']);
        $this->assertNull($result['certNumber']);
        $this->assertNull($result['validUntil']);
        $this->assertNull($result['issueDate']);
        $this->assertNull($result['holder']);
        $this->assertNull($result['frameworkGuess']);
        $this->assertSame(0.0, $result['confidence']);
    }

    // -------------------------------------------------------------------------
    // TC-4: Date format edge — "14 March 2027" and "14. März 2024"
    // -------------------------------------------------------------------------

    #[Test]
    public function normalizesEnglishLongDateFormat(): void
    {
        $text = 'valid until 14 March 2027';
        $result = $this->extractor->extract($text);
        $this->assertSame('2027-03-14', $result['validUntil']);
    }

    #[Test]
    public function normalizesGermanLongDateFormat(): void
    {
        $text = 'Ausstellungsdatum 14. März 2024';
        $result = $this->extractor->extract($text);
        $this->assertSame('2024-03-14', $result['issueDate']);
    }

    // -------------------------------------------------------------------------
    // TC-5: certBody detection for various known bodies
    // -------------------------------------------------------------------------

    #[Test]
    public function detectsTuvRheinland(): void
    {
        $text = 'TÜV Rheinland i-sec GmbH certifies that';
        $result = $this->extractor->extract($text);
        $this->assertSame('TÜV Rheinland', $result['certBody']);
    }

    #[Test]
    public function detectsTuvNord(): void
    {
        $text = "TÜV NORD CERT GmbH\nhereby certifies";
        $result = $this->extractor->extract($text);
        $this->assertSame('TÜV NORD', $result['certBody']);
    }

    #[Test]
    public function detectsDnv(): void
    {
        $text = 'DNV GL Business Assurance has audited and certified that';
        $result = $this->extractor->extract($text);
        $this->assertSame('DNV', $result['certBody']);
    }

    #[Test]
    public function detectsBureauVeritas(): void
    {
        $text = 'Bureau Veritas Certification hereby certifies';
        $result = $this->extractor->extract($text);
        $this->assertSame('Bureau Veritas', $result['certBody']);
    }

    #[Test]
    public function detectsDqs(): void
    {
        $text = 'DQS GmbH - Deutsche Gesellschaft zur Zertifizierung';
        $result = $this->extractor->extract($text);
        $this->assertSame('DQS', $result['certBody']);
    }

    // -------------------------------------------------------------------------
    // TC-6: Framework guess for various norms
    // -------------------------------------------------------------------------

    #[Test]
    public function guessesIso9001(): void
    {
        $text = 'certified according to ISO 9001:2015';
        $result = $this->extractor->extract($text);
        $this->assertSame('ISO9001', $result['frameworkGuess']);
    }

    #[Test]
    public function guessesIso22301(): void
    {
        $text = 'ISO 22301:2019 Business Continuity Management';
        $result = $this->extractor->extract($text);
        $this->assertSame('ISO22301', $result['frameworkGuess']);
    }

    #[Test]
    public function guessesBsiC5(): void
    {
        $text = 'BSI C5:2020 Cloud Computing Compliance Criteria';
        $result = $this->extractor->extract($text);
        $this->assertSame('BSI_C5', $result['frameworkGuess']);
    }

    #[Test]
    public function guessesTisax(): void
    {
        $text = 'TISAX assessment label granted by ENX Association';
        $result = $this->extractor->extract($text);
        $this->assertSame('TISAX', $result['frameworkGuess']);
    }

    #[Test]
    public function guessesSoc2(): void
    {
        $text = 'SOC 2 Type II examination report';
        $result = $this->extractor->extract($text);
        $this->assertSame('SOC2', $result['frameworkGuess']);
    }

    // -------------------------------------------------------------------------
    // TC-7: certNumber variants
    // -------------------------------------------------------------------------

    #[Test]
    public function parsesRegistrationNumber(): void
    {
        $text = 'Registration No 12345-XYZ';
        $result = $this->extractor->extract($text);
        $this->assertSame('12345-XYZ', $result['certNumber']);
    }

    #[Test]
    public function parsesRegNrShortForm(): void
    {
        $text = 'Reg.-Nr. DE-2024-99887';
        $result = $this->extractor->extract($text);
        $this->assertSame('DE-2024-99887', $result['certNumber']);
    }

    // -------------------------------------------------------------------------
    // TC-8: validUntil vs issueDate disambiguation
    // -------------------------------------------------------------------------

    #[Test]
    public function distinguishesValidUntilFromIssueDate(): void
    {
        $text = <<<CERT
        Date of issue: 01/06/2023
        Expiry date: 31/05/2026
        CERT;

        $result = $this->extractor->extract($text);

        $this->assertSame('2023-06-01', $result['issueDate']);
        $this->assertSame('2026-05-31', $result['validUntil']);
    }

    #[Test]
    public function parsesValidFromAsIssueDate(): void
    {
        $text = 'Valid from: 2024-01-01   Valid until: 2027-01-01';
        $result = $this->extractor->extract($text);
        $this->assertSame('2024-01-01', $result['issueDate']);
        $this->assertSame('2027-01-01', $result['validUntil']);
    }

    // -------------------------------------------------------------------------
    // TC-9: Confidence calculation (partial match)
    // -------------------------------------------------------------------------

    #[Test]
    public function confidenceIsProportionalToFoundFields(): void
    {
        // 3 of 6 extractable fields present: certBody, frameworkGuess, certNumber
        $text = <<<CERT
        SGS United Kingdom Ltd
        Certificate No.: SGS-2024-555
        ISO/IEC 27001:2022
        CERT;

        $result = $this->extractor->extract($text);

        $this->assertSame('SGS', $result['certBody']);
        $this->assertSame('SGS-2024-555', $result['certNumber']);
        $this->assertSame('ISO27001', $result['frameworkGuess']);
        $this->assertNull($result['validUntil']);
        $this->assertNull($result['issueDate']);
        $this->assertNull($result['holder']);
        // 3/6 = 0.5
        $this->assertSame(0.5, $result['confidence']);
    }

    // -------------------------------------------------------------------------
    // TC-10: OCR noise tolerance (extra spaces / line breaks mid-token)
    // -------------------------------------------------------------------------

    #[Test]
    public function toleratesExtraWhitespaceInCertNumber(): void
    {
        // OCR artefact: extra space in number
        $text = 'Certificate  No.:   AB  CD  1234';
        $result = $this->extractor->extract($text);
        // The full captured string (spaces included) is preserved as-is
        $this->assertNotNull($result['certNumber']);
    }

    #[Test]
    public function toleratesCaseInsensitiveKeywords(): void
    {
        $text = 'VALID UNTIL 2028-12-31   ISSUED TO ACME Corp';
        $result = $this->extractor->extract($text);
        $this->assertSame('2028-12-31', $result['validUntil']);
        $this->assertSame('ACME Corp', $result['holder']);
    }
}
