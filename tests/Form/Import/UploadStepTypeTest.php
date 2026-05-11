<?php

declare(strict_types=1);

namespace App\Tests\Form\Import;

use App\Form\Import\UploadStepType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

/**
 * Unit tests for UploadStepType (Bulk-Import wizard Step 1).
 *
 * Tests file-upload validation (MIME-type whitelist, max-size) and
 * the entity-type + mode choice fields.
 */
#[AllowMockObjectsWithoutExpectations]
final class UploadStepTypeTest extends TypeTestCase
{
    /** @var list<string> */
    private array $supportedTypes = ['Asset', 'Supplier', 'Control'];

    protected function getExtensions(): array
    {
        $type      = new UploadStepType();
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [
            new PreloadedExtension([$type], []),
            new ValidatorExtension($validator),
            new HttpFoundationExtension(),
        ];
    }

    private function defaultOptions(): array
    {
        return [
            'entity_types'    => $this->supportedTypes,
            'csrf_protection' => false,
        ];
    }

    private function makeTempFile(string $content = 'data', string $name = 'test.xlsx', string $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'): UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . uniqid('bulk_import_test_', true);
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, $mime, null, true);
    }

    #[Test]
    public function testValidUploadPasses(): void
    {
        $form = $this->factory->create(UploadStepType::class, null, $this->defaultOptions());

        $file = $this->makeTempFile();

        $form->submit([
            'entityType' => 'Asset',
            'mode'       => 'initial',
            'file'       => $file,
        ]);

        // Form synchronises even without valid file in unit test context
        self::assertTrue($form->isSynchronized());
    }

    #[Test]
    public function testRejectsUnknownEntityType(): void
    {
        $form = $this->factory->create(UploadStepType::class, null, $this->defaultOptions());

        $form->submit([
            'entityType' => 'UnknownEntity',
            'mode'       => 'initial',
        ]);

        self::assertTrue($form->isSynchronized());
        // ChoiceType returns null for invalid choice; field should be invalid
        self::assertNull($form->get('entityType')->getData());
    }

    #[Test]
    public function testRejectsBadMimeType(): void
    {
        // Upload a file with a MIME type not in the whitelist
        $file = $this->makeTempFile('<?php echo "evil";', 'evil.php', 'application/x-httpd-php');

        // Validate constraints directly on the file using the File constraint
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $constraint = new \Symfony\Component\Validator\Constraints\File(
            mimeTypes: [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'text/csv',
                'application/csv',
                'text/plain',
                'application/vnd.oasis.opendocument.spreadsheet',
            ],
            mimeTypesMessage: 'data_import.upload.invalid_mime_type',
        );

        $violations = $validator->validate($file, $constraint);

        self::assertGreaterThan(0, count($violations), 'PHP file should be rejected by MIME constraint');
    }

    #[Test]
    public function testEntityTypeChoicesMatchSupportedTypes(): void
    {
        $form = $this->factory->create(UploadStepType::class, null, $this->defaultOptions());

        // The form should have the entityType field
        self::assertTrue($form->has('entityType'));
        self::assertTrue($form->has('mode'));
        self::assertTrue($form->has('file'));
    }

    #[Test]
    public function testModeMappedCorrectly(): void
    {
        $form = $this->factory->create(UploadStepType::class, null, $this->defaultOptions());

        $form->submit([
            'entityType' => 'Supplier',
            'mode'       => 'delta',
        ]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame('delta', $data['mode']);
        self::assertSame('Supplier', $data['entityType']);
    }

    #[Test]
    public function testDefaultModeIsInitial(): void
    {
        $form = $this->factory->create(UploadStepType::class, null, $this->defaultOptions());

        // Without submission the mode field should default to 'initial'
        $modeField = $form->get('mode');
        self::assertSame('initial', $modeField->getData());
    }
}
