<?php

namespace App\Livewire\Andon;

use App\Models\Partlist;
use App\Services\AndonService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::andon')]
class Show extends Component
{
    public string $line = '';

    #[Url(except: '')]
    public string $level = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(string $line): void
    {
        abort_unless(Partlist::monitoredOnAndon()->where('line', $line)->exists(), 404);

        $this->line = $line;
    }

    /**
     * @return array{cards: list<array<string, mixed>>, fetched_at: mixed, error: ?string}
     */
    #[Computed]
    public function andon(): array
    {
        return app(AndonService::class)->forLine($this->line);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function cards(): array
    {
        $search = mb_strtolower(trim($this->search));

        return collect($this->andon['cards'])
            ->when($this->level !== '', fn ($cards) => $cards->where('level', $this->level))
            ->when($this->status !== '', fn ($cards) => $cards->where('status', $this->status))
            ->when($search !== '', fn ($cards) => $cards->filter(
                fn (array $card) => str_contains(mb_strtolower($card['part_no']), $search)
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return collect($this->andon['cards'])->countBy('status')->all();
    }

    #[Computed]
    public function process(): ?string
    {
        return app(AndonService::class)->processForLine($this->line);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function levels(): array
    {
        return collect($this->andon['cards'])->pluck('level')->filter()->unique()->sort()->values()->all();
    }

    public function render(): View
    {
        return view('livewire.andon.show')->title('Andon '.$this->line);
    }
}
