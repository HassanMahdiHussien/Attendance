<?php

namespace App\Services\Employees;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class EmployeeTemplate
{
    public function headers(): array
    {
        return __('messages.employee_template.headers');
    }

    public function xlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'employee-template-');
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Employees');
            $writer->addRow(Row::fromValues($this->headers()));
            $writer->addNewSheetAndMakeItCurrent()->setName('Help');
            $writer->addRow(Row::fromValues([__('messages.employee_template.arabic_header'), __('messages.employee_template.english_name'),
                __('messages.employee_template.example'), __('messages.employee_template.required')]));
            $english = __('messages.employee_template.fields');
            $examples = __('messages.employee_template.examples');
            foreach ($this->headers() as $index => $header) {
                $writer->addRow(Row::fromValues([$header, $english[$index], $examples[$index],
                    in_array($index, [0, 1, 4, 5], true) ? __('messages.employee_template.yes') : __('messages.employee_template.no')]));
            }
            $writer->close();

            return $path;
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }
}
