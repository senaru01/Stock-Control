<?php

namespace App\View\Components;

use App\Services\AndonService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AndonMenu extends Component
{
    /** @var array<string, list<array{line: string, total: int}>> */
    public array $groups;

    public ?string $currentLine;

    public function __construct(AndonService $andon)
    {
        $this->groups = $andon->lineGroups();
        $this->currentLine = request()->routeIs('andon.show') ? (string) request()->route('line') : null;
    }

    public function render(): View
    {
        return view('components.andon-menu');
    }
}
