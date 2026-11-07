<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\FlashMessageExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class FlashMessageExtensionTest extends TestCase
{
    private FlashMessageExtension $extension;

    protected function setUp(): void
    {
        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['tenant.flash.created' => 'Organisation angelegt.'], 'de', 'tenant');
        $translator->addResource('array', ['common.csrf_invalid' => 'Sitzung abgelaufen.'], 'de', 'messages');
        $translator->addResource('array', ['existing_frameworks.saved' => 'Auswahl gespeichert.'], 'de', 'setup_wizard');
        $this->extension = new FlashMessageExtension($translator);
    }

    #[Test]
    public function translatesABareKeyFromTheDomainNamedByItsFirstSegment(): void
    {
        self::assertSame('Organisation angelegt.', $this->extension->resolve('tenant.flash.created'));
    }

    #[Test]
    public function fallsBackToMessagesAndThenToAnyDomain(): void
    {
        self::assertSame('Sitzung abgelaufen.', $this->extension->resolve('common.csrf_invalid'));
        self::assertSame('Auswahl gespeichert.', $this->extension->resolve('existing_frameworks.saved'));
    }

    #[Test]
    public function leavesTranslatedTextUnknownKeysAndUrlsUnchanged(): void
    {
        self::assertSame('Gespeichert.', $this->extension->resolve('Gespeichert.'));
        self::assertSame('unknown.key.here', $this->extension->resolve('unknown.key.here'));
        self::assertSame('https://example.org/a.b', $this->extension->resolve('https://example.org/a.b'));
    }

    #[Test]
    public function joinsIterableMessages(): void
    {
        self::assertSame('Organisation angelegt., x', $this->extension->resolve(['tenant.flash.created', 'x']));
    }
}
