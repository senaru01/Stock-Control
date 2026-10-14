<?php

namespace App\Mail;

use App\Models\DailyStock;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Daily Stock Finish Good report: coverage resume and the monthly resume charts in the body,
 * that day's Excel attached.
 */
class DailyStockReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, array{parts: int, under_one: int, not_ok: int, ok: int, over: int}>  $summary
     * @param  list<array{title: string, png: string}>  $charts  PNG images embedded in the body
     */
    public function __construct(
        public DailyStock $dailyStock,
        public array $summary,
        public string $reportPath,
        public string $recipientName = '',
        public array $charts = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Daily Stock Finish Good · '.$this->date()->translatedFormat('d M Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.daily-stock-report',
            with: [
                'date' => $this->date(),
                'totals' => [
                    'parts' => array_sum(array_column($this->summary, 'parts')),
                    'under_one' => array_sum(array_column($this->summary, 'under_one')),
                    'not_ok' => array_sum(array_column($this->summary, 'not_ok')),
                    'over' => array_sum(array_column($this->summary, 'over')),
                ],
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->reportPath)
                ->as(basename($this->reportPath))
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }

    protected function date(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dailyStock->stock_date)->locale('id');
    }
}
