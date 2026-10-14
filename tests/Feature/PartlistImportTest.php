<?php

namespace Tests\Feature;

use App\Livewire\Partlists\Index;
use App\Models\Partlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PartlistImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_partlists_are_imported_from_excel(): void
    {
        $file = $this->makeExcel([
            ['Code', 'LEVEL', 'CUSTOMER_CODE', 'PART_NO', 'PART_NAME', 'TYPE_BOX', 'QTY_KBN', 'CAP_RACK', 'CEK_STARTFINISH', 'PO_Day'],
            ['53307-BZ170FINISH GOODS', 'FINISH GOODS', 'ADM SAP', '53307-BZ170', 'HOOK SUB-ASSY, HOOD LOCK', 'STEEL PALET', 50, 2, 5, 'Senin'],
            [null, 'OUTGOING', 'SC SKI', 800401, 'SAGARI HINOMOTO LOCK R', 6464, 3000, 24, null, null],
        ]);

        Livewire::test(Index::class)
            ->call('openImport')
            ->set('importFile', $file)
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('importResult.created', 2)
            ->assertSet('importResult.failed', 0);

        $this->assertDatabaseHas('partlists', ['code' => '53307-BZ170FINISH GOODS', 'qty_kbn' => 50, 'cek_startfinish' => 5, 'po_day' => 'Senin']);
        $this->assertDatabaseHas('partlists', ['code' => '800401OUTGOING', 'part_no' => '800401', 'type_box' => '6464', 'cek_startfinish' => 0, 'po_day' => null]);
    }

    public function test_existing_partlists_are_updated_when_enabled(): void
    {
        Partlist::factory()->create(['code' => '800401OUTGOING', 'part_no' => '800401', 'level' => 'OUTGOING', 'qty_kbn' => 10]);

        $file = $this->makeExcel([
            ['CODE', 'LEVEL', 'PART_NO', 'QTY_KBN'],
            ['800401OUTGOING', 'OUTGOING', '800401', 3000],
        ]);

        Livewire::test(Index::class)
            ->set('importFile', $file)
            ->call('import')
            ->assertSet('importResult.updated', 1)
            ->assertSet('importResult.created', 0);

        $this->assertDatabaseHas('partlists', ['code' => '800401OUTGOING', 'qty_kbn' => 3000]);
    }

    public function test_existing_partlists_are_skipped_when_update_is_disabled(): void
    {
        Partlist::factory()->create(['code' => '800401OUTGOING', 'qty_kbn' => 10]);

        $file = $this->makeExcel([
            ['CODE', 'LEVEL', 'PART_NO', 'QTY_KBN'],
            ['800401OUTGOING', 'OUTGOING', '800401', 3000],
        ]);

        Livewire::test(Index::class)
            ->set('importUpdateExisting', false)
            ->set('importFile', $file)
            ->call('import')
            ->assertSet('importResult.updated', 0)
            ->assertSet('importResult.failed', 1);

        $this->assertDatabaseHas('partlists', ['code' => '800401OUTGOING', 'qty_kbn' => 10]);
    }

    public function test_invalid_rows_are_reported_and_valid_rows_still_imported(): void
    {
        $file = $this->makeExcel([
            ['CODE', 'LEVEL', 'PART_NO', 'QTY_KBN'],
            ['A1', 'OUTGOING', 'A', 'banyak'],
            ['B1', 'OUTGOING', 'B', 5],
            ['B1', 'OUTGOING', 'B', 6],
        ]);

        $component = Livewire::test(Index::class)
            ->set('importFile', $file)
            ->call('import')
            ->assertSet('importResult.created', 1)
            ->assertSet('importResult.failed', 2);

        $this->assertSame([2, 4], array_column($component->get('importResult')['errors'], 'row'));
        $this->assertDatabaseMissing('partlists', ['code' => 'A1']);
        $this->assertDatabaseHas('partlists', ['code' => 'B1', 'qty_kbn' => 5]);
    }

    public function test_file_without_required_headers_is_rejected(): void
    {
        $file = $this->makeExcel([
            ['CODE', 'PART_NAME'],
            ['X1', 'Something'],
        ]);

        Livewire::test(Index::class)
            ->set('importFile', $file)
            ->call('import')
            ->assertHasErrors('importFile')
            ->assertSet('importResult', null);

        $this->assertDatabaseCount('partlists', 0);
    }

    public function test_non_excel_file_is_rejected(): void
    {
        Livewire::test(Index::class)
            ->set('importFile', UploadedFile::fake()->create('partlist.pdf', 10, 'application/pdf'))
            ->call('import')
            ->assertHasErrors(['importFile' => 'extensions']);
    }

    public function test_template_can_be_downloaded(): void
    {
        Livewire::test(Index::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('template-import-partlist.xlsx');
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function makeExcel(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'partlist-test');
        (new Xlsx($spreadsheet))->save($path);

        $file = UploadedFile::fake()->createWithContent('partlist.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }
}
