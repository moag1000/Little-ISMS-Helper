<?php

declare(strict_types=1);

namespace App\Service\Tisax\Dto;

/**
 * Result DTO from VdaIsaWorkbookParser::parse().
 *
 * @phpstan-type HeaderMap array<string, int>
 */
final readonly class ParsedWorkbookResult
{
    /**
     * @param list<VdaIsaControlRow> $controls
     * @param array<string, int>     $detectedColumnMap  canonical => colIndex
     */
    public function __construct(
        public array $controls,
        public string $sheetName,
        public int $headerRowIndex,
        public array $detectedColumnMap,
    ) {}

    public function getControlCount(): int
    {
        return count($this->controls);
    }

    /**
     * Group controls by their TISAX tier (information_security, prototype_protection, data_protection).
     *
     * @return array<string, list<VdaIsaControlRow>>
     */
    public function groupByTier(): array
    {
        $groups = [];
        foreach ($this->controls as $control) {
            $groups[$control->getTier()][] = $control;
        }
        return $groups;
    }
}
