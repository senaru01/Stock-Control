<?php

namespace App\Exports;

use App\Models\DailyStock;
use App\Models\DailyStockItem;
use App\Models\Partlist;
use App\Services\DailyStockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\DataTable;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

/**
 * "Daily Stock Finish Good" workbook for one day: one row per finish goods part with that day's stock in
 * whole boxes and its coverage (days of delivery), plus a count of short parts per last process, and
 * resume sheets with the short parts of every recorded day of the month up to that day.
 */
class DailyStockReport
{
    /**
     * Part columns before the daily columns: [header, width].
     *
     * @var list<array{0: string, 1: float}>
     */
    protected const PART_COLUMNS = [
        ['NO', 6], ['PART NO', 18], ['PART NO FG', 18], ['MODEL', 14], ['PART NAME', 30], ['CUSTOMER', 14],
        ['QTY/BOX', 8], ['TYPE BOX', 10], ['ORDER (Pcs)', 12], ['DEL/DAY (Pcs)', 11], ['DEL/DAY (Box)', 11],
        ['ADDRESS RACK', 13], ['LAST PROCESS', 11], ['FIX MACHINE', 12],
    ];

    protected const WORKING_DAYS_CELL = '$C$4';

    protected const HEADER_FILL = 'FFDDEBF7';

    protected const LATEST_FILL = 'FFFFF2CC';

    protected const IDLE_FILL = 'FFF2F2F2';

    /**
     * Coverage thresholds of the resume tables: [title, below days].
     *
     * @var list<array{0: string, 1: float}>
     */
    protected const THRESHOLDS = [['STOCK < 1.5 DAY', 1.5], ['STOCK < 1.0 DAY', 1.0]];

    /**
     * Height in rows of each resume chart, placed under the resume tables.
     */
    protected const CHART_ROWS = 30;

    /**
     * Fill of the stacked bars, in row order (fixed, so a process keeps its color in every chart);
     * rows past the palette get Excel's automatic colors.
     *
     * @var list<string>
     */
    protected const CHART_COLORS = ['8EA9DB', 'ED7D31', '2E9AB0', '7030A0', '70AD47', 'A9D18E', 'C0504D', '4472C4', 'FFC000', '7F6000', 'BF8F00', '5B9BD5'];

    protected const CHART_TOTAL_COLOR = 'D98880';

    protected CarbonImmutable $date;

    protected Worksheet $sheet;

    protected int $firstDataRow;

    protected int $lastDataRow;

    public function __construct(string $date, protected int $workingDays, protected string $timezone)
    {
        $this->date = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    }

    /**
     * Build the workbook and return the path of the generated temporary .xlsx file.
     */
    public function create(): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $this->sheet = $spreadsheet->getActiveSheet();
        $this->sheet->setTitle(strtoupper($this->date->locale('en')->format('d M')));

        $parts = Partlist::query()
            ->where('level', DailyStockService::STORE)
            ->orderByRaw("case when rack_no is null or rack_no in ('', '-') then 1 else 0 end")
            ->orderBy('rack_no')
            ->orderBy('part_no')
            ->get()
            ->each(fn (Partlist $part) => $part->setAttribute('last_process', DailyStockService::lastProcess($part->process, $part->line)));

        $dailyStocks = DailyStock::query()
            ->with('items')
            ->where('stock_date', $this->date->toDateString())
            ->get()
            ->keyBy(fn (DailyStock $dailyStock) => (int) substr($dailyStock->stock_date, 8, 2));

        $processes = $this->processes($parts);
        $latest = $dailyStocks->last();

        $this->writeTitle($latest);

        $headerRow = 6 + count($processes) + 6;
        $this->firstDataRow = $headerRow + 3;
        $this->lastDataRow = max($this->firstDataRow, $this->firstDataRow + $parts->count() - 1);

        // Both summaries side by side: < 1.5 day under the part columns, < 1.0 day next to it.
        $this->writeSummary(6, 13, 'STOCK < 1.5 DAY', 1.5, $processes, $dailyStocks);
        $this->writeSummary(6, 18, 'STOCK < 1.0 DAY', 1.0, $processes, $dailyStocks);
        $this->writeHeader($headerRow, $dailyStocks, $latest);
        $this->writeParts($parts, $dailyStocks, $latest);
        $this->styleTable($headerRow);
        $this->writeResumeSheets($spreadsheet);
        $spreadsheet->setActiveSheetIndex(0);

