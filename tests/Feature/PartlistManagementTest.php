<?php

namespace Tests\Feature;

use App\Livewire\Partlists\Index;
use App\Models\Partlist;
use App\Services\PartlistLoadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PartlistManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_partlist_page_renders_with_records(): void
    {
        $partlist = Partlist::factory()->create();

        $this->get(route('partlists.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee($partlist->code);
    }

    public function test_material_order_point_is_po_day_times_parameter_divided_by_qty_kbn(): void
    {
        $partlist = Partlist::factory()->create(['code' => 'A1', 'process' => 'PG', 'po_day' => '40', 'qty_kbn' => 10]);
        Partlist::factory()->create(['code' => 'B1', 'process' => 'SW', 'po_day' => '70', 'qty_kbn' => 10]);

        $this->assertSame(4, $partlist->materialOrderPoint(1));
        $this->assertSame(12, $partlist->materialOrderPoint(3));
        $this->assertSame(1, Partlist::factory()->make(['process' => 'pg', 'po_day' => '5', 'qty_kbn' => 10])->materialOrderPoint(1));
        $this->assertSame(0, Partlist::factory()->make(['process' => 'SW', 'po_day' => '40', 'qty_kbn' => 10])->materialOrderPoint(3));
        $this->assertNull(Partlist::factory()->make(['process' => 'PG', 'po_day' => 'Senin', 'qty_kbn' => 10])->materialOrderPoint(1));
        $this->assertNull(Partlist::factory()->make(['process' => 'PG', 'po_day' => '40', 'qty_kbn' => 0])->materialOrderPoint(1));

        Livewire::test(Index::class)
            ->assertSeeText('MAT_ORDERPOINT')
            ->assertSeeText('4 KBN')
            ->assertSeeText('0 KBN')
            ->assertDontSeeText('7 KBN')
            ->set('orderPointDays', 3)
            ->assertSeeText('12 KBN')
            ->assertDontSeeText('21 KBN')
            ->set('orderPointDays', 99)
            ->assertSet('orderPointDays', 1)
            ->set('orderPointDays', 4);

        $this->assertSame(4, Partlist::orderPointDays());

        Livewire::test(Index::class)->assertSet('orderPointDays', 4);
    }

    public function test_loading_depends_on_cat_machine_and_process_percent(): void
    {
        $this->assertSame(30.0, Partlist::factory()->make(['cat_machine' => 'SPM', 'spm' => 60])->loading(50));
        $this->assertSame(0.5, Partlist::factory()->make(['cat_machine' => 'sph', 'spm' => 60])->loading(50));
        $this->assertSame(0.0, Partlist::factory()->make(['cat_machine' => '0', 'spm' => 60])->loading(100));
    }

    public function test_loading_percent_per_process_can_be_adjusted(): void
    {
        Partlist::factory()->create(['code' => 'A1', 'process' => 'PG', 'cat_machine' => 'SPM', 'spm' => 40]);
        Partlist::factory()->create(['code' => 'B1', 'process' => 'SW', 'cat_machine' => 'SPH', 'spm' => 120]);

        Livewire::test(Index::class)
            ->assertSeeText('LOADING')
            ->assertSeeText('40.00')
            ->assertSeeText('2.00')
            ->call('openLoadingSettings')
            ->assertSet('loadingParameters', [
                ['process' => 'PG', 'percent' => 100],
                ['process' => 'SW', 'percent' => 100],
            ])
            ->set('loadingParameters.0.percent', 0)
            ->call('saveLoadingSettings')
            ->assertHasErrors('loadingParameters.0.percent')
            ->set('loadingParameters.0.percent', 75)
            ->set('loadingParameters.1.percent', 50)
            ->call('saveLoadingSettings')
            ->assertHasNoErrors()
            ->assertSet('showLoadingSettings', false)
            ->assertSeeText('30.00')
            ->assertSeeText('1.00');

        $this->assertSame(['PG' => 75, 'SW' => 50], app(PartlistLoadingService::class)->percents());
    }

    public function test_partlist_can_be_created_with_code_generated_from_part_no_and_level(): void
    {
        $attributes = Partlist::factory()->make()->only(array_keys(Partlist::FIELDS));
        $attributes['code'] = '';

        Livewire::test(Index::class)
            ->call('create')
            ->set('form', $attributes)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('partlists', [
            'code' => $attributes['part_no'].$attributes['level'],
            'part_name' => $attributes['part_name'],
            'qty_kbn' => $attributes['qty_kbn'],
        ]);
    }

    public function test_partlist_code_must_be_unique(): void
    {
        $existing = Partlist::factory()->create();
        $attributes = Partlist::factory()->make(['code' => $existing->code])->only(array_keys(Partlist::FIELDS));

        Livewire::test(Index::class)
            ->set('form', $attributes)
            ->call('save')
            ->assertHasErrors(['form.code' => 'unique']);
    }

    public function test_integer_fields_are_validated(): void
    {
        $attributes = Partlist::factory()->make()->only(array_keys(Partlist::FIELDS));

        Livewire::test(Index::class)
            ->set('form', $attributes)
            ->set('form.qty_kbn', 'abc')
            ->set('form.spm', -1)
            ->call('save')
            ->assertHasErrors(['form.qty_kbn' => 'integer', 'form.spm' => 'min']);
    }

    public function test_partlist_can_be_updated(): void
    {
        $partlist = Partlist::factory()->create();

        Livewire::test(Index::class)
            ->call('edit', $partlist->code)
            ->assertSet('editingCode', $partlist->code)
            ->set('form.part_name', 'UPDATED PART')
            ->set('form.stock_max', 10)
            ->set('form.po_day', 'Senin')
            ->call('save')
            ->assertHasNoErrors();

        $partlist->refresh();
        $this->assertSame('UPDATED PART', $partlist->part_name);
        $this->assertSame(10, $partlist->stock_max);
        $this->assertSame('Senin', $partlist->po_day);
    }

    public function test_partlist_can_be_deleted(): void
    {
        $partlist = Partlist::factory()->create();

        Livewire::test(Index::class)
            ->call('confirmDelete', $partlist->code)
            ->call('delete')
            ->assertSet('deletingCode', null);

        $this->assertModelMissing($partlist);
    }

    public function test_partlists_can_be_searched(): void
    {
        $match = Partlist::factory()->create(['part_name' => 'SAGARI HINOMOTO LOCK R']);
        $other = Partlist::factory()->create(['part_name' => 'HOOK SUB-ASSY']);

        Livewire::test(Index::class)
            ->set('search', 'HINOMOTO')
            ->assertSee($match->code)
            ->assertDontSee($other->code);
    }
}
