<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Entity\ApiToken;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Mcp\McpSession;
use App\Mcp\PersonalDataPolicy;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Suggestion\Kind\AbstractAiKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionKind;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\Suggestion\SuggestionResponsibility;
use App\Service\Suggestion\SuggestionTargetAccess;
use App\Repository\SuggestionRepository;
use App\Tests\Stub\PersonaResolverFactory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\Attribute\Route;

/**
 * S30 durable test over every service tagged `app.suggestion_kind` — a new
 * kind is covered without touching this file:
 *  - its {@see SuggestionKind::targetAccess()} names a real UI write (a POST
 *    action whose `#[IsGranted]` the queue can read and whose record
 *    parameter takes the kind's records), or a role with a reason;
 *  - `canDecide` = UI right: a record holder with only ROLE_USER decides
 *    exactly when the UI write needs no role beyond ROLE_USER;
 *  - drafts about records of people stay with the DPO role, in the list and
 *    over the assistant connection;
 *  - a suggestion about a record of another organisation is stale, never applied.
 */
final class SuggestionKindAccessContractTest extends KernelTestCase
{
    /** Kinds without a record: the organisation comes from the suggestion itself. */
    private const array NO_RECORD = [
        'register.' => 'register proposals and vacated roles are looked up within the suggestion\'s organisation',
    ];

    /**
     * Writes that are manager work in the UI (sweep F3): pinned, so a kind
     * cannot point at a looser action than the one it stands for.
     */
    private const array MANAGER_WRITES = [
        'ai.vvt_from_supplier' => 'VVT entry (ProcessingActivityController::new)',
        'ai.legal_requirement_from_party' => 'register adoption',
        'ai.board_summary' => 'management review edit',
        'ai.requirement_mapping' => 'requirement mapping',
        'register.role' => 'register adoption / archive',
    ];

    /** @return array<string, SuggestionKind> */
    private function kinds(): array
    {
        self::bootKernel();
        $kinds = self::getContainer()->get(SuggestionQueue::class)->kinds();
        self::assertNotEmpty($kinds);

        return $kinds;
    }

