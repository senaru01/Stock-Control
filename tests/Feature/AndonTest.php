<?php

namespace Tests\Feature;

use App\Livewire\Andon\Index;
use App\Livewire\Andon\Show;
use App\Models\Partlist;
use App\Services\AndonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AndonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sos.url' => 'https://sos.test/sos/Ajax/tData', 'services.sos.key' => 'secret-key']);

        Http::fake([
            'sos.test/*' => Http::response(['data' => [
                $this->apiRow('800401', 'OUTGOING', '9000'),
                $this->apiRow('800402', 'OUTGOING', '1000'),
                $this->apiRow('800402', 'OUTGOING', '500'),
                $this->apiRow('800402', 'FINISH GOODS', '99999'),
                $this->apiRow('53307-BZ170', 'FINISH GOODS', '100'),
                $this->apiRow('LOW-1', 'OUTGOING', '600'),
                $this->apiRow('NORMAL-1', 'OUTGOING', '4500'),
                $this->apiRow('MIN-1', 'OUTGOING', '3000'),
                $this->apiRow('NORMAL-2', 'OUTGOING', '6000'),
            ]]),
        ]);
    }

    /**
     * @return array<string, array{int, int, int, int, ?int, string, ?int, int, int}>
     */
    public static function gaugeCases(): array
    {
        return [
            'overstock fills the headroom row' => [9000, 3000, 2, 1, null, AndonService::STATUS_OVERSTOCK, 1, 3, 3],
            'exactly max is normal' => [6000, 3000, 2, 1, null, AndonService::STATUS_NORMAL, 1, 2, 3],
            'exactly prod point is red and reaches its row' => [3000, 3000, 2, 1, null, AndonService::STATUS_PRODUCTION_POINT, 1, 1, 3],
            'partial kanban above prod point rounds down to it' => [4500, 3000, 2, 1, null, AndonService::STATUS_PRODUCTION_POINT, 1, 1, 3],
            'above prod point is normal without an order point' => [60, 10, 10, 2, 0, AndonService::STATUS_NORMAL, 1, 3, 6],
            'between prod point and order point is order point' => [40, 10, 10, 2, 5, AndonService::STATUS_ORDER_POINT, 1, 2, 6],
            'exactly order point reaches its row' => [50, 10, 10, 2, 5, AndonService::STATUS_ORDER_POINT, 1, 3, 6],
            'above order point is normal' => [60, 10, 10, 2, 5, AndonService::STATUS_NORMAL, 1, 4, 6],
            'at prod point stays red even under the order point' => [20, 10, 10, 2, 5, AndonService::STATUS_PRODUCTION_POINT, 1, 1, 6],
            'zero prod point with empty stock is red' => [0, 50, 2, 0, null, AndonService::STATUS_PRODUCTION_POINT, null, 0, 3],
            'below prod point stays under its row' => [9000, 3000, 10, 6, null, AndonService::STATUS_PRODUCTION_POINT, 3, 2, 6],
            'just below a whole kanban is zero' => [2900, 3000, 2, 1, null, AndonService::STATUS_PRODUCTION_POINT, 1, 0, 3],
            'negative stock' => [-200, 50, 2, 1, null, AndonService::STATUS_PRODUCTION_POINT, 1, 0, 3],
            'stock equal to prod point and max is red' => [100, 50, 2, 2, null, AndonService::STATUS_PRODUCTION_POINT, 2, 2, 3],
            'over when prod point equals max' => [150, 50, 2, 2, null, AndonService::STATUS_OVERSTOCK, 2, 3, 3],
            'max above 100 uses 10 kbn per row' => [1200, 10, 150, 30, null, AndonService::STATUS_NORMAL, 3, 12, 16],
            'max 50 to 100 uses 5 kbn per row' => [300, 10, 60, 10, null, AndonService::STATUS_NORMAL, 2, 6, 13],
            'max below 10 uses 1 kbn per row' => [70, 10, 9, 2, null, AndonService::STATUS_NORMAL, 2, 7, 10],
            'missing qty per kanban' => [100, 0, 2, 1, null, AndonService::STATUS_NOT_SET, null, 0, 0],
            'missing max' => [100, 50, 0, 0, null, AndonService::STATUS_NOT_SET, null, 0, 0],
        ];
    }

    #[DataProvider('gaugeCases')]
    public function test_gauge_converts_pcs_to_kanban_rows(int $stockPcs, int $qtyKbn, int $max, int $productionPoint, ?int $orderPoint, string $status, ?int $productionRow, int $filledRows, int $rows): void
    {
        $gauge = app(AndonService::class)->gauge($stockPcs, $qtyKbn, $max, $productionPoint, $orderPoint);

        $this->assertSame($status, $gauge['status']);
        $this->assertSame($productionRow, $gauge['production_point_row']);
        $this->assertSame($filledRows, $gauge['filled_rows']);
        $this->assertSame($rows, $gauge['rows']);
    }

    public function test_sw_lot_is_the_shortfall_to_stock_max_and_never_negative(): void
    {
        $service = app(AndonService::class);
        $partlist = Partlist::factory()->make([
            'process' => 'SW', 'qty_kbn' => 10, 'qty_lot' => 3, 'stock_max' => 12,
            'cat_machine' => 'SPM', 'spm' => 5, 'po_day' => '20',
        ]);

        $planning = $service->planning($partlist, $service->gauge(50, 10, 12), 100);

        $this->assertSame(7, $planning['lot']);
        $this->assertSame(14.0, $planning['loading_time']);
        $this->assertSame(2.5, $planning['coverage_days']);

        $overstock = $service->gauge(200, 10, 12);

        $this->assertSame(AndonService::STATUS_OVERSTOCK, $overstock['status']);
        $this->assertSame(0, $service->planning($partlist, $overstock, 100)['lot']);
    }

    public function test_other_processes_use_qty_lot_and_have_no_loading_time_without_loading(): void
    {
        $service = app(AndonService::class);
        $gauge = $service->gauge(50, 10, 12);

        $pg = Partlist::factory()->make(['process' => 'PG', 'qty_kbn' => 10, 'qty_lot' => 3, 'cat_machine' => 'SPM', 'spm' => 5, 'po_day' => null]);
        $noLoading = Partlist::factory()->make(['process' => 'PG', 'qty_kbn' => 10, 'qty_lot' => 3, 'cat_machine' => 'CT']);

        $this->assertSame(['lot' => 3, 'loading_time' => 6.0, 'coverage_days' => null], $service->planning($pg, $gauge, 100));
        $this->assertNull($service->planning($noLoading, $gauge, 100)['loading_time']);
    }

    public function test_pg_cards_use_the_saved_order_point_days(): void
    {
        Partlist::factory()->create([
            'code' => 'NORMAL-1OUTGOING', 'part_no' => 'NORMAL-1', 'level' => 'OUTGOING', 'line' => 'PG 04', 'process' => 'PG',
            'qty_kbn' => 500, 'stock_max' => 20, 'prod_point' => 3, 'po_day' => '500',
        ]);

        $card = fn () => app(AndonService::class)->forLine('PG 04')['cards'][0];

        $this->assertSame(AndonService::STATUS_NORMAL, $card()['status']);
        $this->assertSame(1, $card()['order_point']);

        Partlist::saveOrderPointDays(10);

        $this->assertSame(AndonService::STATUS_ORDER_POINT, $card()['status']);
        $this->assertSame([10, 5], [$card()['order_point'], $card()['order_point_row']]);
    }

    public function test_non_pg_cards_are_normal_above_the_prod_point(): void
    {
        $this->createPartlist('NORMAL-1', 'OUTGOING', 'SW 8.1', process: 'SW')->update(['qty_kbn' => 500, 'stock_max' => 20, 'prod_point' => 3, 'po_day' => '500']);
        Partlist::saveOrderPointDays(10);

        $card = app(AndonService::class)->forLine('SW 8.1')['cards'][0];

        $this->assertSame(AndonService::STATUS_NORMAL, $card['status']);
        $this->assertNull($card['order_point_row']);
    }

    public function test_cards_show_store_line_loading_time_and_coverage_instead_of_min_max(): void
    {
        $this->createPartlist('800402', 'OUTGOING', 'PG 04');

        Livewire::test(Show::class, ['line' => 'PG 04'])
            ->assertSeeText('OUTGOING · PG 04')
            ->assertSeeText('Loading Time')
            ->assertSeeText('Cov. Stock')
            ->assertSeeText('Production Point')
            ->assertDontSeeText('Min / Max');
    }

    public function test_cards_match_stock_by_part_no_and_level_and_sum_duplicates(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');
        $this->createPartlist('800402', 'OUTGOING', 'PG 04');
        $this->createPartlist('999999', 'OUTGOING', 'PG 04');
        $this->createPartlist('53307-BZ170', 'FINISH GOODS', 'SW 8.1');

        $cards = collect(app(AndonService::class)->forLine('PG 04')['cards'])->keyBy('part_no');

        $this->assertCount(3, $cards);
        $this->assertSame(9000, $cards['800401']['stock_pcs']);
        $this->assertSame(3, $cards['800401']['stock_kbn']);
        $this->assertSame(0, $cards['800402']['stock_kbn']);
        $this->assertSame(AndonService::STATUS_OVERSTOCK, $cards['800401']['status']);
        $this->assertSame(1500, $cards['800402']['stock_pcs']);
        $this->assertSame(AndonService::STATUS_PRODUCTION_POINT, $cards['800402']['status']);
        $this->assertFalse($cards['999999']['has_stock_data']);
        $this->assertSame(0, $cards['999999']['stock_pcs']);
    }

    public function test_cards_with_zero_kanban_are_highlighted_but_missing_stock_cards_are_not(): void
    {
        $this->createPartlist('800402', 'OUTGOING', 'PG 04');
        $this->createPartlist('999999', 'OUTGOING', 'PG 04');

        $html = Livewire::test(Show::class, ['line' => 'PG 04'])->html();

        $this->assertSame(1, substr_count($html, 'bg-rose-500/15'));
        $this->assertMatchesRegularExpression('/wire:key="andon-800402OUTGOING"[^>]*bg-rose-500\/15/s', $html);
        $this->assertDoesNotMatchRegularExpression('/wire:key="andon-999999OUTGOING"[^>]*bg-rose-500\/15/s', $html);
    }

    public function test_andon_index_lists_lines(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');
        $this->createPartlist('53307-BZ170', 'FINISH GOODS', 'SW 8.1');

        $this->get(route('andon.index'))
            ->assertOk()
            ->assertSee('PG 04')
            ->assertSee(route('andon.show', 'SW 8.1'), false);
    }

    public function test_andon_line_page_shows_parts_and_overstock(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'SW 8.1');
        $this->createPartlist('53307-BZ170', 'FINISH GOODS', 'PG 04');

        $this->get(route('andon.show', 'SW 8.1'))
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSeeText('800401')
            ->assertSee('Overstock')
            ->assertDontSeeText('53307-BZ170');
    }

    public function test_andon_cards_show_part_no_but_not_part_name(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04', partName: 'SAGARI HINOMOTO LOCK R');

        Livewire::test(Show::class, ['line' => 'PG 04'])
            ->assertSeeText('800401')
            ->assertDontSeeText('SAGARI HINOMOTO LOCK R');
    }

    public function test_normal_cards_have_a_green_border(): void
    {
        $this->createPartlist('NORMAL-2', 'OUTGOING', 'PG 04');

        Livewire::test(Show::class, ['line' => 'PG 04'])
            ->assertSeeHtml('ring-emerald-500 ring-2');
    }

    public function test_cards_are_sorted_by_status_then_lowest_stock_with_missing_stock_last(): void
    {
        $this->createPartlist('999999', 'OUTGOING', 'PG 04');
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');
        $this->createPartlist('NORMAL-1', 'OUTGOING', 'PG 04');
        $this->createPartlist('MIN-1', 'OUTGOING', 'PG 04');
        $this->createPartlist('800402', 'OUTGOING', 'PG 04');
        $this->createPartlist('LOW-1', 'OUTGOING', 'PG 04');

        $order = array_column(app(AndonService::class)->forLine('PG 04')['cards'], 'part_no');

        $this->assertSame(['LOW-1', '800402', 'MIN-1', 'NORMAL-1', '800401', '999999'], $order);
    }

    public function test_cards_within_a_status_are_sorted_by_lowest_coverage_with_missing_coverage_last(): void
    {
        foreach (['LOW-1' => '100', 'NORMAL-1' => '1500', 'NORMAL-2' => '6000', 'MIN-1' => null] as $partNo => $poDay) {
            $this->createPartlist($partNo, 'OUTGOING', 'SW 8.1', process: 'SW')
                ->update(['qty_kbn' => 100, 'stock_max' => 100, 'prod_point' => 1, 'po_day' => $poDay]);
        }

        $cards = app(AndonService::class)->forLine('SW 8.1')['cards'];

        $this->assertSame([AndonService::STATUS_NORMAL], array_values(array_unique(array_column($cards, 'status'))));
        $this->assertSame(['NORMAL-2', 'NORMAL-1', 'LOW-1', 'MIN-1'], array_column($cards, 'part_no'));

        Livewire::test(Show::class, ['line' => 'SW 8.1'])->assertSeeHtml('bg-emerald-600');
    }

    public function test_andon_cards_can_be_filtered_by_status(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');
        $this->createPartlist('800402', 'OUTGOING', 'PG 04');

        Livewire::test(Show::class, ['line' => 'PG 04'])
            ->set('status', AndonService::STATUS_PRODUCTION_POINT)
            ->assertSeeText('800402')
            ->assertDontSeeText('800401');
    }

    public function test_hold_level_parts_are_not_monitored(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');
        $this->createPartlist('800402', 'HOLD', 'PG 04');
        $this->createPartlist('777777', 'HOLD', 'SERVICE LINE');

        $cards = app(AndonService::class)->forLine('PG 04')['cards'];

        $this->assertSame(['800401'], array_column($cards, 'part_no'));

        $this->get(route('andon.index'))
            ->assertOk()
            ->assertSeeText('PG 04')
            ->assertSeeText('1 part')
            ->assertDontSeeText('SERVICE LINE');

        $this->get(route('andon.show', 'SERVICE LINE'))->assertNotFound();
    }

    public function test_lines_are_grouped_under_their_dominant_process(): void
    {
        $this->createPartlist('A1', 'OUTGOING', 'SW 8.1', process: 'SW');
        $this->createPartlist('A2', 'OUTGOING', 'SW 8.1', process: 'SW');
        $this->createPartlist('A3', 'OUTGOING', 'SW 8.1', process: 'PULLING');
        $this->createPartlist('B1', 'OUTGOING', 'SW 1.1', process: 'SW');
        $this->createPartlist('C1', 'OUTGOING', 'PG 04', process: 'PG');
        $this->createPartlist('D1', 'OUTGOING', 'MISC', process: null);
        $this->createPartlist('E1', 'HOLD', 'SW 9.9', process: 'SW');

        $groups = app(AndonService::class)->lineGroups();

        $this->assertSame(['PG', 'SW', AndonService::UNGROUPED_PROCESS], array_keys($groups));
        $this->assertSame([['line' => 'SW 1.1', 'total' => 1], ['line' => 'SW 8.1', 'total' => 3]], $groups['SW']);
        $this->assertSame('SW', app(AndonService::class)->processForLine('SW 8.1'));
    }

    public function test_priority_processes_come_first_then_the_rest_alphabetically(): void
    {
        foreach (['ASSY', 'SW', 'BENDING', 'TD', 'PR', 'KASHIME', 'PG', null] as $index => $process) {
            $this->createPartlist("P{$index}", 'OUTGOING', "LINE-{$index}", process: $process);
        }

        $this->assertSame(
            ['PG', 'PR', 'TD', 'SW', 'ASSY', 'BENDING', 'KASHIME', AndonService::UNGROUPED_PROCESS],
            array_keys(app(AndonService::class)->lineGroups()),
        );
    }

    public function test_index_and_sidebar_show_lines_grouped_by_process(): void
    {
        $this->createPartlist('A1', 'OUTGOING', 'SW 8.1', process: 'SW');
        $this->createPartlist('C1', 'OUTGOING', 'PG 04', process: 'PG');

        $this->get(route('andon.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Process PG', 'PG 04', 'Process SW', 'SW 8.1'])
            ->assertSee('andon-process-SW', false);

        Livewire::test(Index::class)
            ->set('search', 'sw')
            ->assertSeeText('SW 8.1')
            ->assertDontSeeText('PG 04');
    }

    public function test_line_page_shows_its_process(): void
    {
        $this->createPartlist('A1', 'OUTGOING', 'SW 8.1', process: 'SW');

        Livewire::test(Show::class, ['line' => 'SW 8.1'])
            ->assertSeeText('Process SW');
    }

    public function test_line_links_open_a_new_tab_with_the_full_screen_andon_layout(): void
    {
        $this->createPartlist('800401', 'OUTGOING', 'PG 04');

        $this->get(route('andon.index'))
            ->assertSee('href="'.route('andon.show', 'PG 04').'"'."\n".'                        target="_blank"', false);

        $this->get(route('andon.show', 'PG 04'))
            ->assertOk()
            ->assertSeeText('800401')
            ->assertDontSeeText('Semua Line')
            ->assertSee('enterFullscreenOnFirstInteraction()', false);
    }

    public function test_unknown_line_returns_not_found(): void
    {
        $this->get(route('andon.show', 'TIDAK ADA'))->assertNotFound();
    }

    protected function createPartlist(string $partNo, string $level, string $line, ?string $partName = null, ?string $process = 'PG'): Partlist
    {
        return Partlist::factory()->create([
            'code' => $partNo.$level,
            'part_no' => $partNo,
            'process' => $process,
            'part_name' => $partName ?? 'PART '.$partNo,
            'level' => $level,
            'line' => $line,
            'qty_kbn' => 3000,
            'stock_max' => 2,
            'prod_point' => 1,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function apiRow(string $partNo, string $store, string $stock): array
    {
        return [
            'id' => (string) random_int(1, 99999),
            'store' => $store,
            'part_no' => $partNo,
            'line' => 'PG 04',
            'std_min' => '0',
            'stock' => $stock,
            'stock_prod' => '0',
        ];
    }
}
