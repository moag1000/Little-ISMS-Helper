<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin dashboard's "recent activity" list used `log.action|trans`
 * without a domain — every action code was looked up in `messages`, where
 * none exists. Core CRUD actions now resolve in the audit_log domain.
 */
final class AuditLogActionLabelTest extends KernelTestCase
{
    private const array CORE_ACTIONS = ['create', 'update', 'delete', 'view', 'export', 'import', 'bulk'];

    #[Test]
    public function coreActionCodesAreTranslatedInBothLocales(): void
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['de', 'en'] as $locale) {
            foreach (self::CORE_ACTIONS as $action) {
                $key = 'audit_log.action_code.' . $action;
                self::assertNotSame($key, $translator->trans($key, [], 'audit_log', $locale), "$locale: $key missing");
            }
        }
    }

    #[Test]
    public function dashboardTranslatesActionsWithExplicitDomain(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/dashboard.html.twig');

        self::assertDoesNotMatchRegularExpression('/log\.action\|trans\s*}}/', $template);
        foreach (self::CORE_ACTIONS as $action) {
            self::assertStringContainsString("'" . $action . "'", $template);
        }
        self::assertStringContainsString("('audit_log.action_code.' ~ log.action)|trans({}, 'audit_log')", $template);
    }
}
