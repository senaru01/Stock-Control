<?php

namespace App\Livewire\Recipients;

use App\Models\ReportRecipient;
use App\Services\DailyStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('User Penerima Email')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $email = '';

    public bool $isActive = true;

    public function create(): void
    {
        $this->authorize('recipients.edit');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('recipients.edit');
        $recipient = ReportRecipient::findOrFail($id);

        $this->resetValidation();
        $this->editingId = $recipient->id;
        $this->name = $recipient->name;
        $this->email = $recipient->email;
        $this->isActive = $recipient->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('recipients.edit');
        $this->email = mb_strtolower(trim($this->email));
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('report_recipients', 'email')->ignore($this->editingId)],
            'isActive' => ['boolean'],
        ], [], [
            'name' => 'nama',
            'email' => 'email',
        ]);

        $attributes = ['name' => $validated['name'], 'email' => $validated['email'], 'is_active' => $validated['isActive']];

        if ($this->editingId) {
            ReportRecipient::findOrFail($this->editingId)->update($attributes);
            session()->flash('status', "User {$attributes['name']} diperbarui.");
        } else {
            ReportRecipient::create($attributes);
            session()->flash('status', "User {$attributes['name']} ditambahkan sebagai penerima email.");
        }

        $this->closeForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('recipients.edit');
        $recipient = ReportRecipient::findOrFail($id);
        $recipient->update(['is_active' => ! $recipient->is_active]);
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('recipients.delete');
        $this->deletingId = $id;
    }

    public function delete(): void
    {
        $this->authorize('recipients.delete');
        if ($this->deletingId) {
            $recipient = ReportRecipient::find($this->deletingId);
            $recipient?->delete();

            if ($recipient) {
                session()->flash('status', "User {$recipient->name} dihapus.");
            }
        }

        $this->deletingId = null;
    }

    /**
     * Send the latest Daily Stock report to one user only, to check that the address receives it.
     */
    public function sendTest(int $id): void
    {
        $this->authorize('recipients.edit');
        $dailyStock = app(DailyStockService::class);
        $recipient = ReportRecipient::findOrFail($id);
        $latest = $dailyStock->latest();

        if ($latest === null) {
            session()->flash('error', 'Belum ada data Daily Stock untuk dikirim. Buat dulu di menu Daily Stock.');

            return;
        }

        $result = $dailyStock->sendReport($latest, collect([$recipient]));

        if ($result['failed'] !== []) {
            session()->flash('error', "Email tes ke {$recipient->email} gagal dikirim. Periksa pengaturan email (SMTP).");

            return;
        }

        session()->flash('status', "Email tes (Daily Stock {$latest->stock_date}) dikirim ke {$recipient->email}.");
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /**
     * @return Collection<int, ReportRecipient>
     */
    #[Computed]
    public function recipients(): Collection
    {
        $search = trim($this->search);

        return ReportRecipient::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        $dailyStock = app(DailyStockService::class);

        return view('livewire.recipients.index', [
            'activeCount' => ReportRecipient::query()->active()->count(),
            'scheduleLabel' => $dailyStock->describeSchedule(),
            'mailer' => (string) config('mail.default'),
        ]);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->isActive = true;
    }
}
