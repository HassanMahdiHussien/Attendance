<?php

namespace App\Statistics;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class ExcelReport
{
    public function render(array $report, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $first = true;
            $sections = $report['sections'];
            array_unshift($sections, ['title' => 'الملخص', 'headers' => ['المؤشر', 'القيمة'],
                'rows' => collect($report['summary'])->map(fn ($value, $key) => [$key, $value])->values()->all()]);
            foreach ($sections as $section) {
                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $sheet->setName(mb_substr($section['title'], 0, 31));
                $sheet->setSheetView((new SheetView)->setRightToLeft(true));
                $writer->addRow($this->row($section['headers'], true));
                foreach ($section['rows'] as $values) {
                    $writer->addRow($this->row($values));
                }
            }
        } finally {
            $writer->close();
        }
    }

    private function row(array $values, bool $header = false): Row
    {
        return new Row(array_map(fn ($value) => new StringCell((string) ($value ?? ''), null), $values),
            $header ? (new Style)->setFontBold() : null);
    }
}
