<?php

namespace App\Service;

use DateTime;

/**
 * Security: Input Validation Service
 *
 * Provides centralized input validation and sanitization to prevent:
 * - XSS attacks
 * - SQL injection (additional layer on top of ORM)
 * - Command injection
 * - Path traversal
 * - LDAP injection
 * - XML injection
 *
 * Following Symfony best practices and OWASP guidelines.
 */
class InputValidationService
{
    /**
     * Security: Sanitize string for safe output (XSS prevention)
     *
     * Note: Twig auto-escapes by default, but this can be used for additional safety
     */
    public function sanitizeForOutput(string $input): string
    {
        // Use ENT_QUOTES without ENT_HTML5 to get numeric entities (&#039; instead of &apos;)
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Security: Validate and sanitize email address
     */
    public function validateEmail(string $email): ?string
    {
        $sanitized = filter_var($email, FILTER_SANITIZE_EMAIL);

        // Reject if sanitization changed the email (e.g., spaces removed)
        if ($sanitized !== $email) {
            return null;
        }

        if (filter_var($sanitized, FILTER_VALIDATE_EMAIL)) {
            return $sanitized;
        }

        return null;
    }

    /**
     * Security: Validate and sanitize URL
     */
    public function validateUrl(string $url): ?string
    {
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    /**
     * Security: Validate integer
     */
    public function validateInteger(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Security: Validate float
     */
    public function validateFloat(mixed $value): ?float
    {
        if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Security: Sanitize filename for safe storage
     *
     * Prevents path traversal and command injection via filenames
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);

        // Remove special characters except dot, dash, underscore
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        // Limit length
        $filename = substr((string) $filename, 0, 255);

        // Prevent hidden files
        if (str_starts_with($filename, '.')) {
            return '_' . $filename;
        }

        return $filename;
    }

    /**
     * Security: Validate and sanitize slug (for URLs)
     */
    public function sanitizeSlug(string $slug): string
    {
        // Convert to lowercase
        $slug = strtolower($slug);

        // Replace spaces and special characters with dashes
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing dashes
        $slug = trim((string) $slug, '-');

        // Limit length
        $slug = substr($slug, 0, 100);

        return $slug;
    }

    /**
     * Security: Validate IP address
     */
    public function validateIpAddress(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return null;
    }

    /**
     * Security: Sanitize user input for search queries
     *
     * Removes potentially dangerous characters while preserving valid search terms
     */
    public function sanitizeSearchQuery(string $query): string
    {
        // Remove control characters
        $query = preg_replace('/[\x00-\x1F\x7F]/', '', $query);

        // Remove SQL wildcards to prevent abuse
        $query = str_replace(['%', '_'], '', $query);

        // Limit length
        $query = substr($query, 0, 255);

        return trim($query);
    }

    /**
     * Security: Validate date string
     */
    public function validateDate(string $date, string $format = 'Y-m-d'): ?DateTime
    {
        $dateTime = DateTime::createFromFormat($format, $date);

        if ($dateTime && $dateTime->format($format) === $date) {
            return $dateTime;
        }

        return null;
    }

    /**
     * Security: Sanitize HTML content (allows safe HTML tags)
     *
     * Use this for rich text editors - strips dangerous tags/attributes
     */
    public function sanitizeHtml(string $html): string
    {
        // Allow only safe HTML tags
        $allowedTags = '<p><br><strong><em><u><h1><h2><h3><ul><ol><li><a>';

        // Strip all tags except allowed ones
        $html = strip_tags($html, $allowedTags);

        // Additional sanitization for attributes (remove all for safety)
        // In production, use HTML Purifier library for better control
        $html = preg_replace('/<([a-z]+)([^>]*?)>/i', '<$1>', $html);

        return $html;
    }

    /**
     * Security: Validate alphanumeric string
     */
    public function validateAlphanumeric(string $input): bool
    {
        return ctype_alnum($input);
    }

    /**
     * Security: Validate string length
     */
    public function validateLength(string $input, int $min = 0, int $max = PHP_INT_MAX): bool
    {
        $length = mb_strlen($input, 'UTF-8');
        return $length >= $min && $length <= $max;
    }

    /**
     * Security: Sanitize for JSON output
     */
    public function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            // Remove null bytes and control characters
            return preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        }

        if (is_array($value)) {
            return array_map($this->sanitizeForJson(...), $value);
        }

        return $value;
    }

    /**
     * Security: Validate CSRF token format (basic check)
     *
     * Note: Actual CSRF validation is done by Symfony Security
     */
    public function validateCsrfTokenFormat(string $token): bool
    {
        // CSRF tokens should be alphanumeric with dashes/underscores, typically 40-48 characters
        return preg_match('/^[a-zA-Z0-9_-]{40,48}$/', $token) === 1;
    }

    /**
     * Security: Detect potential XSS patterns in input
     *
     * Returns true if suspicious content detected
     */
    public function detectXssPatterns(string $input): bool
    {
        $xssPatterns = [
            '/<script[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/on\w+\s*=/i',  // onclick, onerror, etc.
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/eval\(/i',
            '/expression\(/i',
        ];
        return array_any($xssPatterns, fn($pattern): int|false => preg_match($pattern, $input));
    }

    /**
     * Security: Detect potential SQL injection patterns
     *
     * Returns true if suspicious content detected
     * Note: Doctrine ORM already prevents SQL injection, this is extra layer
     */
    public function detectSqlInjectionPatterns(string $input): bool
    {
        $sqlPatterns = [
            // Classic quote-based injections: ' OR '1'='1, " OR 1=1, etc.
            '/[\'"\;]\s*(OR|AND)\s*[\'"\d]/i',

            // UNION-based attacks
            '/UNION.*SELECT/i',

            // SQL keywords with suspicious context (quotes, semicolons, operators)
            '/[\'";]\s*(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE)\b/i',

            // Comment-based injection
            '/;\s*--/i',
            '/\/\*.*\*\//s',

            // Multiple statements (stacked queries)
            '/;\s*(DROP|DELETE|UPDATE|INSERT)\b/i',
        ];
        return array_any($sqlPatterns, fn($pattern): int|false => preg_match($pattern, $input));
    }
}
