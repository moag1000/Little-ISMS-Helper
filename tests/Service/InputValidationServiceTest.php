<?php

namespace App\Tests\Service;

use App\Service\InputValidationService;
use PHPUnit\Framework\TestCase;

class InputValidationServiceTest extends TestCase
{
    private InputValidationService $service;

    protected function setUp(): void
    {
        $this->service = new InputValidationService();
    }

    // XSS Prevention Tests

    public function testSanitizeForOutputEscapesHtmlEntities(): void
    {
        $input = '<script>alert("XSS")</script>';
        $result = $this->service->sanitizeForOutput($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testSanitizeForOutputEscapesQuotes(): void
    {
        $input = 'Test "quotes" and \'apostrophes\'';
        $result = $this->service->sanitizeForOutput($input);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&#039;', $result);
    }

    public function testSanitizeForOutputPreservesUtf8(): void
    {
        $input = 'Ü ö ä € 中文';
        $result = $this->service->sanitizeForOutput($input);

        // Should preserve multibyte characters
        $this->assertStringContainsString('Ü', $result);
        $this->assertStringContainsString('中文', $result);
    }

    // Email Validation Tests

    public function testValidateEmailAcceptsValidEmail(): void
    {
        $validEmails = [
            'test@example.com',
            'user.name@example.co.uk',
            'test+tag@example.com',
            'admin@subdomain.example.com'
        ];

        foreach ($validEmails as $email) {
            $result = $this->service->validateEmail($email);
            $this->assertEquals($email, $result, "Failed for: $email");
        }
    }

    public function testValidateEmailRejectsInvalidEmail(): void
    {
        $invalidEmails = [
            'not-an-email',
            '@example.com',
            'test@',
            'test @example.com',
            'test@exam ple.com',
            ''
        ];

        foreach ($invalidEmails as $email) {
            $result = $this->service->validateEmail($email);
            $this->assertNull($result, "Should reject: $email");
        }
    }

    // URL Validation Tests

    public function testValidateUrlAcceptsValidUrl(): void
    {
        $validUrls = [
            'https://example.com',
            'http://example.com/path',
            'https://subdomain.example.com:8080/path?query=value',
            'ftp://ftp.example.com'
        ];

        foreach ($validUrls as $url) {
            $result = $this->service->validateUrl($url);
            $this->assertNotNull($result, "Failed for: $url");
        }
    }

    public function testValidateUrlRejectsInvalidUrl(): void
    {
        $invalidUrls = [
            'not a url',
            'javascript:alert(1)',
            'http://',
            ''
        ];

        foreach ($invalidUrls as $url) {
            $result = $this->service->validateUrl($url);
            $this->assertNull($result, "Should reject: $url");
        }
    }

    // Integer Validation Tests

    public function testValidateIntegerAcceptsValidIntegers(): void
    {
        $validIntegers = [0, 1, -1, 42, -42, '123', '-456'];

        foreach ($validIntegers as $value) {
            $result = $this->service->validateInteger($value);
            $this->assertIsInt($result);
            $this->assertEquals((int)$value, $result);
        }
    }

    public function testValidateIntegerRejectsInvalidIntegers(): void
    {
        $invalidIntegers = ['abc', '12.5', '12a', '', null, []];

        foreach ($invalidIntegers as $value) {
            $result = $this->service->validateInteger($value);
            $this->assertNull($result);
        }
    }

    // Float Validation Tests

    public function testValidateFloatAcceptsValidFloats(): void
    {
        $validFloats = [0.0, 1.5, -2.5, '3.14', '-9.99', '42'];

        foreach ($validFloats as $value) {
            $result = $this->service->validateFloat($value);
            $this->assertIsFloat($result);
        }
    }

    public function testValidateFloatRejectsInvalidFloats(): void
    {
        $invalidFloats = ['abc', '12.5a', '', null, []];

        foreach ($invalidFloats as $value) {
            $result = $this->service->validateFloat($value);
            $this->assertNull($result);
        }
    }

    // Filename Sanitization Tests (Path Traversal Prevention)

    public function testSanitizeFilenameRemovesPathComponents(): void
    {
        $input = '../../etc/passwd';
        $result = $this->service->sanitizeFilename($input);

        $this->assertEquals('passwd', $result);
        $this->assertStringNotContainsString('..', $result);
        $this->assertStringNotContainsString('/', $result);
    }

    public function testSanitizeFilenameRemovesSpecialCharacters(): void
    {
        $input = 'file<>:"|?*.txt';
        $result = $this->service->sanitizeFilename($input);

        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringNotContainsString('|', $result);
        $this->assertStringNotContainsString('*', $result);
        $this->assertStringContainsString('.txt', $result);
    }

    public function testSanitizeFilenamePreventsHiddenFiles(): void
    {
        $input = '.htaccess';
        $result = $this->service->sanitizeFilename($input);

        $this->assertStringStartsWith('_', $result);
        $this->assertEquals('_.htaccess', $result);
    }

    public function testSanitizeFilenameLimitsLength(): void
    {
        $input = str_repeat('a', 300) . '.txt';
        $result = $this->service->sanitizeFilename($input);

        $this->assertLessThanOrEqual(255, strlen($result));
    }

    public function testSanitizeFilenameAllowsValidCharacters(): void
    {
        $input = 'valid_file-name.2024.txt';
        $result = $this->service->sanitizeFilename($input);

        $this->assertEquals('valid_file-name.2024.txt', $result);
    }

    // Slug Sanitization Tests

    public function testSanitizeSlugConvertsToLowercase(): void
    {
        $input = 'My Blog Post Title';
        $result = $this->service->sanitizeSlug($input);

        $this->assertEquals('my-blog-post-title', $result);
    }

    public function testSanitizeSlugReplacesSpacesWithDashes(): void
    {
        $input = 'hello world test';
        $result = $this->service->sanitizeSlug($input);

        $this->assertEquals('hello-world-test', $result);
    }

    public function testSanitizeSlugRemovesSpecialCharacters(): void
    {
        $input = 'hello@world#test!';
        $result = $this->service->sanitizeSlug($input);

        $this->assertEquals('hello-world-test', $result);
    }

    public function testSanitizeSlugRemovesLeadingTrailingDashes(): void
    {
        $input = '---hello-world---';
        $result = $this->service->sanitizeSlug($input);

        $this->assertEquals('hello-world', $result);
    }

    public function testSanitizeSlugLimitsLength(): void
    {
        $input = str_repeat('a', 150);
        $result = $this->service->sanitizeSlug($input);

        $this->assertLessThanOrEqual(100, strlen($result));
    }

    // IP Address Validation Tests

    public function testValidateIpAddressAcceptsValidIpv4(): void
    {
        $validIps = ['192.168.1.1', '10.0.0.1', '127.0.0.1', '8.8.8.8'];

        foreach ($validIps as $ip) {
            $result = $this->service->validateIpAddress($ip);
            $this->assertEquals($ip, $result);
        }
    }

    public function testValidateIpAddressAcceptsValidIpv6(): void
    {
        $validIps = ['::1', '2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'fe80::1'];

        foreach ($validIps as $ip) {
            $result = $this->service->validateIpAddress($ip);
            $this->assertNotNull($result);
        }
    }

    public function testValidateIpAddressRejectsInvalidIp(): void
    {
        $invalidIps = ['256.1.1.1', '192.168.1', 'not-an-ip', ''];

        foreach ($invalidIps as $ip) {
            $result = $this->service->validateIpAddress($ip);
            $this->assertNull($result);
        }
    }

    // Search Query Sanitization Tests

    public function testSanitizeSearchQueryRemovesControlCharacters(): void
    {
        $input = "test\x00\x01\x1F\x7Fquery";
        $result = $this->service->sanitizeSearchQuery($input);

        $this->assertEquals('testquery', $result);
        $this->assertStringNotContainsString("\x00", $result);
    }

    public function testSanitizeSearchQueryRemovesSqlWildcards(): void
    {
        $input = 'test%query_search';
        $result = $this->service->sanitizeSearchQuery($input);

        $this->assertEquals('testquerysearch', $result);
        $this->assertStringNotContainsString('%', $result);
        $this->assertStringNotContainsString('_', $result);
    }

    public function testSanitizeSearchQueryLimitsLength(): void
    {
        $input = str_repeat('a', 300);
        $result = $this->service->sanitizeSearchQuery($input);

        $this->assertEquals(255, strlen($result));
    }

    public function testSanitizeSearchQueryTrimsWhitespace(): void
    {
        $input = '  test query  ';
        $result = $this->service->sanitizeSearchQuery($input);

        $this->assertEquals('test query', $result);
    }

    // Date Validation Tests

    public function testValidateDateAcceptsValidDate(): void
    {
        $validDates = ['2024-01-15', '2023-12-31', '2025-06-30'];

        foreach ($validDates as $date) {
            $result = $this->service->validateDate($date);
            $this->assertInstanceOf(\DateTime::class, $result);
            $this->assertEquals($date, $result->format('Y-m-d'));
        }
    }

    public function testValidateDateRejectsInvalidDate(): void
    {
        $invalidDates = ['2024-13-01', '2024-02-30', 'not-a-date', ''];

        foreach ($invalidDates as $date) {
            $result = $this->service->validateDate($date);
            $this->assertNull($result);
        }
    }

    public function testValidateDateAcceptsCustomFormat(): void
    {
        $date = '15/01/2024';
        $result = $this->service->validateDate($date, 'd/m/Y');

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertEquals($date, $result->format('d/m/Y'));
    }

    // HTML Sanitization Tests

    public function testSanitizeHtmlAllowsSafeTags(): void
    {
        $input = '<p>Test</p><strong>Bold</strong><em>Italic</em>';
        $result = $this->service->sanitizeHtml($input);

        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    public function testSanitizeHtmlRemovesDangerousTags(): void
    {
        $input = '<script>alert("XSS")</script><p>Safe</p>';
        $result = $this->service->sanitizeHtml($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    public function testSanitizeHtmlRemovesAttributes(): void
    {
        $input = '<p onclick="alert(1)">Test</p>';
        $result = $this->service->sanitizeHtml($input);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    // Alphanumeric Validation Tests

    public function testValidateAlphanumericAcceptsValid(): void
    {
        $validInputs = ['abc123', 'ABC', '123', 'Test123'];

        foreach ($validInputs as $input) {
            $result = $this->service->validateAlphanumeric($input);
            $this->assertTrue($result, "Failed for: $input");
        }
    }

    public function testValidateAlphanumericRejectsInvalid(): void
    {
        $invalidInputs = ['abc-123', 'test_123', 'hello world', 'test!', ''];

        foreach ($invalidInputs as $input) {
            $result = $this->service->validateAlphanumeric($input);
            $this->assertFalse($result, "Should reject: $input");
        }
    }

    // Length Validation Tests

    public function testValidateLengthAcceptsWithinRange(): void
    {
        $this->assertTrue($this->service->validateLength('hello', 1, 10));
        $this->assertTrue($this->service->validateLength('test', 4, 4));
        $this->assertTrue($this->service->validateLength('', 0, 10));
    }

    public function testValidateLengthRejectsOutsideRange(): void
    {
        $this->assertFalse($this->service->validateLength('hello', 10, 20));
        $this->assertFalse($this->service->validateLength('hello world', 1, 5));
    }

    public function testValidateLengthHandlesMultibyteCharacters(): void
    {
        $input = 'Ü ö ä 中文'; // 7 characters
        $this->assertTrue($this->service->validateLength($input, 1, 10));
        $this->assertFalse($this->service->validateLength($input, 10, 20));
    }

    // JSON Sanitization Tests

    public function testSanitizeForJsonRemovesControlCharacters(): void
    {
        $input = "test\x00\x01\x1F\x7Fvalue";
        $result = $this->service->sanitizeForJson($input);

        $this->assertEquals('testvalue', $result);
    }

    public function testSanitizeForJsonHandlesArrays(): void
    {
        $input = ["test\x00value", "clean", "bad\x1Fdata"];
        $result = $this->service->sanitizeForJson($input);

        $this->assertIsArray($result);
        $this->assertEquals('testvalue', $result[0]);
        $this->assertEquals('clean', $result[1]);
        $this->assertEquals('baddata', $result[2]);
    }

    public function testSanitizeForJsonPreservesNonStringTypes(): void
    {
        $this->assertEquals(42, $this->service->sanitizeForJson(42));
        $this->assertEquals(3.14, $this->service->sanitizeForJson(3.14));
        $this->assertTrue($this->service->sanitizeForJson(true));
        $this->assertNull($this->service->sanitizeForJson(null));
    }

    // CSRF Token Format Validation Tests

    public function testValidateCsrfTokenFormatAcceptsValid(): void
    {
        // Valid CSRF token format (43 characters, alphanumeric + - _)
        $validToken = 'abcdefghijklmnopqrstuvwxyz0123456789-_ABCD';
        $result = $this->service->validateCsrfTokenFormat($validToken);

        $this->assertTrue($result);
    }

    public function testValidateCsrfTokenFormatRejectsInvalid(): void
    {
        $invalidTokens = [
            'too-short',
            'this-is-way-too-long-to-be-a-valid-csrf-token-format',
            'invalid@chars#in$token!1234567890123456',
            ''
        ];

        foreach ($invalidTokens as $token) {
            $result = $this->service->validateCsrfTokenFormat($token);
            $this->assertFalse($result, "Should reject: $token");
        }
    }

    // XSS Pattern Detection Tests

    public function testDetectXssPatternsDetectsScriptTags(): void
    {
        $maliciousInputs = [
            '<script>alert(1)</script>',
            '<SCRIPT>alert(1)</SCRIPT>',
            '<script src="evil.js"></script>'
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectXssPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectXssPatternsDetectsJavascriptProtocol(): void
    {
        $maliciousInputs = [
            'javascript:alert(1)',
            'JAVASCRIPT:void(0)',
            '<a href="javascript:alert(1)">Click</a>'
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectXssPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectXssPatternsDetectsEventHandlers(): void
    {
        $maliciousInputs = [
            '<img onerror="alert(1)">',
            '<body onload="alert(1)">',
            '<div onclick="malicious()"></div>'
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectXssPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectXssPatternsDetectsIframeAndObjects(): void
    {
        $maliciousInputs = [
            '<iframe src="evil.com"></iframe>',
            '<object data="evil.swf"></object>',
            '<embed src="evil.swf">'
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectXssPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectXssPatternsAcceptsSafeContent(): void
    {
        $safeInputs = [
            'This is a normal string',
            '<p>Safe HTML paragraph</p>',
            'Email: test@example.com'
        ];

        foreach ($safeInputs as $input) {
            $result = $this->service->detectXssPatterns($input);
            $this->assertFalse($result, "Should accept: $input");
        }
    }

    // SQL Injection Pattern Detection Tests

    public function testDetectSqlInjectionPatternsDetectsSqlKeywords(): void
    {
        $maliciousInputs = [
            "1 OR 1=1; DROP TABLE users",
            "'; DELETE FROM users--",
            "admin' OR '1'='1",
            "1 UNION SELECT * FROM passwords"
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectSqlInjectionPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectSqlInjectionPatternsDetectsComments(): void
    {
        $maliciousInputs = [
            "test'; --",
            "value /* comment */ SELECT",
            "input;--comment"
        ];

        foreach ($maliciousInputs as $input) {
            $result = $this->service->detectSqlInjectionPatterns($input);
            $this->assertTrue($result, "Should detect: $input");
        }
    }

    public function testDetectSqlInjectionPatternsAcceptsSafeContent(): void
    {
        $safeInputs = [
            'This is a normal search query',
            'User wants to select a product',
            'Inserting data into form',
            'test@example.com'
        ];

        foreach ($safeInputs as $input) {
            $result = $this->service->detectSqlInjectionPatterns($input);
            $this->assertFalse($result, "Should accept: $input");
        }
    }
}
