<?php

namespace App\Tests\Service;

use App\Service\ExcelExportService;
use PHPUnit\Framework\TestCase;

class ExcelExportServiceTest extends TestCase
{
    private ExcelExportService $service;

    protected function setUp(): void
    {
        $this->service = new ExcelExportService();
    }

    public function testCreateSpreadsheet(): void
    {
        $spreadsheet = $this->service->createSpreadsheet('Test Export');

        $this->assertEquals('Test Export', $spreadsheet->getProperties()->getTitle());
        $this->assertEquals('Little ISMS Helper', $spreadsheet->getProperties()->getCreator());
    }

    public function testExportArray(): void
    {
        $headers = ['Name', 'Email', 'Role'];
        $data = [
            ['John Doe', 'john@example.com', 'Admin'],
            ['Jane Smith', 'jane@example.com', 'User'],
        ];

        $spreadsheet = $this->service->exportArray($data, $headers, 'Users');

        $this->assertEquals('Users', $spreadsheet->getActiveSheet()->getTitle());
        $this->assertEquals('Name', $spreadsheet->getActiveSheet()->getCell('A1')->getValue());
        $this->assertEquals('John Doe', $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
        $this->assertEquals('jane@example.com', $spreadsheet->getActiveSheet()->getCell('B3')->getValue());
    }

    public function testGenerateExcel(): void
    {
        $spreadsheet = $this->service->createSpreadsheet('Test');
        $excel = $this->service->generateExcel($spreadsheet);

        $this->assertIsString($excel);
        $this->assertGreaterThan(0, strlen($excel));
    }
}
