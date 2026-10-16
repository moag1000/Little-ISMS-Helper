<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * Security: File Upload Validation Service
 *
 * Implements comprehensive file upload security according to OWASP guidelines:
 * - MIME type validation (using finfo, not trusting client headers)
 * - File size limits
 * - Magic byte verification
 * - Extension whitelist
 * - Filename sanitization
 *
 * Profiles: the default profile covers document/evidence uploads. Uploads
 * with a narrower or different purpose pass a profile constant so they get
 * their own whitelist instead of bypassing the service:
 *  - PROFILE_BACKUP          backup restore files (.json / .gz / .zip)
 *  - PROFILE_TABULAR_IMPORT  bulk-import spreadsheets (.csv / .xlsx / .xls / .ods)
 */
class FileUploadSecurityService
{
    public const string PROFILE_DEFAULT = 'default';
    public const string PROFILE_BACKUP = 'backup';
    public const string PROFILE_TABULAR_IMPORT = 'tabular_import';

    /**
     * Non-default profiles: extension whitelist, server-detected MIME
     * whitelist and size limit.
     *
     * @var array<string, array{extensions: list<string>, mime_types: list<string>, max_size: int}>
     */
    private const array PROFILES = [
        self::PROFILE_BACKUP => [
            'extensions' => ['json', 'gz', 'zip'],
            'mime_types' => [
                'application/json',
                'text/plain',
                'application/gzip',
                'application/x-gzip',
                'application/zip',
                'application/x-zip-compressed',
            ],
            // Full-instance backups (format 2.0 ZIP with embedded files) are
            // far larger than documents; PHP's upload_max_filesize usually
            // binds first.
            'max_size' => 512 * 1024 * 1024,
        ],
        self::PROFILE_TABULAR_IMPORT => [
            'extensions' => ['csv', 'xlsx', 'xls', 'ods'],
            'mime_types' => [
                'text/csv',
                'application/csv',
                'text/plain',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/vnd.oasis.opendocument.spreadsheet',
            ],
            'max_size' => 10 * 1024 * 1024,
        ],
    ];

    // Security: Allowed MIME types (whitelist approach)
    private const array ALLOWED_MIME_TYPES = [
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',

        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',

        // Archives
        'application/zip',
        'application/x-zip-compressed',
        'application/x-7z-compressed',
    ];

