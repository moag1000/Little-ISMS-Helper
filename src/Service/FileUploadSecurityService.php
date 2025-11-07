<?php

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
 */
class FileUploadSecurityService
{
    // Security: Allowed MIME types (whitelist approach)
    private const ALLOWED_MIME_TYPES = [
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
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'zip', '7z',
    ];

    // Security: Maximum file size (10MB default)
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB in bytes

    // Security: Magic bytes for file type verification
    private const MAGIC_BYTES = [
        'pdf' => ['25504446'],  // %PDF
        'jpg' => ['ffd8ffe0', 'ffd8ffe1', 'ffd8ffe2'],  // JPEG
        'png' => ['89504e47'],  // PNG
        'gif' => ['47494638'],  // GIF
        'zip' => ['504b0304', '504b0506'],  // ZIP
        'docx' => ['504b0304'],  // DOCX (ZIP-based)
        'xlsx' => ['504b0304'],  // XLSX (ZIP-based)
    ];

    /**
     * Security: Validate uploaded file against all security checks
     *
     * @throws FileException if validation fails
     */
    public function validateUploadedFile(UploadedFile $file): void
    {
        // Security: Check file was uploaded without errors
        if (!$file->isValid()) {
            throw new FileException('File upload failed: ' . $file->getErrorMessage());
        }

        // Security: Check file size
        $this->validateFileSize($file);

        // Security: Validate file extension
        $this->validateExtension($file);

        // Security: Validate MIME type using finfo (server-side detection)
        $this->validateMimeType($file);

        // Security: Validate magic bytes (file signature)
        $this->validateMagicBytes($file);
    }

    /**
     * Security: Validate file size
     */
    private function validateFileSize(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $maxSizeMB = self::MAX_FILE_SIZE / (1024 * 1024);
            throw new FileException(
                sprintf('File size exceeds maximum allowed size of %dMB', $maxSizeMB)
            );
        }
    }

    /**
     * Security: Validate file extension (whitelist approach)
     */
    private function validateExtension(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new FileException(
                sprintf(
                    'File extension "%s" is not allowed. Allowed extensions: %s',
                    $extension,
                    implode(', ', self::ALLOWED_EXTENSIONS)
                )
            );
        }
    }

    /**
     * Security: Validate MIME type using finfo (not trusting client headers)
     */
    private function validateMimeType(UploadedFile $file): void
    {
        // Use finfo to detect actual MIME type from file content
        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new FileException(
                sprintf(
                    'File type "%s" is not allowed. File appears to be: %s',
                    $file->getClientOriginalExtension(),
                    $mimeType
                )
            );
        }
    }

    /**
     * Security: Validate magic bytes (file signature verification)
     *
     * This prevents attackers from uploading malicious files with fake extensions
     * by checking the actual file signature (magic bytes) at the beginning of the file.
     */
    private function validateMagicBytes(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // Skip magic byte check for file types without defined signatures
        if (!isset(self::MAGIC_BYTES[$extension])) {
            return;
        }

        $filePath = $file->getPathname();
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new FileException('Unable to read file for validation');
        }

        // Read first 8 bytes for magic byte verification
        $fileHeader = fread($handle, 8);
        fclose($handle);

        $fileHeaderHex = bin2hex($fileHeader);
        $expectedMagicBytes = self::MAGIC_BYTES[$extension];
        $isValid = false;

        foreach ($expectedMagicBytes as $magicByte) {
            if (str_starts_with($fileHeaderHex, $magicByte)) {
                $isValid = true;
                break;
            }
        }

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
    public function generateSafeFilename(UploadedFile $file): string
    {
        // Get original filename without extension
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // Security: Remove all special characters, keep only alphanumeric, dash, underscore
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);

        // Limit length
        $safeName = substr($safeName, 0, 50);

        // Security: Add unique identifier to prevent overwrites
        $uniqueId = uniqid('', true);

        // Get extension
        $extension = $file->getClientOriginalExtension();

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
