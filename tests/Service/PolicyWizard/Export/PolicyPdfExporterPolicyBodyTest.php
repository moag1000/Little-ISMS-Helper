<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Editable-policy-body integration in {@see PolicyPdfExporter}.
 *
 * Body resolution priority:
 *   1. `Document.policyBody` — persisted rendered body
 *   2. `substitutionVariables._body` — legacy snapshot
 *   3. `Document.description` — uploaded-file fallback
 *   4. empty stub
 *
 * Plus: post-generation edits surface a footer marker pointing the
 * auditor at the editor + edit date.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyPdfExporterPolicyBodyTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(
            dirname(__DIR__, 4) . '/templates',
        );
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);
        $this->twig->addFilter(new \Twig\TwigFilter('trans', static function (
            string $message,
            array $arguments = [],
            ?string $domain = null,
        ): string {
            // Special-case the locally_edited footer marker so the
            // arguments are inlined into a recognisable phrase. The
            // identity-translator default (last dot-segment) discards
            // arguments, but this test needs them visible in the PDF
            // text stream to assert on.
            if ($message === 'policy_wizard.export.footer.locally_edited') {
                $name = $arguments['%name%'] ?? '—';
                $date = $arguments['%date%'] ?? '—';
                return sprintf('Locally edited by %s on %s', (string) $name, (string) $date);
            }
            $parts = explode('.', $message);
            return ucfirst((string) end($parts));
        }));
        $this->twig->addGlobal('app', new class () {
            public function getRequest(): object
            {
                return new class () {
                    public function getLocale(): string
                    {
                        return 'en';
                    }
                };
            }
        });
    }

    #[Test]
    public function testExporterReadsPolicyBodyWhenSet(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument($tenant);
        $doc->setPolicyBody("# Tenant override heading\n\nUNIQUE_TENANT_BODY_MARKER");
        // The legacy fallbacks are also populated to prove the new
        // column wins precedence over them.
        $doc->setDescription("LEGACY_DESCRIPTION_BODY");
        $doc->setSubstitutionVariables(['_body' => 'LEGACY_VARS_BODY', '_title' => 'X']);

        $exporter = new PolicyPdfExporter($this->twig);
        $pdf = $exporter->exportDocument($doc, null);

        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('UNIQUE_TENANT_BODY_MARKER', $haystack);
        self::assertStringNotContainsString('LEGACY_DESCRIPTION_BODY', $haystack);
        self::assertStringNotContainsString('LEGACY_VARS_BODY', $haystack);
    }

    #[Test]
    public function testExporterFallsBackToLegacyPathsWhenPolicyBodyUnset(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument($tenant);
        // policyBody intentionally NULL — legacy fallback chain.
        $doc->setSubstitutionVariables(['_body' => 'LEGACY_VARS_BODY_FALLBACK', '_title' => 'X']);

        $exporter = new PolicyPdfExporter($this->twig);
        $pdf = $exporter->exportDocument($doc, null);

        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('LEGACY_VARS_BODY_FALLBACK', $haystack);
    }

    #[Test]
    public function testFooterCarriesLocallyEditedMarkerWhenEdited(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument($tenant);
        $doc->setPolicyBody("# A body");

        $editor = new User();
        $editor->setEmail('ciso@example.com');
        $editor->setFirstName('Anna');
        $editor->setLastName('Müller');
        $doc->setPolicyBodyEditedBy($editor);
        $doc->setPolicyBodyEditedAt(new DateTimeImmutable('2026-05-08 14:30:00'));

        $exporter = new PolicyPdfExporter($this->twig);
        $pdf = $exporter->exportDocument($doc, null);

        $haystack = $this->extractText($pdf);
        // The marker template is `… (zuletzt bearbeitet von %name% am
        // %date%)` — our stub trans filter inlines %name% / %date% so
        // the editor's full name + edit date should appear in the PDF
        // text stream.
        self::assertStringContainsString('Anna', $haystack);
        self::assertStringContainsString('2026-05-08', $haystack);
    }

    private function makeTenant(int $id = 11): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        $stub->method('getCode')->willReturn('TC');
        return $stub;
    }

    private function makeDocument(Tenant $tenant): Document
    {
        $template = new PolicyTemplate();
        $template->setKey('iso27001.access_control');
        $template->setStandard('iso27001');
        $template->setTopic('access_control');
        $template->setVersion(1);

        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('policy-test.md');
        $doc->setOriginalFilename('Access Control Policy');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1234);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setStatus('approved');
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-08 10:00:00'));
        $doc->setSha256Hash('abcdef1234567890');
        $doc->setGeneratedFromTemplate($template);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, 99);

        return $doc;
    }

    /**
     * Pull readable text out of a dompdf-rendered PDF buffer. Same
     * algorithm as the sibling PolicyPdfExporterTest — copied here so
     * each test file is self-contained.
     */
    private function extractText(string $pdf): string
    {
        $out = '';
        $offset = 0;
        while (($start = strpos($pdf, "stream\n", $offset)) !== false) {
            $start += strlen("stream\n");
            $end = strpos($pdf, "\nendstream", $start);
            if ($end === false) {
                break;
            }
            $blob = substr($pdf, $start, $end - $start);
            $offset = $end + strlen("\nendstream");

            $decoded = @gzuncompress($blob);
            if ($decoded === false) {
                $decoded = @gzinflate($blob);
            }
            if (!is_string($decoded)) {
                continue;
            }
            if (preg_match_all('/\(([^()\\\\]*)\)/', $decoded, $matches)) {
                foreach ($matches[1] as $literal) {
                    $out .= $literal . ' ';
                }
            }
        }
        return $out;
    }
}