    // Security: Allowed file extensions (whitelist)
    private const array ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'zip', '7z',
    ];

    // Security: Maximum file size (10MB default)
    private const int|float MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB in bytes

    // Security: Magic bytes for file type verification
    private const array MAGIC_BYTES = [
        'pdf' => ['25504446'],  // %PDF
        'jpg' => ['ffd8ffe0', 'ffd8ffe1', 'ffd8ffe2'],  // JPEG
        'png' => ['89504e47'],  // PNG
        'gif' => ['47494638'],  // GIF
        'zip' => ['504b0304', '504b0506'],  // ZIP
        'docx' => ['504b0304'],  // DOCX (ZIP-based)
        'xlsx' => ['504b0304'],  // XLSX (ZIP-based)
        'ods' => ['504b0304'],  // ODS (ZIP-based)
        'gz' => ['1f8b'],  // gzip
    ];

    /**
     * Security: Validate uploaded file and return result array
     *
     * @return array{valid: bool, error: string|null}
     */
    public function validateUpload(UploadedFile $uploadedFile, string $profile = self::PROFILE_DEFAULT): array
    {
        try {
            $this->validateUploadedFile($uploadedFile, $profile);
            return ['valid' => true, 'error' => null];
        } catch (FileException $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Security: Validate uploaded file against all security checks
     *
     * @param string $profile one of the PROFILE_* constants
     *
     * @throws FileException if validation fails
     */
    public function validateUploadedFile(UploadedFile $uploadedFile, string $profile = self::PROFILE_DEFAULT): void
    {
        $rules = $this->rules($profile);

        // Security: Check file was uploaded without errors
        if (!$uploadedFile->isValid()) {
            throw new FileException('File upload failed: ' . $uploadedFile->getErrorMessage());
        }

        // Security: Check file size
        $this->validateFileSize($uploadedFile, $rules['max_size']);

        // Security: Validate file extension
        $this->validateExtension($uploadedFile, $rules['extensions']);

        // Security: Validate MIME type using finfo (server-side detection)
        $this->validateMimeType($uploadedFile, $rules['mime_types']);

        // Security: Validate magic bytes (file signature)
        $this->validateMagicBytes($uploadedFile);
    }

    /**
     * Security: Validate file size
     */
    private function validateFileSize(UploadedFile $uploadedFile, int $maxSize): void
    {
        if ($uploadedFile->getSize() > $maxSize) {
            $maxSizeMB = $maxSize / (1024 * 1024);
            throw new FileException(
                sprintf('File size exceeds maximum allowed size of %dMB', $maxSizeMB)
            );
        }
    }

    /**
     * Security: Validate file extension (whitelist approach)
     */
    /**
     * @param list<string> $allowedExtensions
     */
    private function validateExtension(UploadedFile $uploadedFile, array $allowedExtensions): void
    {
        $extension = strtolower($uploadedFile->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new FileException(
                sprintf(
                    'File extension "%s" is not allowed. Allowed extensions: %s',
                    $extension,
                    implode(', ', $allowedExtensions)
                )
            );
        }
    }

    /**
     * Security: Validate MIME type using finfo (not trusting client headers)
     */
    /**
     * @param list<string> $allowedMimeTypes
     */
    private function validateMimeType(UploadedFile $uploadedFile, array $allowedMimeTypes): void
    {
        // Use finfo to detect actual MIME type from file content
        $mimeType = $uploadedFile->getMimeType();

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new FileException(
                sprintf(
                    'File type "%s" is not allowed. File appears to be: %s',
                    $uploadedFile->getClientOriginalExtension(),
                    $mimeType
                )
            );
        }
    }

    /**
     * @return array{extensions: list<string>, mime_types: list<string>, max_size: int}
     */
    private function rules(string $profile): array
    {
        if ($profile === self::PROFILE_DEFAULT) {
            return [
                'extensions' => self::ALLOWED_EXTENSIONS,
                'mime_types' => self::ALLOWED_MIME_TYPES,
                'max_size' => (int) self::MAX_FILE_SIZE,
            ];
        }

        return self::PROFILES[$profile] ?? throw new \InvalidArgumentException(sprintf('Unknown upload profile "%s".', $profile));
    }

    /**
     * Security: Validate magic bytes (file signature verification)
     *
     * This prevents attackers from uploading malicious files with fake extensions
     * by checking the actual file signature (magic bytes) at the beginning of the file.
     */
    private function validateMagicBytes(UploadedFile $uploadedFile): void
    {
        $extension = strtolower($uploadedFile->getClientOriginalExtension());

        // Skip magic byte check for file types without defined signatures
        if (!isset(self::MAGIC_BYTES[$extension])) {
            return;
        }

        $filePath = $uploadedFile->getPathname();
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new FileException('Unable to read file for validation');
        }

        // Read first 8 bytes for magic byte verification
        $fileHeader = fread($handle, 8);
        fclose($handle);

        $fileHeaderHex = bin2hex($fileHeader);
        $expectedMagicBytes = self::MAGIC_BYTES[$extension];
        $isValid = array_any($expectedMagicBytes, fn($magicByte): bool => str_starts_with($fileHeaderHex, (string) $magicByte));

        if (!$isValid) {
            throw new FileException(
                sprintf(
                    'File signature does not match expected type for .%s files. Possible file manipulation detected.',
                    $extension
                )
            );
        }
    }

    /**
     * Security: Generate safe filename
     *
     * Removes special characters and adds unique identifier to prevent:
     * - Path traversal attacks
     * - File overwrites
     * - Command injection via filenames
     */
    public function generateSafeFilename(UploadedFile $uploadedFile): string
    {
        // Get original filename without extension
        $originalName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

        // Security: Remove all special characters, keep only alphanumeric, dash, underscore
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);

        // Limit length
        $safeName = substr((string) $safeName, 0, 50);

        // Security: Add unique identifier to prevent overwrites
        $uniqueId = uniqid('', true);

        // Get extension
        $extension = $uploadedFile->getClientOriginalExtension();

        return sprintf('%s_%s.%s', $safeName, $uniqueId, $extension);
    }

    /**
     * Get maximum allowed file size in bytes
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    /**
     * Get allowed file extensions
     */
    public function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Get allowed MIME types
     */
    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }
}
