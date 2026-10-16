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

        return new NavigationSearch($urls, $auth, $modules);
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
}
