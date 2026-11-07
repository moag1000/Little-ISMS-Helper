<?php

declare(strict_types=1);

namespace App\Tests\Security\Persona;

use App\Entity\User;
use App\Security\Persona\Persona;
use App\Security\Voter\ReportAccessVoter;
use App\Tests\Stub\PersonaResolverFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PersonaResolverTest extends TestCase
{
    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setEmail('p' . md5(implode(',', $roles)) . '@example.com');
        $user->setRoles($roles);

        return $user;
    }

    /** @return iterable<string, array{list<string>, Persona, string}> */
    public static function personas(): iterable
    {
        yield 'plain user' => [[], Persona::USER, 'app_dashboard'];
        yield 'function owner' => [['ROLE_FUNCTION_OWNER'], Persona::RISK_OWNER, 'app_dashboard'];
        yield 'auditor' => [['ROLE_AUDITOR'], Persona::AUDITOR, 'app_dashboard_auditor'];
        yield 'manager' => [['ROLE_MANAGER'], Persona::MANAGER, 'app_dashboard'];
        yield 'ISB lands on the ISB cockpit' => [['ROLE_ISB'], Persona::ISB, 'app_dashboard_isb'];
        yield 'BCM lands on the BCM cockpit' => [['ROLE_BCM_OFFICER'], Persona::BCM_OFFICER, 'app_dashboard_bcm'];
        yield 'group BCM officer is a BCM officer' => [['ROLE_GROUP_BCM_OFFICER'], Persona::BCM_OFFICER, 'app_dashboard_bcm'];
        yield 'DPO lands on the DPO cockpit' => [['ROLE_DPO'], Persona::DPO, 'app_dashboard_dpo'];
        yield 'top management' => [['ROLE_TOP_MGMT'], Persona::TOP_MANAGEMENT, 'app_dashboard_board'];
        yield 'admin without persona role' => [['ROLE_ADMIN'], Persona::ADMIN, 'app_dashboard'];
        yield 'explicit persona wins over admin' => [['ROLE_ADMIN', 'ROLE_DPO'], Persona::DPO, 'app_dashboard_dpo'];
        yield 'CISO before ISB' => [['ROLE_ISB', 'ROLE_CISO'], Persona::CISO, 'app_dashboard_ciso'];
        yield 'group CISO' => [['ROLE_GROUP_CISO'], Persona::GROUP_CISO, 'app_dashboard'];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('personas')]
    public function primaryPersonaAndDashboard(array $roles, Persona $expected, string $dashboard): void
    {
        $resolver = PersonaResolverFactory::create();
        $persona = $resolver->primaryOf($this->user($roles));

        self::assertSame($expected, $persona);
        self::assertSame($dashboard, $persona->dashboardRoute());
    }

    #[Test]
    public function accessFollowsTheRoleHierarchy(): void
    {
        $resolver = PersonaResolverFactory::create();
        $isb = $this->user(['ROLE_ISB']);

        self::assertTrue($resolver->reaches($isb, 'ROLE_MANAGER'));
        self::assertTrue($resolver->reaches($isb, 'ROLE_AUDITOR'));
        self::assertFalse($resolver->reaches($isb, 'ROLE_ADMIN'));
        self::assertContains(Persona::MANAGER, $resolver->personasOf($isb));

        $admin = $this->user(['ROLE_ADMIN']);
        foreach ([Persona::CISO, Persona::ISB, Persona::BCM_OFFICER, Persona::TOP_MANAGEMENT] as $persona) {
            self::assertTrue($resolver->canSeeDashboardOf($admin, $persona), $persona->value);
        }

        $board = $this->user(['ROLE_TOP_MGMT']);
        self::assertFalse($resolver->reaches($board, 'ROLE_MANAGER'), 'top management stays read-only');
        self::assertFalse($resolver->canSeeDashboardOf($board, Persona::CISO));
        self::assertTrue($resolver->canSeeDashboardOf($this->user(['ROLE_CISO']), Persona::TOP_MANAGEMENT));
    }

    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function downloads(): iterable
    {
        yield 'auditor gets the audit package' => [['ROLE_AUDITOR'], ReportAccessVoter::AUDIT_PACKAGE, true];
        yield 'auditor gets the certification bundle' => [['ROLE_AUDITOR'], ReportAccessVoter::CERTIFICATION_BUNDLE, true];
        yield 'auditor does not get the board one-pager' => [['ROLE_AUDITOR'], ReportAccessVoter::BOARD_ONE_PAGER, false];
        yield 'top management gets the one-pager' => [['ROLE_TOP_MGMT'], ReportAccessVoter::BOARD_ONE_PAGER, true];
        yield 'top management gets the review output' => [['ROLE_TOP_MGMT'], ReportAccessVoter::REVIEW_OUTPUT, true];
        yield 'konzern auditor gets the bundle' => [['ROLE_KONZERN_AUDITOR'], ReportAccessVoter::CERTIFICATION_BUNDLE, true];
        yield 'ISB gets everything' => [['ROLE_ISB'], ReportAccessVoter::EVIDENCE_PACKAGE, true];
        yield 'manager gets everything' => [['ROLE_MANAGER'], ReportAccessVoter::QUARTERLY_TREND, true];
        yield 'plain user gets nothing' => [[], ReportAccessVoter::AUDIT_PACKAGE, false];
        yield 'function owner gets nothing' => [['ROLE_FUNCTION_OWNER'], ReportAccessVoter::CERTIFICATION_BUNDLE, false];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('downloads')]
    public function downloadRightsComeFromThePersona(array $roles, string $report, bool $allowed): void
    {
        self::assertSame($allowed, PersonaResolverFactory::create()->mayDownload($this->user($roles), $report));
    }

    #[Test]
    public function toursPutTheOwnPersonaFirstAndTheBasicTourLast(): void
    {
        $resolver = PersonaResolverFactory::create();

        self::assertSame(['isb', 'junior'], $resolver->toursFor($this->user(['ROLE_ISB'])));
        self::assertSame(['auditor', 'junior'], $resolver->toursFor($this->user(['ROLE_AUDITOR'])));
        self::assertSame(['board', 'junior'], $resolver->toursFor($this->user(['ROLE_TOP_MGMT'])));
        self::assertSame(['dpo', 'junior'], $resolver->toursFor($this->user(['ROLE_DPO'])));
        self::assertNotContains('board', $resolver->toursFor($this->user(['ROLE_ADMIN'])), 'admins do not inherit the Leitung role');
        self::assertSame('ciso', $resolver->toursFor($this->user(['ROLE_ADMIN']))[0]);
    }

    #[Test]
    public function everyPersonaDashboardHasAVoterAttributeRoundTrip(): void
    {
        foreach (Persona::cases() as $persona) {
            $attribute = $persona->voterAttribute();
            if ($attribute !== null) {
                self::assertSame($persona, Persona::fromVoterAttribute($attribute));
            }
        }
    }
}
