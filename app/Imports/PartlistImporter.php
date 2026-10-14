<?php

namespace App\Imports;

use App\Models\Partlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PartlistImporter
{
    /**
     * Alternative header spellings mapped to their partlist column.
     *
     * @var array<string, string>
     */
    protected const HEADER_ALIASES = [
        'qty_kanban' => 'qty_kbn',
        'qty_kbn' => 'qty_kbn',
        'cek_start_finish' => 'cek_startfinish',
        'cek_startfinish' => 'cek_startfinish',
        'part_number' => 'part_no',
        'part_number_fg' => 'part_no_fg',
    ];

    /** @var list<string> */
    public const REQUIRED_HEADERS = ['level', 'part_no'];

    /**
     * Import partlists from a spreadsheet file.
     *
     * @return array{created: int, updated: int, failed: int, errors: list<array{row: int, code: ?string, messages: list<string>}>, ignored_headers: list<string>}
     */
    public function import(string $path, string $extension, bool $updateExisting = true): array
    {
        $rows = $this->readRows($path, $extension);
        [$headerRowNumber, $columns, $ignoredHeaders] = $this->resolveHeader($rows);

        $errors = [];
        $validRows = [];
        $rowNumbersByCode = [];

        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber <= $headerRowNumber || $this->isEmptyRow($cells)) {
                continue;
            }

            $data = $this->mapRow($cells, $columns);
            $validator = Validator::make($data, Partlist::validationRules(uniqueCode: false), [], $this->attributeNames());

            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'code' => $data['code'], 'messages' => $validator->errors()->all()];

                continue;
            }

            if (isset($rowNumbersByCode[$data['code']])) {
                $errors[] = ['row' => $rowNumber, 'code' => $data['code'], 'messages' => ["Code duplikat dengan baris {$rowNumbersByCode[$data['code']]} di file yang sama."]];

                continue;
            }

            $rowNumbersByCode[$data['code']] = $rowNumber;
            $validRows[$data['code']] = $data;
        }

        $existingCodes = $this->existingCodes(array_keys($validRows));

        if (! $updateExisting) {
            foreach ($existingCodes as $code) {
                $errors[] = ['row' => $rowNumbersByCode[$code], 'code' => $code, 'messages' => ['Code sudah ada di database (opsi update dimatikan).']];
                unset($validRows[$code]);
            }
        }

        DB::transaction(function () use ($validRows) {
            foreach (array_chunk($validRows, 200) as $chunk) {
                Partlist::upsert($chunk, ['code'], array_values(array_diff(array_keys(Partlist::FIELDS), ['code'])));
            }
        });

        usort($errors, fn (array $a, array $b) => $a['row'] <=> $b['row']);
        $updated = $updateExisting ? count($existingCodes) : 0;

        return [
            'created' => count($validRows) - $updated,
            'updated' => $updated,
            'failed' => count($errors),
            'errors' => $errors,
            'ignored_headers' => $ignoredHeaders,
        ];
    }

    /**
     * Read every row of the first worksheet, keyed by spreadsheet row number.
     *
     * @return array<int, list<mixed>>
     */
    protected function readRows(string $path, string $extension): array
    {
        $readerType = match (strtolower($extension)) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => throw new InvalidArgumentException('Format file tidak didukung. Gunakan .xlsx, .xls, atau .csv.'),
        };

        $reader = IOFactory::createReader($readerType);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $sheet = $reader->load($path)->getSheet(0);
        $rows = [];

        foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
            $rows[$index + 1] = $cells;
        }

        return $rows;
    }

    /**
     * Find the header row and map spreadsheet column indexes to partlist columns.
     *
     * @param  array<int, list<mixed>>  $rows
     * @return array{0: int, 1: array<int, string>, 2: list<string>}
     */
    protected function resolveHeader(array $rows): array
    {
        foreach ($rows as $rowNumber => $cells) {
            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $columns = [];
            $ignoredHeaders = [];

            foreach ($cells as $index => $header) {
                if (blank($header)) {
                    continue;
                }

                $column = $this->normalizeHeader((string) $header);

                if ($column !== null && ! in_array($column, $columns, true)) {
                    $columns[$index] = $column;
                } else {
                    $ignoredHeaders[] = trim((string) $header);
                }
            }

            $missing = array_diff(self::REQUIRED_HEADERS, $columns);

            if ($missing !== []) {
                throw new InvalidArgumentException('Kolom wajib tidak ditemukan di header: '.Str::upper(implode(', ', $missing)).'.');
            }

            return [$rowNumber, $columns, $ignoredHeaders];
        }

        throw new InvalidArgumentException('File kosong atau tidak memiliki baris header.');
    }

    protected function normalizeHeader(string $header): ?string
    {
        $normalized = Str::of($header)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if (array_key_exists($normalized, Partlist::FIELDS)) {
            return $normalized;
        }

        return self::HEADER_ALIASES[$normalized] ?? null;
    }

    /**
     * Convert spreadsheet cells into partlist attributes.
     *
     * @param  list<mixed>  $cells
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    protected function mapRow(array $cells, array $columns): array
    {
        $data = [];

        foreach (Partlist::FIELDS as $name => $field) {
            $data[$name] = $field['type'] === 'integer' ? 0 : null;
        }

        foreach ($columns as $index => $column) {
            $value = $cells[$index] ?? null;

            $data[$column] = Partlist::FIELDS[$column]['type'] === 'integer'
                ? $this->toInteger($value)
                : $this->toText($value);
        }

        if (blank($data['code']) && filled($data['part_no']) && filled($data['level'])) {
            $data['code'] = $data['part_no'].$data['level'];
        }

        return $data;
    }

    protected function toText(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function toInteger(mixed $value): mixed
    {
        if (blank($value)) {
            return 0;
        }

        if (is_numeric($value) && floor((float) $value) === (float) $value) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $cells
     */
    protected function isEmptyRow(array $cells): bool
    {
        return collect($cells)->every(fn ($cell) => blank($cell));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    protected function existingCodes(array $codes): array
    {
        return collect($codes)
            ->chunk(500)
            ->flatMap(fn ($chunk) => Partlist::whereIn('code', $chunk->all())->pluck('code'))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(): array
    {
        return collect(Partlist::FIELDS)->map(fn (array $field) => Str::upper($field['label']))->all();
    }
}
