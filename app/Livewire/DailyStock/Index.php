<?php

namespace App\Livewire\DailyStock;

use App\Models\DailyStock;
use App\Models\ReportRecipient;
use App\Services\DailyStockService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Daily Stock Finish Good')]
class Index extends Component
{
    public bool $showSettings = false;

    public string $time = '07:00';

    /** @var list<int> */
    public array $days = [];

    public int $workingDays = 22;

    /** @var array<string, string> */
    public const RESUMES = [
        DailyStockService::RESUME_ITEM => 'Item',
        DailyStockService::RESUME_BOX => 'Box',
        DailyStockService::RESUME_PG_ITEM => 'PG per Mesin',
    ];

    #[Url(as: 'resume', except: DailyStockService::RESUME_ITEM)]
    public string $resumeView = DailyStockService::RESUME_ITEM;

    /** The month (Y-m) of the resume charts; empty for the month of the latest recorded day. */
    #[Url(as: 'month', except: '')]
    public string $resumeMonth = '';

    public function mount(DailyStockService $dailyStock): void
    {
        $schedule = $dailyStock->schedule();
        $this->time = $schedule['time'];
        $this->days = $schedule['days'];
        $this->workingDays = $schedule['working_days'];
        $this->updatedResumeView();
        $this->updatedResumeMonth();
    }

    public function updatedResumeView(): void
    {
        if (! array_key_exists($this->resumeView, self::RESUMES)) {
            $this->resumeView = DailyStockService::RESUME_ITEM;
        }
    }

    public function updatedResumeMonth(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->resumeMonth) !== 1) {
            $this->resumeMonth = substr((string) ($this->latest?->stock_date ?? app(DailyStockService::class)->now()->toDateString()), 0, 7);
        }
    }

    public function shiftResumeMonth(int $direction): void
    {
        $this->resumeMonth = CarbonImmutable::parse($this->resumeMonth.'-01')->addMonthsNoOverflow($direction > 0 ? 1 : -1)->format('Y-m');
    }

    public function saveSchedule(DailyStockService $dailyStock): void
    {
        $this->authorize('daily_stock.edit');
        $this->validate([
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'days' => ['array'],
            'days.*' => ['integer', Rule::in(array_keys(DailyStockService::DAYS))],
            'workingDays' => ['required', 'integer', 'min:1', 'max:31'],
        ], [
            'time.regex' => 'Format jam harus HH:MM, contoh: 07:00.',
        ]);

        $dailyStock->saveSchedule([
            'time' => $this->time,
            'days' => array_map('intval', $this->days),
            'working_days' => $this->workingDays,
        ]);

        $this->days = $dailyStock->schedule()['days'];
        $this->showSettings = false;
        session()->flash('status', 'Jadwal daily stock disimpan: '.$dailyStock->describeSchedule().'.');
    }

    public function recordNow(DailyStockService $dailyStock): void
    {
        $this->authorize('daily_stock.edit');
        try {
            $recorded = $dailyStock->record('manual');
        } catch (RuntimeException $exception) {
            session()->flash('error', 'Gagal mengambil data: '.$exception->getMessage());

            return;
        }

        unset($this->latest, $this->reports, $this->recent, $this->resume, $this->resumeCharts);
        session()->flash('status', "Daily stock {$recorded->stock_date} {$recorded->local_time} disimpan dan file Excel hari ini dibuat.");
    }

    public function regenerate(DailyStockService $dailyStock, string $date): void
    {
        $this->authorize('daily_stock.edit');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        $dailyStock->generateReport($date);
        unset($this->reports);
        session()->flash('status', "File Excel {$date} dibuat ulang dengan partlist dan hari kerja terbaru.");
    }

    /**
     * Email that day's Excel report to every active user now.
     */
    public function sendEmail(string $date): void
    {
        $this->authorize('daily_stock.edit');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        $recorded = DailyStock::query()->where('stock_date', $date)->first();

        if ($recorded === null) {
            session()->flash('error', "Tidak ada data Daily Stock tanggal {$date}.");

            return;
        }

        if ($this->activeRecipients === 0) {
            session()->flash('error', 'Belum ada user penerima aktif. Tambahkan di menu User.');

            return;
        }

        $result = app(DailyStockService::class)->sendReport($recorded);
        unset($this->recent);

        if ($result['failed'] !== []) {
            session()->flash('error', "Email terkirim ke {$result['sent']} user, gagal ke: ".implode(', ', $result['failed']).'. Periksa pengaturan email (SMTP).');

            return;
        }

        session()->flash('status', "File Daily Stock {$date} dikirim ke {$result['sent']} user.");
    }

    public function download(DailyStockService $dailyStock, string $date): StreamedResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        $path = $dailyStock->reportPath($date);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    #[Computed]
    public function latest(): ?DailyStock
    {
        return app(DailyStockService::class)->latest();
    }

    /**
     * @return array<string, array{parts: int, under_one: int, not_ok: int, ok: int, over: int}>
     */
    #[Computed]
    public function summary(): array
    {
        return $this->latest ? app(DailyStockService::class)->coverageSummary($this->latest) : [];
    }

    /**
     * The monthly resume of the chosen month, up to the latest recorded day (or the month's last day).
     *
     * @return array{days_in_month: int, recorded_days: list<int>, resumes: array<string, array{rows: list<string>, values: array<string, array<string, array<int, int>>>}>}
     */
    #[Computed]
    public function resume(): array
    {
        $endOfMonth = CarbonImmutable::parse($this->resumeMonth.'-01')->endOfMonth()->toDateString();
        $latestDate = $this->latest?->stock_date;
        $date = $latestDate !== null && str_starts_with($latestDate, $this->resumeMonth) ? $latestDate : $endOfMonth;

        return app(DailyStockService::class)->monthResume($date);
    }

    /**
     * One chart per coverage threshold for the chosen resume, built like the charts in the report email.
     *
     * @return list<array{threshold: string, title: string, unit: string, labels: list<string>, series: list<array{label: string, slot: int|null, data: list<?int>}>, total: list<?int>, rows: list<array{label: string, data: list<?int>}>}>
     */
    #[Computed]
    public function resumeCharts(): array
    {
        return app(DailyStockService::class)->resumeCharts($this->resume, $this->resumeView);
    }

    /**
     * @return list<array{date: string, label: string, path: string, size: int, updated_at: CarbonImmutable}>
     */
    #[Computed]
    public function reports(): array
    {
        return app(DailyStockService::class)->reports();
    }

    /**
     * @return Collection<int, DailyStock>
     */
    #[Computed]
    public function recent(): Collection
    {
        return DailyStock::query()->latest('stock_date')->limit(10)->get();
    }

    #[Computed]
    public function activeRecipients(): int
    {
        return ReportRecipient::query()->active()->count();
    }

    #[Computed]
    public function nextRun(): ?CarbonImmutable
    {
        $dailyStock = app(DailyStockService::class);

        return $dailyStock->nextRunAt($dailyStock->now());
    }

    public function render(): View
    {
        return view('livewire.daily-stock.index', [
            'scheduleLabel' => app(DailyStockService::class)->describeSchedule(),
            'dayOptions' => DailyStockService::DAYS,
            'resumeOptions' => self::RESUMES,
        ]);
    }
}
