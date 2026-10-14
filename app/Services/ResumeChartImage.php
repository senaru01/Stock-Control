<?php

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Draws a monthly resume chart (stacked columns per process with the total as a labelled line) as a PNG,
 * for email clients that cannot run the dashboard's JavaScript charts. Same data, colours and rules:
 * fixed categorical slots, a grey "Lainnya", and a total line that bridges days without a recorded stock.
 */
class ResumeChartImage
{
    /**
     * Pixels per CSS pixel: the image is drawn twice as large as it is shown, so it stays sharp.
     */
    public const SCALE = 2;

    public const WIDTH = 720;

    public const HEIGHT = 330;

    /** @var list<string> */
    protected const SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

    protected const OTHER = '#b5b4ad';

    protected const TOTAL = '#52514e';

    protected const INK = '#0b0b0b';

    protected const MUTED = '#6b6a65';

    protected const GRID = '#e1e0d9';

    protected const AXIS = '#c3c2b7';

    protected const SURFACE = '#ffffff';

    protected GdImage $image;

    /** @var array<string, int> */
    protected array $colors = [];

    /**
     * @param  array{title: string, unit: string, labels: list<string>, series: list<array{label: string, slot: int|null, data: list<?int>}>, total: list<?int>}  $chart
     */
    public function png(array $chart): string
    {
        $width = self::WIDTH * self::SCALE;
        $height = self::HEIGHT * self::SCALE;
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD tidak bisa membuat gambar grafik.');
        }

        $this->image = $image;
        $this->colors = [];
        imagefill($this->image, 0, 0, $this->color(self::SURFACE));

        $this->drawHeader($chart);
        $this->drawPlot($chart);

        ob_start();
        imagepng($this->image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($this->image);

        return $png;
    }

    /**
     * Title, the latest total and the legend above the plot.
     *
     * @param  array{title: string, unit: string, series: list<array{label: string, slot: int|null, data: list<?int>}>, total: list<?int>}  $chart
     */
    protected function drawHeader(array $chart): void
    {
        $this->text($chart['title'], 16, 24, 13, self::INK, bold: true);

        $lastDay = collect($chart['total'])->filter(fn (?int $value) => $value !== null)->keys()->last();

        if ($lastDay !== null) {
            $summary = 'Tgl '.($lastDay + 1).': '.number_format($chart['total'][$lastDay]).' '.strtolower($chart['unit']);
            $this->text($summary, self::WIDTH - 16 - $this->textWidth($summary, 11, true), 24, 11, self::INK, bold: true);
        }

        $x = 16;

        foreach ($chart['series'] as $series) {
            $this->rect($x, 40, $x + 9, 49, $this->seriesColor($series['slot']));
            $this->text($series['label'], $x + 13, 48, 9, self::MUTED);
            $x += 13 + $this->textWidth($series['label'], 9) + 14;
        }

        $this->line($x, 45, $x + 16, 45, self::TOTAL, 2);
        $this->text('TOTAL', $x + 20, 48, 9, self::MUTED);
    }

    /**
     * @param  array{unit: string, labels: list<string>, series: list<array{label: string, slot: int|null, data: list<?int>}>, total: list<?int>}  $chart
     */
    protected function drawPlot(array $chart): void
    {
        $left = 52;
        $right = self::WIDTH - 16;
        $top = 72;
        $bottom = self::HEIGHT - 28;
        $days = count($chart['labels']);
        $slotWidth = ($right - $left) / max(1, $days);
        $barWidth = min(18, $slotWidth * 0.62);

        $highest = max(1, ...array_map(fn (?int $value) => (int) $value, $chart['total']));
        [$maximum, $step] = $this->niceScale($highest * 1.12);
        $y = fn (float $value) => $bottom - ($value / $maximum) * ($bottom - $top);

        for ($tick = 0; $tick <= $maximum; $tick += $step) {
            $tickY = $y($tick);
            $this->line($left, $tickY, $right, $tickY, $tick === 0 ? self::AXIS : self::GRID, 1);
            $label = number_format($tick);
            $this->text($label, $left - 6 - $this->textWidth($label, 8), $tickY + 3, 8, self::MUTED);
        }

        $this->text($chart['unit'], 14, ($top + $bottom) / 2 + $this->textWidth($chart['unit'], 9) / 2, 9, self::MUTED, angle: 90);

        $points = [];

        foreach ($chart['labels'] as $index => $label) {
            $center = $left + $slotWidth * ($index + 0.5);
            $this->text($label, $center - $this->textWidth($label, 8) / 2, $bottom + 14, 8, self::MUTED);

            $stackTop = 0;

            foreach ($chart['series'] as $series) {
                $value = (int) ($series['data'][$index] ?? 0);

                if ($value <= 0) {
                    continue;
                }

                $segmentBottom = $y($stackTop);
                $stackTop += $value;
                $segmentTop = $y($stackTop);
                // A 1px surface gap keeps neighbouring segments apart.
                $this->rect($center - $barWidth / 2, $segmentTop + 1, $center + $barWidth / 2, $segmentBottom, $this->seriesColor($series['slot']));
            }

            if ($chart['total'][$index] !== null) {
                $points[] = [$center, $y($chart['total'][$index]), $chart['total'][$index]];
            }
        }

        // The total line bridges days without a recorded stock (holidays), as on the dashboard and in Excel.
        foreach (array_slice($points, 1) as $offset => [$x, $pointY]) {
            [$previousX, $previousY] = $points[$offset];
            $this->line($previousX, $previousY, $x, $pointY, self::TOTAL, 2);
        }

        foreach ($points as [$x, $pointY, $value]) {
            $this->circle($x, $pointY, 3.5, self::SURFACE);
            $this->circle($x, $pointY, 2.5, self::TOTAL);
            $label = number_format($value);
            $this->text($label, $x - $this->textWidth($label, 8, true) / 2, $pointY - 7, 8, self::INK, bold: true);
        }
    }