    #[Test]
    public function everyKindClassIsTagged(): void
    {
        $tagged = array_map(static fn (SuggestionKind $k): string => $k::class, array_values($this->kinds()));
        $base = dirname(__DIR__, 3) . '/src/Service/Suggestion/Kind/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Service\\Suggestion\\Kind\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($base), -4));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(SuggestionKind::class)) {
                continue;
            }
            self::assertContains($class, $tagged, $class . ' is a suggestion kind outside the queue.');
        }
    }

    #[Test]
    public function targetAccessNamesTheUiWrite(): void
    {
        $access = new SuggestionTargetAccess($this->createStub(Security::class));
        foreach ($this->kinds() as $key => $kind) {
            $target = $kind->targetAccess();
            if ($target->action === null) {
                self::assertNotNull($target->role, $key);
                self::assertMatchesRegularExpression('/^ROLE_[A-Z_]+$/', (string) $target->role, $key);
                self::assertNotSame('ROLE_USER', $target->role, $key . ': a role form is only for a right above every user');
                self::assertNotSame('', trim($target->reason), $key . ': a role instead of the UI action needs its reason');
                continue;
            }
            [$class, $method] = $target->action;
            self::assertTrue(method_exists($class, $method), sprintf('%s: %s::%s does not exist', $key, $class, $method));
            $routes = (new \ReflectionMethod($class, $method))->getAttributes(Route::class);
            self::assertNotSame([], $routes, sprintf('%s: %s::%s is not a route', $key, $class, $method));
            $methods = $routes[0]->newInstance()->methods;
            self::assertTrue(
                $methods === [] || in_array('POST', $methods, true),
                sprintf('%s: %s::%s does not write (no POST)', $key, $class, $method),
            );
            foreach ($access->rules($kind) as $rule) {
                self::assertNotNull($rule['attribute'], sprintf('%s: an #[IsGranted] of %s::%s cannot be checked for a suggestion', $key, $class, $method));
                if ($rule['subject'] && $rule['type'] !== null && $kind instanceof AbstractAiKind) {
                    foreach ($kind->subjectClasses() as $subjectClass) {
                        self::assertTrue(
                            is_a($subjectClass, $rule['type'], true),
                            sprintf('%s: %s::%s takes %s, the kind is about %s', $key, $class, $method, $rule['type'], $subjectClass),
                        );
                    }
                }
            }
        }
    }

    #[Test]
    public function aRecordHolderDecidesExactlyWhenTheUiLetsThemWrite(): void
    {
        $tenant = new Tenant();
        $kinds = $this->kinds();
        foreach (array_keys(self::MANAGER_WRITES) as $pinned) {
            self::assertArrayHasKey($pinned, $kinds, 'pinned kind renamed or removed: ' . $pinned);
        }
        foreach ($kinds as $key => $kind) {
            // The holder: ROLE_USER, responsible for the record, passes every record-bound rule
            // (RECORD_RESPONSIBLE, voter EDIT on own record), holds no other role.
            $security = $this->createStub(Security::class);
            $security->method('isGranted')->willReturnCallback(
                static fn (mixed $attribute): bool => $attribute === 'ROLE_USER'
                    || $attribute instanceof Expression
                    || (is_string($attribute) && !str_starts_with($attribute, 'ROLE_')),
            );
            $security->method('getUser')->willReturn(new User());
            $targetAccess = new SuggestionTargetAccess($security);
            $subject = $this->recordFor($kind, $targetAccess);
            $responsibility = $this->createStub(SuggestionResponsibility::class);
            $responsibility->method('subject')->willReturn($subject);
            $responsibility->method('of')->willReturn(Responsibility::roles('ROLE_MANAGER'));
            $resolver = $this->createStub(ResponsibilityResolver::class);
            $resolver->method('isResponsible')->willReturn(true);
            $modules = $this->createStub(ModuleConfigurationService::class);
            $modules->method('isModuleActiveForTenant')->willReturn(true);
            $queue = new SuggestionQueue(
                [$kind],
                $this->createStub(SuggestionRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AuditLogger::class),
                $security,
                $modules,
                $responsibility,
                $resolver,
                $targetAccess,
            );
            $suggestion = new Suggestion($tenant, $key, $kind->origin(), 'T', 'R', 'k');

            $floor = $targetAccess->roleFloor($kind);
            if (isset(self::MANAGER_WRITES[$key])) {
                self::assertContains('ROLE_MANAGER', $floor, $key . ': ' . self::MANAGER_WRITES[$key] . ' needs ROLE_MANAGER');
            }
            self::assertSame(
                $floor === [],
                $queue->canDecide($suggestion),
                sprintf('%s: the UI write needs %s, the queue must agree', $key, $floor === [] ? 'no role' : implode(', ', $floor)),
            );
        }
    }

    #[Test]
    public function draftsAboutPeopleStayWithTheDpoRole(): void
    {
        $kinds = $this->kinds();
        $policy = new PersonalDataPolicy(PersonaResolverFactory::create(), dirname(__DIR__, 3));
        $tenant = new Tenant();
        $checked = 0;
        foreach ($kinds as $key => $kind) {
            if (!$kind instanceof AbstractAiKind) {
                continue;
            }
            foreach ($kind->subjectClasses() as $class) {
                if (!SuggestionQueue::isAboutPeople($class)) {
                    continue;
                }
                $checked++;
                $suggestion = (new Suggestion($tenant, $key, SuggestionOrigin::Ai, 'T', 'R', 'k'))->setSubject($class, 1);

                self::assertFalse($this->reader(['ROLE_AUDITOR'], $kind)->isVisible($suggestion), $key . ': a reader without DPO');
                self::assertTrue($this->reader(['ROLE_AUDITOR', 'ROLE_DPO'], $kind)->isVisible($suggestion), $key . ': the DPO');
                self::assertSame([], $policy->suggestions([$suggestion], $this->session('ROLE_MANAGER', $tenant)), $key . ': MCP without DPO');
                self::assertSame([$suggestion], $policy->suggestions([$suggestion], $this->session('ROLE_DPO', $tenant)), $key . ': MCP for the DPO');
            }
        }
        self::assertGreaterThan(0, $checked, 'breach and data subject drafts are about people');
    }

    #[Test]
    public function aRecordOfAnotherOrganisationIsStale(): void
    {
        $own = new Tenant();
        $foreign = new Tenant();
        foreach ($this->kinds() as $key => $kind) {
            foreach (self::NO_RECORD as $prefix => $reason) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }
            $applier = $this->withForeignRecords($kind, $foreign);
            $suggestion = (new Suggestion($own, $key, $kind->origin(), 'T', 'R', 'k'))
                ->setSubject(\App\Entity\Risk::class, 1)
                ->setPayload(['log' => 1, 'requirement' => 1, 'controls' => [1], 'asset' => 1, 'questionnaire' => 1, 'entry' => 1]);
            try {
                $applier->apply($suggestion, new User(), 'Text');
                self::fail($key . ': a record of another organisation was applied');
            } catch (StaleSuggestionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A record instance the kind's UI write takes (or null when it takes none). */
    private function recordFor(SuggestionKind $kind, SuggestionTargetAccess $access): ?object
    {
        foreach ($access->rules($kind) as $rule) {
            if ($rule['subject'] && $rule['type'] !== null) {
                return (new \ReflectionClass($rule['type']))->newInstanceWithoutConstructor();
            }
        }

        return new \stdClass();
    }

    /** @param list<string> $roles */
    private function reader(array $roles, SuggestionKind $kind): SuggestionQueue
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => is_string($attribute) && in_array($attribute, $roles, true),
        );
        $security->method('getUser')->willReturn(new User());
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn(true);
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('isResponsible')->willReturn(false);
        $responsibility = $this->createStub(SuggestionResponsibility::class);
        $responsibility->method('of')->willReturn(Responsibility::roles('ROLE_MANAGER'));

        return new SuggestionQueue(
            [$kind],
            $this->createStub(SuggestionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLogger::class),
            $security,
            $modules,
            $responsibility,
            $resolver,
        );
    }

    private function session(string $role, Tenant $tenant): McpSession
    {
        $user = (new User())->setRoles([$role]);

        return new McpSession($user, $tenant, new ApiToken(), 'https://isms.example');
    }

    /**
     * The kind as the container builds it, but every record it loads (entity
     * manager, repositories) belongs to another organisation.
     */
    private function withForeignRecords(SuggestionKind $kind, Tenant $foreign): SuggestionKind
    {
        $container = self::getContainer();
        $foreignRecord = static function (string $class) use ($foreign): ?object {
            if (!class_exists($class)) {
                return null;
            }
            $record = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            for ($r = new \ReflectionClass($class); $r !== false; $r = $r->getParentClass()) {
                foreach ($r->getProperties() as $property) {
                    $type = $property->getType();
                    if ($property->isStatic() || !$type instanceof \ReflectionNamedType) {
                        continue;
                    }
                    if ($type->getName() === Tenant::class) {
                        $property->setValue($record, $foreign); // tenant, uploadTenant, …
                    } elseif ($property->getName() === 'id') {
                        $property->setValue($record, 1);
                    }
                }
            }

            return $record;
        };
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class): ?object => $foreignRecord($class));

        $constructor = (new \ReflectionClass($kind))->getConstructor();
        $args = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($name === EntityManagerInterface::class) {
                $args[] = $em;
            } elseif ($name !== null && is_a($name, ServiceEntityRepository::class, true)) {
                $entityClass = $container->get($name)->getClassName();
                $repository = $this->createStub($name);
                $repository->method('find')->willReturnCallback(static fn (): ?object => $foreignRecord($entityClass));
                $args[] = $repository;
            } elseif ($name !== null && $container->has($name)) {
                $args[] = $container->get($name);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            } elseif ($name !== null && (interface_exists($name) || class_exists($name))) {
                $args[] = $this->createStub($name);
            } else {
                self::fail(sprintf('%s: cannot build constructor argument $%s', $kind::class, $parameter->getName()));
            }
        }

        return (new \ReflectionClass($kind))->newInstanceArgs($args);
    }
}
