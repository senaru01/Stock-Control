<?php

namespace Tests\Feature;

use App\Livewire\Recipients\Index;
use App\Mail\DailyStockReportMail;
use App\Models\DailyStock;
use App\Models\Partlist;
use App\Models\ReportRecipient;
use App\Services\DailyStockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReportRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sos.url' => 'https://sos.test/sos/Ajax/tData',
            'services.sos.key' => 'secret-key',
            'services.sos.history_timezone' => 'Asia/Jakarta',
        ]);

        Storage::fake('local');
        Mail::fake();

        Http::fake([
            'sos.test/*' => Http::response(['data' => [
                ['id' => '1', 'store' => 'FINISH GOODS', 'part_no' => 'A', 'stock' => '30', 'std_min' => '0', 'stock_prod' => '0'],
            ]]),
        ]);

        Partlist::factory()->create([
            'code' => 'AFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'A', 'qty_kbn' => 10, 'po_day' => '40', 'process' => 'SW',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_users_page_lists_recipients(): void
    {
        ReportRecipient::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        ReportRecipient::factory()->inactive()->create(['name' => 'Sari', 'email' => 'sari@example.com']);

        $this->get(route('recipients.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSeeText('Budi Santoso')
            ->assertSeeText('budi@example.com')
            ->assertSeeText('Nonaktif')
            ->assertSeeText('1 user aktif');
    }

    public function test_recipient_can_be_created_with_a_normalised_email(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', ' Budi Santoso ')
            ->set('email', ' Budi@Example.COM ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('report_recipients', ['name' => 'Budi Santoso', 'email' => 'budi@example.com', 'is_active' => true]);
    }

    public function test_recipient_requires_a_name_and_a_unique_valid_email(): void
    {
        ReportRecipient::factory()->create(['email' => 'budi@example.com']);

        Livewire::test(Index::class)
            ->set('name', '')
            ->set('email', 'bukan-email')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'email' => 'email']);

        Livewire::test(Index::class)
            ->set('name', 'Budi Lain')
            ->set('email', 'BUDI@example.com')
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_recipient_can_be_edited_toggled_and_deleted(): void
    {
        $recipient = ReportRecipient::factory()->create(['name' => 'Budi', 'email' => 'budi@example.com']);

        Livewire::test(Index::class)
            ->call('edit', $recipient->id)
            ->assertSet('email', 'budi@example.com')
            ->set('name', 'Budi Santoso')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Budi Santoso', $recipient->fresh()->name);

        Livewire::test(Index::class)->call('toggleActive', $recipient->id);
        $this->assertFalse($recipient->fresh()->is_active);

        Livewire::test(Index::class)->call('confirmDelete', $recipient->id)->call('delete');
        $this->assertModelMissing($recipient);
    }

    public function test_test_email_needs_a_recorded_day(): void
    {
        $recipient = ReportRecipient::factory()->create();

        Livewire::test(Index::class)
            ->call('sendTest', $recipient->id)
            ->assertSee('Belum ada data Daily Stock');

        Mail::assertNothingSent();
    }

    public function test_test_email_goes_only_to_that_user_and_is_not_logged_as_delivery(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 07:00', 'Asia/Jakarta')->utc());
        $recorded = app(DailyStockService::class)->record('manual');
        $target = ReportRecipient::factory()->create(['email' => 'target@example.com']);
        ReportRecipient::factory()->create(['email' => 'other@example.com']);

        Livewire::test(Index::class)
            ->call('sendTest', $target->id)
            ->assertSee('dikirim ke target@example.com');

        Mail::assertSent(DailyStockReportMail::class, 1);
        Mail::assertSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->hasTo('target@example.com')
            && $mail->reportPath === 'daily-stock/Daily Stock FG 2026-10-09.xlsx');
        $this->assertNull($recorded->fresh()->emailed_at);
    }

    public function test_mail_shows_the_resume_and_attaches_the_day_excel(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 07:00', 'Asia/Jakarta')->utc());
        $recorded = app(DailyStockService::class)->record('manual');
        $service = app(DailyStockService::class);

        $mail = new DailyStockReportMail($recorded, $service->coverageSummary($recorded), $service->reportPath('2026-10-09'), 'Budi');

        $mail->assertHasSubject('Daily Stock Finish Good · 09 Okt 2026');
        $mail->assertSeeInHtml('Jumat, 09 Oktober 2026');
        $mail->assertSeeInHtml('Yth. Budi');
        $mail->assertSeeInOrderInHtml(['STOCK &lt; 1.5 DAY', 'SW', 'STOCK &lt; 1.0 DAY', 'SW'], false);
        $mail->assertHasAttachment(Attachment::fromStorageDisk('local', 'daily-stock/Daily Stock FG 2026-10-09.xlsx'));
        $this->assertInstanceOf(DailyStock::class, $mail->dailyStock);
    }
}
