<?php

namespace App\Livewire\Partlists;

use App\Exports\PartlistTemplate;
use App\Imports\PartlistImporter;
use App\Models\Partlist;
use App\Services\PartlistLoadingService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Title('Partlist')]
class Index extends Component
{
    use WithFileUploads, WithPagination;

    /** @var list<string> */
    protected const SEARCHABLE = ['code', 'part_no', 'part_name', 'customer_code', 'job_no', 'line', 'rack_no', 'jig_no'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $level = '';

    #[Url(except: 'code')]
    public string $sortField = 'code';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public int $perPage = 15;

    /** The day on which material is ordered, used for the MAT_ORDERPOINT column; saved so the andon uses it too. */
    public int $orderPointDays = Partlist::DEFAULT_ORDER_POINT_DAYS;

    public bool $showForm = false;

    public ?string $editingCode = null;

    public ?string $deletingCode = null;

    /** @var array<string, string|int|null> */
    public array $form = [];

    public bool $showImport = false;

    public bool $showLoadingSettings = false;

    /** @var list<array{process: string, percent: int|string}> */
    public array $loadingParameters = [];

    public ?TemporaryUploadedFile $importFile = null;

    public bool $importUpdateExisting = true;

    /** @var array{created: int, updated: int, failed: int, errors: list<array{row: int, code: ?string, messages: list<string>}>, ignored_headers: list<string>}|null */
    public ?array $importResult = null;

    public function mount(): void
    {
        $this->orderPointDays = Partlist::orderPointDays();

        $this->resetForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLevel(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedOrderPointDays(): void
    {
        Partlist::saveOrderPointDays($this->orderPointDays);
        $this->orderPointDays = Partlist::orderPointDays();
    }

    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, Partlist::FIELDS)) {
            return;
        }

        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
    }

    public function create(): void
    {
        $this->authorize('partlists.edit');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $code): void
    {
        $this->authorize('partlists.edit');
        $partlist = Partlist::findOrFail($code);

        $this->resetValidation();
        $this->editingCode = $partlist->code;
        $this->form = $partlist->only(array_keys(Partlist::FIELDS));
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('partlists.edit');
        if (blank($this->form['code'] ?? null) && filled($this->form['part_no'] ?? null) && filled($this->form['level'] ?? null)) {
            $this->form['code'] = $this->form['part_no'].$this->form['level'];
        }

        $data = collect($this->validate($this->rules())['form'])
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();

        if ($this->editingCode) {
            Partlist::findOrFail($this->editingCode)->update(collect($data)->except('code')->all());
            session()->flash('status', "Partlist {$this->editingCode} berhasil diperbarui.");
        } else {
            Partlist::create($data);
            session()->flash('status', "Partlist {$data['code']} berhasil ditambahkan.");
        }

        $this->closeForm();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmDelete(string $code): void
    {
        $this->authorize('partlists.delete');
        $this->deletingCode = $code;
    }

    public function delete(): void
    {
        $this->authorize('partlists.delete');
        if ($this->deletingCode) {
            Partlist::whereKey($this->deletingCode)->delete();
            session()->flash('status', "Partlist {$this->deletingCode} berhasil dihapus.");
        }

        $this->deletingCode = null;
    }

    public function openLoadingSettings(PartlistLoadingService $loading): void
    {
        $this->authorize('partlists.edit');
        $this->resetValidation();
        $this->loadingParameters = collect($loading->percents())
            ->map(fn (int $percent, string $process) => ['process' => $process, 'percent' => $percent])
            ->values()
            ->all();
        $this->showLoadingSettings = true;
    }

    public function saveLoadingSettings(PartlistLoadingService $loading): void
    {
        $this->authorize('partlists.edit');
        $this->validate(
            ['loadingParameters.*.percent' => ['required', 'integer', 'min:1', 'max:100']],
            [],
            ['loadingParameters.*.percent' => 'persen loading'],
        );

        $loading->savePercents(collect($this->loadingParameters)->pluck('percent', 'process')->all());

        unset($this->loadingPercents);
        $this->showLoadingSettings = false;
        session()->flash('status', 'Parameter loading per process berhasil disimpan.');
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function loadingPercents(): array
    {
        return app(PartlistLoadingService::class)->percents();
    }

    public function openImport(): void
    {
        $this->authorize('partlists.edit');
        $this->closeImport();
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->resetValidation('importFile');
        $this->reset('showImport', 'importFile', 'importUpdateExisting', 'importResult');
    }

    public function import(PartlistImporter $importer): void
    {
        $this->authorize('partlists.edit');
        $this->validate(
            ['importFile' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240']],
            [],
            ['importFile' => 'file Excel'],
        );

        try {
            $this->importResult = $importer->import(
                $this->importFile->getRealPath(),
                $this->importFile->getClientOriginalExtension(),
                $this->importUpdateExisting,
            );
        } catch (InvalidArgumentException|SpreadsheetException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        } finally {
            $this->importFile?->delete();
            $this->importFile = null;
        }

        unset($this->partlists, $this->levels, $this->loadingPercents);
        $this->resetPage();
    }

    public function downloadTemplate(PartlistTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->create(), 'template-import-partlist.xlsx')
            ->deleteFileAfterSend();
    }

    /**
     * @return LengthAwarePaginator<int, Partlist>
     */
    #[Computed]
    public function partlists(): LengthAwarePaginator
    {
        $sortField = array_key_exists($this->sortField, Partlist::FIELDS) ? $this->sortField : 'code';
        $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return Partlist::query()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    foreach (self::SEARCHABLE as $column) {
                        $query->orWhere($column, 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->when($this->level !== '', fn ($query) => $query->where('level', $this->level))
            ->orderBy($sortField, $sortDirection)
            ->paginate(in_array($this->perPage, [15, 25, 50, 100], true) ? $this->perPage : 15);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function levels(): array
    {
        return Partlist::query()->distinct()->orderBy('level')->pluck('level')->all();
    }

    public function render(): View
    {
        return view('livewire.partlists.index', [
            'fields' => Partlist::FIELDS,
            'orderPointDayOptions' => Partlist::ORDER_POINT_DAY_OPTIONS,
            'loadingService' => app(PartlistLoadingService::class),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return collect(Partlist::validationRules(ignoreCode: $this->editingCode))
            ->mapWithKeys(fn (array $rules, string $name) => ["form.{$name}" => $rules])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return collect(Partlist::FIELDS)
            ->mapWithKeys(fn (array $field, string $name) => ["form.{$name}" => $field['label']])
            ->all();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingCode = null;
        $this->form = collect(Partlist::FIELDS)
            ->map(fn (array $field) => $field['type'] === 'integer' ? 0 : '')
            ->all();
    }
}
