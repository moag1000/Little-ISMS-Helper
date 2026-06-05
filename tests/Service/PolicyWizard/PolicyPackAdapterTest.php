<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Service\PolicyWizard\PolicyPackAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F38 — policy-pack export + parse/validate. DB-free; the translator stub
 * echoes keys so resolved text falls back to a traceable reference.
 */
final class PolicyPackAdapterTest extends TestCase
{
    private function adapter(): PolicyPackAdapter
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id, // unresolved → key returned (adapter then keeps the key)
        );

        return new PolicyPackAdapter($translator);
    }

    private function template(string $key, string $type, string $standard): PolicyTemplate
    {
        $t = new PolicyTemplate();
        $t->setKey($key);
        $t->setDocumentType($type);
        $t->setStandard($standard);
        $t->setTitleTranslationKey($key . '.title');
        $t->setBodyTranslationKey($key . '.body');
        return $t;
    }

    #[Test]
    public function exportProducesParseablePackWithEntries(): void
    {
        $adapter = $this->adapter();
        $json = $adapter->export([
            $this->template('iso.a5.1', 'policy', 'iso27001'),
            $this->template('iso.a8.24', 'procedure', 'iso27001'),
        ], 'de', 'my-pack');

        $decoded = json_decode($json, true);
        self::assertSame('1.0', $decoded['pack']['format_version']);
        self::assertSame('my-pack', $decoded['pack']['name']);
        self::assertSame(2, $decoded['pack']['entry_count']);
        self::assertCount(2, $decoded['entries']);
        self::assertSame('iso.a5.1', $decoded['entries'][0]['key']);
        self::assertSame('iso.a5.1.title', $decoded['entries'][0]['title']);
    }

    #[Test]
    public function parseAcceptsAValidPack(): void
    {
        $adapter = $this->adapter();
        $json = $adapter->export([$this->template('iso.a5.1', 'policy', 'iso27001')], 'de');

        $result = $adapter->parse($json);

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame('1.0', $result['format_version']);
        self::assertCount(1, $result['entries']);
    }

    #[Test]
    public function parseRejectsInvalidJson(): void
    {
        $result = $this->adapter()->parse('{not json');

        self::assertFalse($result['valid']);
        self::assertNotEmpty($result['errors']);
    }

    #[Test]
    public function parseFlagsMissingRequiredFields(): void
    {
        $json = (string) json_encode([
            'pack' => ['format_version' => '1.0'],
            'entries' => [['document_type' => 'policy']], // missing "key"
        ]);

        $result = $this->adapter()->parse($json);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('key', implode(' ', $result['errors']));
    }

    #[Test]
    public function parseRejectsFutureFormatVersion(): void
    {
        $json = (string) json_encode(['pack' => ['format_version' => '99.0'], 'entries' => []]);

        $result = $this->adapter()->parse($json);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('Unsupported pack format', implode(' ', $result['errors']));
    }
}