    /**
     * A round maximum and step for about five grid lines.
     *
     * @return array{int, int}
     */
    protected function niceScale(float $maximum): array
    {
        $rough = max(1, $maximum / 5);
        $magnitude = 10 ** floor(log10($rough));
        $step = (int) (collect([1, 2, 2.5, 5, 10])->first(fn (float $factor) => $factor * $magnitude >= $rough) * $magnitude);
        $step = max(1, $step);

        return [(int) (ceil($maximum / $step) * $step), $step];
    }

    protected function seriesColor(?int $slot): string
    {
        return $slot === null ? self::OTHER : self::SLOTS[$slot % count(self::SLOTS)];
    }

    protected function color(string $hex): int
    {
        return $this->colors[$hex] ??= (int) imagecolorallocate(
            $this->image,
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        );
    }

    protected function rect(float $x1, float $y1, float $x2, float $y2, string $hex): void
    {
        $s = self::SCALE;

        if ($y2 - $y1 < 0.5) {
            return;
        }

        imagefilledrectangle($this->image, (int) round($x1 * $s), (int) round($y1 * $s), (int) round($x2 * $s) - 1, (int) round($y2 * $s) - 1, $this->color($hex));
    }

    protected function line(float $x1, float $y1, float $x2, float $y2, string $hex, float $thickness): void
    {
        $s = self::SCALE;
        imagesetthickness($this->image, max(1, (int) round($thickness * $s)));
        imageline($this->image, (int) round($x1 * $s), (int) round($y1 * $s), (int) round($x2 * $s), (int) round($y2 * $s), $this->color($hex));
        imagesetthickness($this->image, 1);
    }

    protected function circle(float $x, float $y, float $radius, string $hex): void
    {
        $s = self::SCALE;
        $diameter = (int) round($radius * 2 * $s);
        imagefilledellipse($this->image, (int) round($x * $s), (int) round($y * $s), $diameter, $diameter, $this->color($hex));
    }

    /**
     * Text with its baseline at (x, y) in CSS pixels; GD's built-in font when no TrueType font is available.
     */
    protected function text(string $text, float $x, float $y, float $size, string $hex, bool $bold = false, int $angle = 0): void
    {
        $s = self::SCALE;
        $font = $this->font($bold);

        if ($font === null) {
            $builtIn = $size >= 12 ? 5 : 3;
            $angle === 90
                ? imagestringup($this->image, $builtIn, (int) round($x * $s), (int) round($y * $s), $text, $this->color($hex))
                : imagestring($this->image, $builtIn, (int) round($x * $s), (int) round(($y - $size) * $s), $text, $this->color($hex));

            return;
        }

        imagettftext($this->image, $size * $s, $angle, (int) round($x * $s), (int) round($y * $s), $this->color($hex), $font, $text);
    }

    protected function textWidth(string $text, float $size, bool $bold = false): float
    {
        $font = $this->font($bold);

        if ($font === null) {
            return strlen($text) * imagefontwidth($size >= 12 ? 5 : 3) / self::SCALE;
        }

        $box = imagettfbbox($size * self::SCALE, 0, $font, $text);

        return $box === false ? 0 : ($box[2] - $box[0]) / self::SCALE;
    }

    protected function font(bool $bold): ?string
    {
        $path = (string) config($bold ? 'services.charts.font_bold' : 'services.charts.font');

        return $path !== '' && is_readable($path) ? $path : null;
    }
}
