<?php

namespace App\Exports;

use App\Models\Partlist;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PartlistTemplate
{
    /**
     * Build the partlist import template and return the path of the generated .xlsx file.
     */
    public function create(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Partlist');

        $headers = collect(array_keys(Partlist::FIELDS))->map(fn (string $name) => Str::upper($name))->all();
        $example = [
            '800401OUTGOING', 'OUTGOING', 'SC SKI', '-', '800401', '800401', '', 'SAGARI HINOMOTO LOCK R',
            '6464', 3000, 'PG', 'PG 04', 'PG4', 'OG2-16-A', 24, '800401-PG 03', 2, 1, 2, '800401-OG.JPG',
            'PG 04', 'KASHIMA-OG', 60, 80, '800401/800402', 'Lembar', 1, 'SPM', 45, 17, 0, '',
        ];

        $sheet->fromArray($headers, null, 'A1');

        foreach (array_keys(Partlist::FIELDS) as $index => $name) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).'2';

            if (Partlist::FIELDS[$name]['type'] === 'string') {
                $sheet->setCellValueExplicit($cell, $example[$index], 'str');
            } else {
                $sheet->setCellValue($cell, $example[$index]);
            }

            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE0E7FF');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->freezePane('B2');

        $tempFile = tempnam(sys_get_temp_dir(), 'partlist');
        unlink($tempFile);
        $path = $tempFile.'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