        $tempFile = tempnam(sys_get_temp_dir(), 'dailystock');
        unlink($tempFile);
        $path = $tempFile.'.xlsx';
        (new Xlsx($spreadsheet))->setIncludeCharts(true)->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->moveChartDataTables($path);

        return $path;
    }

    protected function writeTitle(?DailyStock $latest): void
    {
        $sheet = $this->sheet;

        $sheet->setCellValue('A1', 'DAILY STOCK FINISH GOOD');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $info = [
            2 => ['TANGGAL', strtoupper($this->date->translatedFormat('d F Y'))],
            3 => ['DATA DIAMBIL', $latest ? CarbonImmutable::parse($latest->stock_date)->format('d M Y').' · '.$latest->local_time.' WIB' : 'Belum ada data'],
            4 => ['HARI KERJA', $this->workingDays],
        ];

        foreach ($info as $row => [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        }

        $sheet->getStyle('C2:C4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LATEST_FILL);
        $sheet->setCellValue('D4', '← dipakai untuk ORDER = DEL/DAY × hari kerja');
        $sheet->getStyle('D4')->getFont()->setItalic(true)->getColor()->setARGB('FF808080');
    }

    /**
     * Count of parts whose coverage is below the threshold, per last process, for the report's day.
     * The counts are cumulative: a part below 1.0 day is also counted below 1.5 days.
     *
     * @param  list<string>  $processes
     * @param  Collection<int, DailyStock>  $dailyStocks
     */
    protected function writeSummary(int $row, int $firstColumn, string $title, float $threshold, array $processes, Collection $dailyStocks): void
    {
        $sheet = $this->sheet;
        $labelColumn = $this->column($firstColumn);
        $processColumn = $this->column($firstColumn + 1);
        $valueColumn = $this->column($firstColumn + 2);
        $lastColumn = $this->column($firstColumn + 3);

        $sheet->setCellValue("{$processColumn}{$row}", $title);
        $sheet->getStyle("{$processColumn}{$row}")->getFont()->setBold(true)->setSize(14);

        $dateRow = $row + 1;
        $headerRow = $row + 2;
        $firstProcessRow = $row + 3;
        $totalRow = $firstProcessRow + count($processes);

        $sheet->setCellValue("{$labelColumn}{$headerRow}", 'NO');
        $sheet->setCellValue("{$processColumn}{$headerRow}", 'LAST PROCESS');
        $sheet->setCellValue("{$valueColumn}{$dateRow}", ExcelDate::PHPToExcel($this->date->toDateString()));
        $sheet->setCellValue("{$valueColumn}{$headerRow}", 'PART');
        $sheet->setCellValue("{$processColumn}{$totalRow}", 'TOTAL:');

        for ($mergeRow = $dateRow; $mergeRow <= $totalRow; $mergeRow++) {
            $sheet->mergeCells("{$valueColumn}{$mergeRow}:{$lastColumn}{$mergeRow}");
        }

        $hasData = $dailyStocks->has($this->date->day);
        $processRange = "\$M\${$this->firstDataRow}:\$M\${$this->lastDataRow}";
        $deliveryRange = "\$K\${$this->firstDataRow}:\$K\${$this->lastDataRow}";
        $coverageColumn = $this->coverageColumn($this->date->day);
        $coverageRange = "\${$coverageColumn}\${$this->firstDataRow}:\${$coverageColumn}\${$this->lastDataRow}";

        foreach ($processes as $index => $process) {
            $processRow = $firstProcessRow + $index;
            $sheet->setCellValue("{$labelColumn}{$processRow}", $index + 1);
            $sheet->setCellValueExplicit("{$processColumn}{$processRow}", $process, DataType::TYPE_STRING);

            if ($hasData) {
                // The threshold sits outside the quotes ("<"&1.5) so Excel with a comma decimal locale still reads it as a number.
                $sheet->setCellValue("{$valueColumn}{$processRow}", "=COUNTIFS({$processRange},{$processColumn}{$processRow},{$deliveryRange},\">0\",{$coverageRange},\"<\"&{$threshold})");
            }
        }

        if ($hasData) {
            $sheet->setCellValue("{$valueColumn}{$totalRow}", "=SUM({$valueColumn}{$firstProcessRow}:{$valueColumn}".($totalRow - 1).')');
        }

        $sheet->getColumnDimension($labelColumn)->setWidth(max(8, $sheet->getColumnDimension($labelColumn)->getWidth()));
        $sheet->getColumnDimension($processColumn)->setWidth(max(16, $sheet->getColumnDimension($processColumn)->getWidth()));

        $range = "{$labelColumn}{$dateRow}:{$lastColumn}{$totalRow}";
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($range)->getFont()->setSize(12);
        $sheet->getStyle("{$labelColumn}{$dateRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("{$labelColumn}{$dateRow}:{$processColumn}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle("{$valueColumn}{$dateRow}:{$lastColumn}{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LATEST_FILL);
        $sheet->getStyle("{$labelColumn}{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("{$valueColumn}{$dateRow}")->getNumberFormat()->setFormatCode('dd-mmm');
        $sheet->getStyle("{$valueColumn}{$firstProcessRow}:{$valueColumn}{$totalRow}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
    }

    /**
     * @param  Collection<int, DailyStock>  $dailyStocks
     */
    protected function writeHeader(int $row, Collection $dailyStocks, ?DailyStock $latest): void
    {
        $sheet = $this->sheet;
        $subRow = $row + 1;
        $totalRow = $row + 2;

        foreach (self::PART_COLUMNS as $index => [$header, $width]) {
            $column = $this->column($index + 1);
            $sheet->setCellValue("{$column}{$row}", $header);
            $sheet->mergeCells("{$column}{$row}:{$column}{$subRow}");
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach ($this->days() as $day) {
            $stockColumn = $this->stockColumn($day);
            $coverageColumn = $this->coverageColumn($day);

            $sheet->mergeCells("{$stockColumn}{$row}:{$coverageColumn}{$row}");
            $sheet->setCellValue("{$stockColumn}{$row}", ExcelDate::PHPToExcel($this->date->toDateString()));
            $sheet->setCellValue("{$stockColumn}{$subRow}", 'STOCK (Box)');
            $sheet->setCellValue("{$coverageColumn}{$subRow}", "COVERAGE\nSTOCK");
            $sheet->getColumnDimension($stockColumn)->setWidth(9);
            $sheet->getColumnDimension($coverageColumn)->setWidth(10);

            if ($dailyStocks->has($day)) {
                $sheet->setCellValue("{$stockColumn}{$totalRow}", "=SUM({$stockColumn}{$this->firstDataRow}:{$stockColumn}{$this->lastDataRow})");
            } else {
                $sheet->getStyle("{$stockColumn}{$row}:{$coverageColumn}{$this->lastDataRow}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::IDLE_FILL);
            }
        }

        $sheet->setCellValue("B{$totalRow}", 'TOTAL');
        $sheet->setCellValue("{$this->column(9)}{$totalRow}", "=SUM({$this->column(9)}{$this->firstDataRow}:{$this->column(9)}{$this->lastDataRow})");

        $summaryHeaders = [
            ['TOTAL STOCK (Box)', 11], ["COVERAGE\nTOTAL", 10], ['STATUS', 9], ['STOCK (Pcs)', 11], ['STD MIN (Kbn)', 9], ['STD MAX (Kbn)', 9], ['REMARKS', 24],
        ];

        foreach ($summaryHeaders as $offset => [$header, $width]) {
            $column = $this->column($this->trailingColumn() + $offset);
            $sheet->setCellValue("{$column}{$row}", $header);
            $sheet->mergeCells("{$column}{$row}:{$column}{$subRow}");
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach ([0, 3] as $offset) {
            $column = $this->column($this->trailingColumn() + $offset);
            $sheet->setCellValue("{$column}{$totalRow}", "=SUM({$column}{$this->firstDataRow}:{$column}{$this->lastDataRow})");
        }

        $lastColumn = $this->column($this->trailingColumn() + count($summaryHeaders) - 1);
        $header = $sheet->getStyle("A{$row}:{$lastColumn}{$subRow}");
        $header->getFont()->setBold(true);
        $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle("{$this->stockColumn($this->date->day)}{$row}:{$this->coverageColumn($this->date->day)}{$row}")->getNumberFormat()->setFormatCode('d-mmm-yy');
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->getRowDimension($subRow)->setRowHeight(30);

        if ($latest !== null) {
            $day = (int) substr($latest->stock_date, 8, 2);
            $sheet->getStyle("{$this->stockColumn($day)}{$row}:{$this->coverageColumn($day)}{$subRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LATEST_FILL);
        }

        $sheet->setAutoFilter("A{$subRow}:{$lastColumn}{$this->lastDataRow}");
        $sheet->freezePane('C'.$this->firstDataRow);
    }

    /**
     * @param  Collection<int, Partlist>  $parts
     * @param  Collection<int, DailyStock>  $dailyStocks
     */
    protected function writeParts(Collection $parts, Collection $dailyStocks, ?DailyStock $latest): void
    {
        $sheet = $this->sheet;
        $stockByDay = $dailyStocks->map(fn (DailyStock $dailyStock) => $dailyStock->items->keyBy(fn (DailyStockItem $item) => mb_strtoupper($item->part_no)));
        $latestDay = $latest !== null ? (int) substr($latest->stock_date, 8, 2) : null;
        $trailing = $this->trailingColumn();

        foreach ($parts->values() as $index => $part) {
            $row = $this->firstDataRow + $index;
            $partKey = mb_strtoupper(trim($part->part_no));
            $qtyBox = (int) $part->qty_kbn;
            $deliveryPerDay = (int) $part->po_day;

            $sheet->setCellValue("A{$row}", $index + 1);

            foreach (['B' => $part->part_no, 'C' => $part->part_no_fg, 'D' => $part->model, 'E' => $part->part_name, 'F' => $part->customer_code] as $column => $value) {
                $sheet->setCellValueExplicit("{$column}{$row}", (string) $value, DataType::TYPE_STRING);
            }

            $sheet->setCellValue("G{$row}", $qtyBox);
            $sheet->setCellValueExplicit("H{$row}", (string) $part->type_box, DataType::TYPE_STRING);
            $sheet->setCellValue("I{$row}", $deliveryPerDay * $this->workingDays);
            $sheet->setCellValue("J{$row}", '=IFERROR(I'.$row.'/'.self::WORKING_DAYS_CELL.',0)');
            // PO/day is in pcs while stock is in boxes: convert the delivery to whole boxes per day (rounded up).
            $sheet->setCellValue("K{$row}", "=IF(G{$row}>0,ROUNDUP(J{$row}/G{$row},0),0)");
            $sheet->setCellValueExplicit("L{$row}", (string) $part->rack_no, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("M{$row}", (string) $part->last_process, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("N{$row}", (string) $part->line, DataType::TYPE_STRING);

            foreach ($stockByDay as $day => $items) {
                $item = $items->get($partKey);
                $boxSize = $item?->qty_box ?: $qtyBox;
                $stockColumn = $this->stockColumn($day);

                $sheet->setCellValue("{$stockColumn}{$row}", $boxSize > 0 ? intdiv(max(0, (int) ($item->stock_pcs ?? 0)), $boxSize) : 0);
                $sheet->setCellValue("{$this->coverageColumn($day)}{$row}", "=IF(\$K{$row}>0,{$stockColumn}{$row}/\$K{$row},\"\")");
            }

            $totalColumn = $this->column($trailing);
            $coverageColumn = $this->column($trailing + 1);

            if ($latestDay !== null) {
                $sheet->setCellValue("{$totalColumn}{$row}", "={$this->stockColumn($latestDay)}{$row}");
                $sheet->setCellValue("{$coverageColumn}{$row}", "=IF(\$K{$row}>0,{$totalColumn}{$row}/\$K{$row},\"\")");
                $sheet->setCellValue("{$this->column($trailing + 2)}{$row}", "=IF({$coverageColumn}{$row}=\"\",\"-\",IF({$coverageColumn}{$row}>5,\"OVER\",IF({$coverageColumn}{$row}<1.5,\"NOT OK\",\"OK\")))");
                $sheet->setCellValue("{$this->column($trailing + 3)}{$row}", (int) ($stockByDay[$latestDay]->get($partKey)?->stock_pcs ?? 0));
            }

            $sheet->setCellValue("{$this->column($trailing + 4)}{$row}", (int) $part->stock_min);
            $sheet->setCellValue("{$this->column($trailing + 5)}{$row}", (int) $part->stock_max);
            $sheet->setCellValueExplicit("{$this->column($trailing + 6)}{$row}", (string) $part->remark, DataType::TYPE_STRING);
        }
    }

    /**
     * "Resume Stock FG" sheets: per day of the report's month (up to the report's day), the short parts per
     * PG fix machine, the short parts per last process, and the stock in boxes of those short parts per last process.
     *
     * @param  Collection<int, Partlist>  $parts
     * @param  list<string>  $processes
     */
    protected function writeResumeSheets(Spreadsheet $spreadsheet): void
    {
        $resume = app(DailyStockService::class)->monthResume($this->date->toDateString());
        $recordedDays = $resume['recorded_days'];

        $sheets = [
            ['Resume Stock FG (PG-Item)', 'FIX MACHINE', 'jumlah part (item) last process PG per fix machine', DailyStockService::RESUME_PG_ITEM],
            ['Resume Stock FG (Item)', 'LAST PROCESS', 'jumlah part (item) per last process', DailyStockService::RESUME_ITEM],
            ['Resume Stock FG (Box)', 'LAST PROCESS', 'jumlah stock (box) per last process', DailyStockService::RESUME_BOX],
        ];

        foreach ($sheets as [$title, $rowLabel, $subtitle, $key]) {
            $rows = $resume['resumes'][$key]['rows'];

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->setCellValue('A1', 'Resume Data Stock Finish Good');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', strtoupper($this->date->locale('en')->format('F Y')).' · '.$subtitle);
            $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF808080');
            $sheet->getColumnDimension('A')->setWidth(6);
            $sheet->getColumnDimension('B')->setWidth(16);

            $row = 4;
            $tables = [];

            foreach (self::THRESHOLDS as [$heading, $threshold]) {
                $values = collect($resume['resumes'][$key]['values'][(string) $threshold])->map(fn (array $days) => collect($days));

                $totalRow = $this->writeResumeTable($sheet, $row, $heading, $rowLabel, $rows, $values, $recordedDays);
                $tables[] = [$heading, $row + 1, $totalRow];
                $row = $totalRow + 3;
            }

            // Charts go under both tables so the table positions stay the same.
            foreach ($tables as [$heading, $headerRow, $totalRow]) {
                $this->addResumeChart($sheet, $heading, $key === DailyStockService::RESUME_PG_ITEM ? 'PG ' : '', $key === DailyStockService::RESUME_BOX ? 'Box' : 'Item', $headerRow, $totalRow, $row);
                $row += self::CHART_ROWS + 2;
            }

            $sheet->freezePane('C1');
        }
    }

    /**
     * Stacked columns per process (or machine) for every day of the month with the total as a labelled line
     * and the values in a data table under the chart, e.g. "Grafik Stock FG < 1.5 Day".
     */
    protected function addResumeChart(Worksheet $sheet, string $heading, string $scope, string $unit, int $headerRow, int $totalRow, int $topRow): void
    {
        $firstRow = $headerRow + 1;

        if ($totalRow <= $firstRow) {
            return;
        }

        $days = $this->date->daysInMonth;
        $lastColumn = $this->column(2 + $days);
        $reference = "'".str_replace("'", "''", $sheet->getTitle())."'!";
        $range = fn (int $row) => "{$reference}\$C\${$row}:\${$lastColumn}\${$row}";

        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range($headerRow), 'd', $days)];
        $labels = [];
        $values = [];

        foreach (range($firstRow, $totalRow - 1) as $index => $row) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$reference}\$B\${$row}", null, 1);
            $series = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range($row), null, $days);

            if (isset(self::CHART_COLORS[$index])) {
                $series->setFillColor(self::CHART_COLORS[$index]);
            }

            $values[] = $series;
        }

        $bars = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, range(0, count($values) - 1), $labels, $categories, $values);
        $bars->setPlotDirection(DataSeries::DIRECTION_COL);

        $total = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range($totalRow), null, $days, [], 'none');
        $total->setLineColorProperties(self::CHART_TOTAL_COLOR);
        $total->setLineWidth(2.25);
        $total->setLabelLayout((new Layout)->setShowVal(true)->setDLblPos('t'));
        $line = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$reference}\$B\${$totalRow}", null, 1)],
            $categories,
            [$total],
        );

        $plotArea = new PlotArea(null, [$bars, $line]);
        $plotArea->setDataTable(new DataTable);

        // One slot per day of the month.
        $xAxis = new Axis;
        $xAxis->setAxisType(Axis::AXIS_TYPE_DATE);
        $xAxis->setAxisNumberProperties('d');
        $xAxis->setAxisOption('baseTimeUnit', Axis::TIME_UNIT_DAYS);
        $xAxis->setAxisOption('majorTimeUnit', Axis::TIME_UNIT_DAYS);
        $xAxis->setAxisOption('major_unit', 1);
        // The dates already head the data table under the chart, so the axis itself shows no labels.
        $xAxis->setAxisOption('axis_labels', Axis::AXIS_LABELS_NONE);

        $title = 'Grafik Stock FG '.$scope.str_replace(['STOCK ', ' DAY'], ['', ' Day'], $heading);
        // Blank days (no recorded stock, e.g. holidays) are spanned so the total line never breaks.
        $chart = new Chart(
            'resume-'.$headerRow,
            new Title($title),
            null,
            $plotArea,
            true,
            DataSeries::EMPTY_AS_SPAN,
            null,
            new Title($unit),
            $xAxis,
        );
        $chart->setTopLeftPosition("A{$topRow}");
        $chart->setBottomRightPosition($this->column(3 + $days).($topRow + self::CHART_ROWS));
        $sheet->addChart($chart);
    }

    /**
     * PhpSpreadsheet writes a chart's data table between its two axes, where Excel ignores it; the schema
     * wants it after the last axis, so it is moved there in every chart of the saved workbook.
     */
    protected function moveChartDataTables(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open {$path} to fix its chart data tables.");
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $xml = $zip->getFromIndex($index);

            if (! str_starts_with($name, 'xl/charts/chart') || $xml === false || preg_match('#<c:dTable>.*?</c:dTable>#s', $xml, $match) !== 1) {
                continue;
            }

            $xml = str_replace($match[0], '', $xml);
            $lastAxisEnd = strrpos($xml, '</c:valAx>');

            if ($lastAxisEnd !== false) {
                $insertAt = $lastAxisEnd + strlen('</c:valAx>');
                $zip->addFromString($name, substr($xml, 0, $insertAt).$match[0].substr($xml, $insertAt));
            }
        }

        $zip->close();
    }

    /**
     * One resume table: a row per process (or machine), a column per day of the month and a total row.
     * Days without a recorded stock stay empty. Returns the total row.
     *
     * @param  list<string>  $rows
     * @param  Collection<string, Collection<int, int>>  $values
     * @param  list<int>  $recordedDays
     */
    protected function writeResumeTable(Worksheet $sheet, int $row, string $heading, string $rowLabel, array $rows, Collection $values, array $recordedDays): int
    {
        $headerRow = $row + 1;
        $firstRow = $row + 2;
        $totalRow = $firstRow + count($rows);
        $lastColumn = $this->column(2 + $this->date->daysInMonth);

        $sheet->setCellValue("A{$row}", $heading);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue("A{$headerRow}", 'NO');
        $sheet->setCellValue("B{$headerRow}", $rowLabel);
        $sheet->setCellValue("B{$totalRow}", 'TOTAL:');

        foreach ($rows as $index => $label) {
            $sheet->setCellValue('A'.($firstRow + $index), $index + 1);
            $sheet->setCellValueExplicit('B'.($firstRow + $index), $label, DataType::TYPE_STRING);
        }

        for ($day = 1; $day <= $this->date->daysInMonth; $day++) {
            $column = $this->column(2 + $day);
            $sheet->setCellValue("{$column}{$headerRow}", ExcelDate::PHPToExcel($this->date->setDay($day)->toDateString()));
            $sheet->getColumnDimension($column)->setWidth(8);

            if (! in_array($day, $recordedDays, true)) {
                $sheet->getStyle("{$column}{$firstRow}:{$column}{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::IDLE_FILL);

                continue;
            }

            foreach ($rows as $index => $label) {
                $sheet->setCellValue("{$column}".($firstRow + $index), $values->get($label)?->get($day) ?? 0);
            }

            $sheet->setCellValue("{$column}{$totalRow}", $rows === [] ? 0 : "=SUM({$column}{$firstRow}:{$column}".($totalRow - 1).')');
        }

        $range = "A{$headerRow}:{$lastColumn}{$totalRow}";
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle("C{$headerRow}:{$lastColumn}{$headerRow}")->getNumberFormat()->setFormatCode('d-mmm');
        $sheet->getStyle("C{$firstRow}:{$lastColumn}{$totalRow}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);

        $dayColumn = $this->column(2 + $this->date->day);
        $sheet->getStyle("{$dayColumn}{$headerRow}:{$dayColumn}{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LATEST_FILL);

        return $totalRow;
    }

    protected function styleTable(int $headerRow): void
    {
        $sheet = $this->sheet;
        $trailing = $this->trailingColumn();
        $lastColumn = $this->column($trailing + 6);
        $first = $this->firstDataRow;
        $last = $this->lastDataRow;

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$first}:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G'.($headerRow + 2).":J{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("K{$first}:K{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("G{$first}:H{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L{$first}:N{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($this->days() as $day) {
            $sheet->getStyle($this->stockColumn($day).($headerRow + 2).":{$this->stockColumn($day)}{$last}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
            $sheet->getStyle("{$this->coverageColumn($day)}{$first}:{$this->coverageColumn($day)}{$last}")->getNumberFormat()->setFormatCode('0.0');
        }

        $totalColumn = $this->column($trailing);
        $coverageColumn = $this->column($trailing + 1);
        $statusColumn = $this->column($trailing + 2);

        $sheet->getStyle("{$totalColumn}".($headerRow + 2).":{$totalColumn}{$last}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
        $sheet->getStyle("{$coverageColumn}{$first}:{$coverageColumn}{$last}")->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle($this->column($trailing + 3).($headerRow + 2).":{$this->column($trailing + 5)}{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("{$statusColumn}{$first}:{$statusColumn}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("{$statusColumn}{$first}:{$statusColumn}{$last}")->getFont()->setBold(true);

        $coverageRanges = collect($this->days())
            ->map(fn (int $day) => "{$this->coverageColumn($day)}{$first}:{$this->coverageColumn($day)}{$last}")
            ->push("{$coverageColumn}{$first}:{$coverageColumn}{$last}");

        foreach ($coverageRanges as $range) {
            $sheet->getStyle($range)->setConditionalStyles([
                $this->lessThan(1.0, 'FFFFC7CE', 'FF9C0006'),
                $this->lessThan(1.5, 'FFFFEB9C', 'FF9C5700'),
            ]);
        }

        $sheet->getStyle("{$statusColumn}{$first}:{$statusColumn}{$last}")->setConditionalStyles([
            $this->textIs('NOT OK', 'FFFFC7CE', 'FF9C0006'),
            $this->textIs('OVER', 'FFFFEB9C', 'FF9C5700'),
            $this->textIs('OK', 'FFC6EFCE', 'FF006100'),
        ]);
    }

    protected function lessThan(float $value, string $fill, string $font): Conditional
    {
        $conditional = (new Conditional)
            ->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
            ->addCondition($value);
        $conditional->setStopIfTrue(true);
        $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setARGB($fill);
        $conditional->getStyle()->getFont()->getColor()->setARGB($font);

        return $conditional;
    }

    protected function textIs(string $text, string $fill, string $font): Conditional
    {
        $conditional = (new Conditional)
            ->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_EQUAL)
            ->addCondition("\"{$text}\"");
        $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setARGB($fill);
        $conditional->getStyle()->getFont()->getColor()->setARGB($font);

        return $conditional;
    }

    /**
     * Last processes found in the part list, in the preferred summary order.
     *
     * @param  Collection<int, Partlist>  $parts
     * @return list<string>
     */
    protected function processes(Collection $parts): array
    {
        return DailyStockService::orderProcesses($parts->pluck('last_process')->all());
    }

    /**
     * Only the report's own day is shown (no columns for the other dates of the month).
     *
     * @return list<int>
     */
    protected function days(): array
    {
        return [$this->date->day];
    }

    protected function stockColumn(int $day): string
    {
        return $this->column(count(self::PART_COLUMNS) + 1);
    }

    protected function coverageColumn(int $day): string
    {
        return $this->column(count(self::PART_COLUMNS) + 2);
    }

    /**
     * Index of the first column after the day's columns.
     */
    protected function trailingColumn(): int
    {
        return count(self::PART_COLUMNS) + 3;
    }

    protected function column(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}
