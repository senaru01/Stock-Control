<?php

namespace Tests\Feature;

use App\Livewire\StockParts\Index;
use App\Models\Partlist;
use App\Services\StockPartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StockPartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sos.url' => 'https://sos.test/sos/Ajax/tData',
            'services.sos.key' => 'secret-key',
            'cache.stores.array.serialize' => true,
        ]);
    }

    public function test_stock_part_page_shows_rows_from_api(): void
    {
        $this->fakeApi();

        $this->get(route('stock-parts.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('51057-KK050')
            ->assertSee('51905-KK010');

        Http::assertSent(fn (Request $request) => $request['table'] === 'tbl_stock_part'
            && $request['api'] === 'secret-key'
            && $request['menuid'] === 'stockstockpart');
    }

    public function test_rows_are_given_a_stock_status(): void
    {
        $this->fakeApi();

        $rows = collect(app(StockPartService::class)->get()['rows'])->keyBy('part_no');

        $this->assertSame(StockPartService::STATUS_SAFE, $rows['51057-KK050']['status']);
        $this->assertSame(StockPartService::STATUS_BELOW_MIN, $rows['51905-KK010']['status']);
        $this->assertSame(StockPartService::STATUS_BELOW_MIN, $rows['80001-OG']['status']);
    }

    public function test_negative_quantities_from_api_become_zero(): void
    {
        $this->fakeApi();

        $row = collect(app(StockPartService::class)->get()['rows'])->firstWhere('part_no', '80001-OG');

        $this->assertSame(0, $row['stock']);
        $this->assertSame(0, $row['stock_prod']);
    }

    public function test_max_from_partlist_is_converted_to_pcs_and_marks_over_stock(): void
    {
        $this->fakeApi();
        $this->createPartlist('51057-KK050', 'FINISH GOODS', qtyKbn: 25, stockMax: 2);
        $this->createPartlist('51905-KK010', 'FINISH GOODS', qtyKbn: 60, stockMax: 10);
        $this->createPartlist('51057-KK050', 'OUTGOING', qtyKbn: 1, stockMax: 1);

        $rows = collect(app(StockPartService::class)->get()['rows'])->keyBy('part_no');

        $this->assertSame(50, $rows['51057-KK050']['max_pcs']);
        $this->assertSame(2, $rows['51057-KK050']['max_kbn']);
        $this->assertSame(StockPartService::STATUS_OVER, $rows['51057-KK050']['status']);
        $this->assertSame(600, $rows['51905-KK010']['max_pcs']);
        $this->assertSame(StockPartService::STATUS_BELOW_MIN, $rows['51905-KK010']['status']);
        $this->assertNull($rows['80001-OG']['max_pcs']);
    }

    public function test_rows_without_partlist_max_are_never_over(): void
    {
        $this->fakeApi();
        $this->createPartlist('51057-KK050', 'FINISH GOODS', qtyKbn: 25, stockMax: 0);

        $row = collect(app(StockPartService::class)->get()['rows'])->firstWhere('part_no', '51057-KK050');

        $this->assertNull($row['max_pcs']);
        $this->assertSame(StockPartService::STATUS_SAFE, $row['status']);
    }

    public function test_api_response_is_cached(): void
    {
        $this->fakeApi();

        app(StockPartService::class)->get();
        app(StockPartService::class)->get();

        Http::assertSentCount(1);
    }

    public function test_rows_can_be_filtered_by_search_store_and_status(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->set('search', 'kk010')
            ->assertSee('51905-KK010')
            ->assertDontSee('51057-KK050')
            ->set('search', '')
            ->set('store', 'OUTGOING')
            ->assertSee('80001-OG')
            ->assertDontSee('51905-KK010')
            ->set('store', '')
            ->set('status', StockPartService::STATUS_BELOW_MIN)
            ->assertSee('51905-KK010')
            ->assertSee('80001-OG')
            ->assertDontSee('51057-KK050')
            ->assertSet('summary.total', 2);
    }

    public function test_auto_refresh_polls_the_api_with_the_selected_interval(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->assertDontSeeHtml('wire:poll')
            ->set('autoRefresh', true)
            ->set('refreshInterval', 180)
            ->assertSeeHtml('wire:poll.180s.keep-alive="autoRefreshTick"')
            ->call('autoRefreshTick');

        Http::assertSentCount(2);
    }

    public function test_auto_refresh_tick_does_nothing_when_disabled(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)->call('autoRefreshTick');

        Http::assertSentCount(1);
    }

    public function test_invalid_refresh_interval_falls_back_to_default(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->set('refreshInterval', 5)
            ->assertSet('refreshInterval', 60);
    }

    public function test_auto_refresh_stays_on_after_leaving_and_reopening_the_page(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->set('autoRefresh', true)
            ->set('refreshInterval', 120);

        Livewire::test(Index::class)
            ->assertSet('autoRefresh', true)
            ->assertSet('refreshInterval', 120)
            ->assertSeeHtml('wire:poll.120s.keep-alive="autoRefreshTick"');

        $this->get(route('stock-parts.index'))
            ->assertOk()
            ->assertSee('wire:poll.120s.keep-alive="autoRefreshTick"', false);
    }

    public function test_auto_refresh_stays_off_once_turned_off(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)->set('autoRefresh', true);
        Livewire::test(Index::class)->set('autoRefresh', false);

        Livewire::test(Index::class)
            ->assertSet('autoRefresh', false)
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_auto_refresh_settings_are_read_from_the_url(): void
    {
        $this->fakeApi();

        Livewire::withQueryParams(['auto' => '1', 'interval' => '240'])
            ->test(Index::class)
            ->assertSet('autoRefresh', true)
            ->assertSet('refreshInterval', 240)
            ->assertSeeHtml('wire:poll.240s.keep-alive="autoRefreshTick"');
    }

    public function test_total_stock_is_summarized_per_store(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->assertSet('summary.stock_by_store', ['FINISH GOODS' => 315, 'OUTGOING' => 0])
            ->assertSet('summary.stock', 315)
            ->set('store', 'FINISH GOODS')
            ->assertSet('summary.stock_by_store', ['FINISH GOODS' => 315]);
    }

    public function test_status_column_comes_after_stock_columns(): void
    {
        $this->assertSame(
            ['qty_kbn', 'std_min', 'max_kbn', 'max_pcs', 'stock', 'stock_prod', 'status'],
            array_slice(array_keys(Index::COLUMNS), 8, 7),
        );
    }

    public function test_rows_can_be_filtered_by_over_status(): void
    {
        $this->fakeApi();
        $this->createPartlist('51057-KK050', 'FINISH GOODS', qtyKbn: 25, stockMax: 2);

        Livewire::test(Index::class)
            ->set('status', StockPartService::STATUS_OVER)
            ->assertSee('51057-KK050')
            ->assertDontSee('51905-KK010')
            ->assertSet('summary.over', 1)
            ->assertSet('summary.total', 1);
    }

    public function test_refresh_bypasses_the_cache(): void
    {
        $this->fakeApi();

        Livewire::test(Index::class)
            ->call('refresh')
            ->assertHasNoErrors();

        Http::assertSentCount(2);
    }

    public function test_cached_rows_are_shown_when_api_fails(): void
    {
        Http::fake([
            'sos.test/*' => Http::sequence()
                ->push($this->apiPayload())
                ->push('Server Error', 500)
                ->push('Server Error', 500)
                ->push('Server Error', 500),
        ]);

        app(StockPartService::class)->get();
        $this->travel(10)->minutes();

        $result = app(StockPartService::class)->get();

        $this->assertTrue($result['stale']);
        $this->assertCount(3, $result['rows']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertStringNotContainsString('secret-key', $result['error']);
    }

    public function test_error_is_shown_when_api_fails_without_cache(): void
    {
        Http::fake(['sos.test/*' => Http::response('Server Error', 500)]);

        Livewire::test(Index::class)
            ->assertSee('API SOS merespons dengan status 500')
            ->assertSee('Tidak ada data stock part.');

        $this->assertNull(Cache::get(StockPartService::CACHE_KEY));
    }

    protected function createPartlist(string $partNo, string $level, int $qtyKbn, int $stockMax): Partlist
    {
        return Partlist::factory()->create([
            'code' => $partNo.$level,
            'part_no' => $partNo,
            'level' => $level,
            'qty_kbn' => $qtyKbn,
            'stock_min' => 0,
            'stock_max' => $stockMax,
        ]);
    }

    protected function fakeApi(): void
    {
        Http::fake(['sos.test/*' => Http::response($this->apiPayload())]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function apiPayload(): array
    {
        return [
            'data' => [
                $this->apiRow(['id' => '1', 'store' => 'FINISH GOODS', 'part_no' => '51057-KK050', 'std_min' => '25', 'stock' => '75']),
                $this->apiRow(['id' => '2', 'store' => 'FINISH GOODS', 'part_no' => '51905-KK010', 'std_min' => '600', 'stock' => '240']),
                $this->apiRow(['id' => '3', 'store' => 'OUTGOING', 'part_no' => '80001-OG', 'std_min' => '10', 'stock' => '-5', 'stock_prod' => '-3']),
            ],
            'options' => [],
            'files' => [],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, ?string>
     */
    protected function apiRow(array $overrides): array
    {
        return [
            'DT_RowId' => 'row_'.$overrides['id'],
            'rack_no' => 'B1-03-A',
            'process' => 'SW',
            'line' => 'SW 1.2',
            'part_no_fg' => $overrides['part_no'],
            'customer' => 'TMMIN P1',
            'initial_by' => null,
            'initial_date' => null,
            'initial_stock' => null,
            'stock_prod' => '0',
            'last_update_in' => '2026-10-09 08:28:55',
            'pic_update_in' => 'NANA ANDRIYANI',
            'last_update_out' => null,
            'pic_update_out' => null,
            'last_print' => null,
            ...$overrides,
        ];
    }
}
