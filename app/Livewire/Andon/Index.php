<?php

namespace App\Livewire\Andon;

use App\Services\AndonService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Andon')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    /**
     * Lines grouped by process, filtered by the search on process or line name.
     *
     * @return array<string, list<array{line: string, total: int}>>
     */
    #[Computed]
    public function groups(): array
    {
        $search = mb_strtolower(trim($this->search));
        $groups = app(AndonService::class)->lineGroups();

        if ($search === '') {
            return $groups;
        }

        return collect($groups)
            ->map(fn (array $lines, $process) => str_contains(mb_strtolower((string) $process), $search)
                ? $lines
                : array_values(array_filter($lines, fn (array $line) => str_contains(mb_strtolower($line['line']), $search))))
            ->filter()
            ->all();
    }

    public function render(): View
    {
        return view('livewire.andon.index');
    }
}
