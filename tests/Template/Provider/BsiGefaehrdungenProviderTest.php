<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\Risk;
use App\Template\Provider\BsiGefaehrdungenProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BsiGefaehrdungenProviderTest extends TestCase
{
    #[Test]
    public function emitsExactlyOneTemplatePerLanguage(): void
    {
        $provider = new BsiGefaehrdungenProvider();
        $templates = iterator_to_array($provider->provide());

        $this->assertCount(2, $templates);
    }

    #[Test]
    public function eachTemplateContainsAll47BsiHazards(): void
    {
        $provider = new BsiGefaehrdungenProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(Risk::class, $template->entityClass);
            $this->assertCount(47, $template->items,
                'BSI elementare Gefährdungen G0.1 - G0.47 must be 47 entries');
        }
    }

    #[Test]
    public function everyItemTitleCarriesGZeroCode(): void
    {
        $provider = new BsiGefaehrdungenProvider();
        foreach ($provider->provide() as $template) {
            foreach ($template->items as $item) {
                $this->assertMatchesRegularExpression('/^G0\.\d{1,2}\s+/', $item['title']);
            }
        }
    }

    #[Test]
    public function codesAreUniqueWithinTemplate(): void
    {
        $provider = new BsiGefaehrdungenProvider();
        foreach ($provider->provide() as $template) {
            $codes = array_map(
                fn ($item) => explode(' ', $item['title'], 2)[0],
                $template->items,
            );
            $this->assertCount(count($codes), array_unique($codes),
                'BSI hazard codes must be unique');
        }
    }
}
