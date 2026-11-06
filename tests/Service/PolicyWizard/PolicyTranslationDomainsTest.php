<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\PolicyTranslationDomains;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyTranslationDomainsTest extends TestCase
{
    #[Test]
    public function every_policy_translation_file_belongs_to_a_standard(): void
    {
        $known = array_merge(...array_values(PolicyTranslationDomains::BY_STANDARD));
        foreach (glob(dirname(__DIR__, 3) . '/translations/policy_*.de.yaml') ?: [] as $file) {
            $domain = basename($file, '.de.yaml');
            if ($domain === 'policy_wizard') {
                continue; // wizard UI copy, not policy prose
            }
            self::assertContains($domain, $known, "{$domain} is in no PolicyTranslationDomains list — its text would never be found");
        }
    }

    #[Test]
    public function unknown_standards_fall_back_to_their_own_domain(): void
    {
        self::assertSame(['policy_foo'], PolicyTranslationDomains::for('foo'));
        self::assertSame([], PolicyTranslationDomains::for(null));
    }
}
