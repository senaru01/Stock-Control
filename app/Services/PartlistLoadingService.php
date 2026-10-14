<?php

namespace App\Services;

use App\Models\Partlist;
use App\Models\Setting;
use Illuminate\Support\Collection;

class PartlistLoadingService
{
    public const DEFAULT_PERCENT = 100;

    protected const SETTING_KEY = 'partlist.loading_percent';

    /**
     * Loading percentage per process (1-100). Processes never adjusted use the default of 100%.
     *
     * @return array<string, int>
     */
    public function percents(): array
    {
        $saved = (array) Setting::read(self::SETTING_KEY, []);

        return $this->processes()
            ->mapWithKeys(fn (string $process) => [$process => $this->clamp($saved[$process] ?? self::DEFAULT_PERCENT)])
            ->all();
    }

    /**
     * @param  array<string, int|string>  $percents
     */
    public function savePercents(array $percents): void
    {
        $saved = (array) Setting::read(self::SETTING_KEY, []);

        foreach ($percents as $process => $percent) {
            $saved[$this->normalizeProcess((string) $process)] = $this->clamp($percent);
        }

        Setting::write(self::SETTING_KEY, $saved);
    }

    /**
     * @param  array<string, int>|null  $percents
     */
    public function percentFor(?string $process, ?array $percents = null): int
    {
        $percents ??= $this->percents();

        return $percents[$this->normalizeProcess((string) $process)] ?? self::DEFAULT_PERCENT;
    }

    public function normalizeProcess(string $process): string
    {
        return strtoupper(trim($process));
    }

    /**
     * Distinct non-empty processes in the partlist, normalized and sorted.
     *
     * @return Collection<int, string>
     */
    protected function processes(): Collection
    {
        return Partlist::query()
            ->whereNotNull('process')
            ->distinct()
            ->pluck('process')
            ->map(fn ($process) => $this->normalizeProcess((string) $process))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    protected function clamp(mixed $percent): int
    {
        return max(1, min(100, (int) $percent));
    }
}
