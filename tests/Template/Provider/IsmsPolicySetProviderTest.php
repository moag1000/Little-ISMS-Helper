<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\Document;
use App\Template\Provider\IsmsPolicySetProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IsmsPolicySetProviderTest extends TestCase
{
    #[Test]
    public function emitsSixPoliciesPerLanguage(): void
    {
        $provider = new IsmsPolicySetProvider();
        $templates = iterator_to_array($provider->provide());

        // 6 policies × 2 languages = 12 templates
        $this->assertCount(12, $templates);
    }

    #[Test]
    public function allPolicyTemplatesPrefillDocumentFields(): void
    {
        $provider = new IsmsPolicySetProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(Document::class, $template->entityClass);
            $this->assertSame('documents', $template->module);

            // Document required fields
            $this->assertArrayHasKey('filename', $template->prefill);
            $this->assertArrayHasKey('originalFilename', $template->prefill);
            $this->assertArrayHasKey('mimeType', $template->prefill);
            $this->assertArrayHasKey('filePath', $template->prefill);
            $this->assertArrayHasKey('category', $template->prefill);
            $this->assertArrayHasKey('policyBody', $template->prefill);

            // Policy body is non-trivial Markdown
            $this->assertGreaterThan(500, strlen($template->prefill['policyBody']),
                sprintf('%s policyBody too short to be a useful starter', $template->key));
            $this->assertStringContainsString('#', $template->prefill['policyBody']);
        }
    }

    #[Test]
    public function statusIsDraftForEveryTemplate(): void
    {
        $provider = new IsmsPolicySetProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame('draft', $template->prefill['status']);
        }
    }
}
