<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\Constraint\NoInternalIp;
use App\Validator\Constraint\NoInternalIpValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

class NoInternalIpValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): NoInternalIpValidator
    {
        return new NoInternalIpValidator();
    }

    public function testValidPublicUrl(): void
    {
        $this->validator->validate('https://example.com/path', new NoInternalIp());
        $this->assertNoViolation();
    }

    public function testNullValue(): void
    {
        $this->validator->validate(null, new NoInternalIp());
        $this->assertNoViolation();
    }

    public function testEmptyString(): void
    {
        $this->validator->validate('', new NoInternalIp());
        $this->assertNoViolation();
    }

    #[DataProvider('blockedIpProvider')]
    public function testBlocksInternalIp(string $url): void
    {
        $this->validator->validate($url, new NoInternalIp());
        $this->buildViolation('security.url.no_internal_ip')->assertRaised();
    }

    public static function blockedIpProvider(): array
    {
        return [
            'loopback v4' => ['http://127.0.0.1/'],
            'loopback v6' => ['http://[::1]/'],
            'private 10/8' => ['http://10.0.0.1/'],
            'private 172.16/12' => ['http://172.16.0.1/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'link-local 169.254/16' => ['http://169.254.169.254/'],
            'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
        ];
    }
}
