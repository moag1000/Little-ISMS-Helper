<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\ModuleConfigurationService;
use App\Service\Search\NavigationSearch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class NavigationSearchTest extends TestCase
{
    private function search(bool $granted, bool $modulesActive, ?UrlGeneratorInterface $urls = null): NavigationSearch
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($granted);
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn($modulesActive);
        if ($urls === null) {
            $urls = $this->createStub(UrlGeneratorInterface::class);
            $urls->method('generate')->willReturnCallback(static fn(string $route): string => '/' . $route);
        }

        return new NavigationSearch($urls, $auth, $modules, self::translator('de'));
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['de', 'en'] as $lang) {
            $translator->addResource('yaml', __DIR__ . '/../../../translations/search_nav.' . $lang . '.yaml', $lang, 'search_nav');
        }

        return $translator;
    }

    #[Test]
    public function returnsNavigationResultsWithGeneratedUrls(): void
    {
        $results = $this->search(true, true)->search('Benutzerverwaltung');

        $byTitle = array_column($results, null, 'title');
        self::assertArrayHasKey('Benutzerverwaltung', $byTitle);
        self::assertSame('/user_management_index', $byTitle['Benutzerverwaltung']['url']);
        self::assertSame('navigation', $byTitle['Benutzerverwaltung']['type']);
    }

    #[Test]
    public function hidesEntriesTheUserHasNoRoleFor(): void
    {
        self::assertSame([], $this->search(false, true)->search('Benutzerverwaltung'));
    }

    #[Test]
    public function skipsRoutesThatNeedMandatoryParameters(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willThrowException(new MissingMandatoryParametersException('r', ['id']));

        self::assertSame([], $this->search(true, true, $urls)->search('Benutzerverwaltung'));
    }

    #[Test]
    public function englishUiShowsEnglishLabelsAndStillFindsGermanTerms(): void
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn(string $route): string => '/' . $route);
        $search = new NavigationSearch($urls, $auth, $modules, self::translator('en'));

        $byTitle = array_column($search->search('Benutzerverwaltung'), null, 'title');
        self::assertArrayHasKey('User management', $byTitle);
        self::assertArrayHasKey('Organisation comparison', array_column($search->search('Organisationsvergleich'), null, 'title'));
    }

    #[Test]
    public function everyEntryHasGermanAndEnglishTexts(): void
    {
        $results = $this->search(true, true)->search('a');
        self::assertSame([], $results, 'single letters never match');

        foreach (['de', 'en'] as $lang) {
            $catalogue = self::translator($lang)->getCatalogue($lang)->all('search_nav');
            self::assertNotEmpty($catalogue);
            foreach ($catalogue as $key => $text) {
                self::assertStringNotContainsString('search_nav.', $text, $key . ' untranslated in ' . $lang);
            }
        }
    }
}
