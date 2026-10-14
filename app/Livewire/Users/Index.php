<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('User & Hak Akses')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $role = AccessControl::ROLE_SECTION;

    public bool $isActive = true;

    /**
     * Checked permissions ("<menu>.<ability>") of the user being edited.
     *
     * @var list<string>
     */
    public array $permissions = [];

    public function create(): void
    {
        $this->authorize('users.edit');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('users.edit');
        $user = User::findOrFail($id);
        $this->ensureCanManage($user);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->username = (string) $user->username;
        $this->email = (string) $user->email;
        $this->role = $user->role;
        $this->isActive = $user->is_active;
        $this->permissions = $user->isSuperAdmin() ? AccessControl::permissions() : AccessControl::normalize($user->permissions ?? []);
        $this->showForm = true;
    }

    /**
     * Picking a level loads that level's default permissions, which can then be changed per user.
     */
    public function updatedRole(): void
    {
        if (! array_key_exists($this->role, $this->assignableRoles)) {
            $this->role = AccessControl::ROLE_SECTION;
        }

        $this->permissions = array_values(array_intersect(AccessControl::defaultPermissions($this->role), $this->grantablePermissions));
    }

    /**
     * Tick or untick every ability of one menu at once.
     */
    public function toggleMenu(string $menu): void
    {
        abort_unless(array_key_exists($menu, AccessControl::MENUS), 404);

        $menuPermissions = array_map(fn (string $ability) => "{$menu}.{$ability}", AccessControl::MENUS[$menu]['abilities']);
        $allChecked = array_diff($menuPermissions, $this->permissions) === [];

        $this->permissions = $allChecked
            ? array_values(array_diff($this->permissions, $menuPermissions))
            : array_values(array_unique([...$this->permissions, ...array_intersect($menuPermissions, $this->grantablePermissions)]));
    }

    public function save(): void
    {
        $this->authorize('users.edit');

        $this->username = Str::lower(trim($this->username));
        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $user = $this->editingId ? User::findOrFail($this->editingId) : null;

        if ($user !== null) {
            $this->ensureCanManage($user);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($this->editingId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => [$user === null ? 'required' : 'nullable', 'string', Password::min(6)],
            'role' => ['required', Rule::in(array_keys($this->assignableRoles))],
            'isActive' => ['boolean'],
            'permissions' => ['array'],
            // Permissions the user already has stay allowed even when the editor lacks them; new ones must be the editor's own.
            'permissions.*' => ['string', Rule::in([...$this->grantablePermissions, ...($user->permissions ?? [])])],
        ], [
            'username.regex' => 'Username hanya huruf kecil, angka, titik, minus atau garis bawah (tanpa spasi).',
            'permissions.*.in' => 'Anda hanya bisa memberi hak akses yang Anda miliki sendiri.',
        ], [
            'name' => 'nama',
            'role' => 'level',
        ]);

        if ($user?->is(auth()->user()) && (! $validated['isActive'] || $validated['role'] !== $user->role)) {
            $this->addError('role', 'Anda tidak bisa mengubah level atau menonaktifkan akun sendiri.');

            return;
        }

        $attributes = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?: null,
            'role' => $validated['role'],
            'is_active' => $validated['isActive'],
            'permissions' => $validated['role'] === AccessControl::ROLE_SUPERADMIN ? AccessControl::permissions() : AccessControl::normalize($validated['permissions']),
        ];

        if (filled($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        if ($user !== null) {
            $user->update($attributes);
            session()->flash('status', "User {$user->name} diperbarui.");
        } else {
            $user = User::create($attributes);
            session()->flash('status', "User {$user->name} ({$user->username}) dibuat.");
        }

        $this->closeForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('users.edit');
        $user = User::findOrFail($id);
        $this->ensureCanManage($user);

        if ($user->is(auth()->user())) {
            session()->flash('error', 'Anda tidak bisa menonaktifkan akun sendiri.');

            return;
        }

        if ($user->is_active && $this->isLastActiveSuperAdmin($user)) {
            session()->flash('error', 'Super admin aktif terakhir tidak bisa dinonaktifkan.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('users.delete');
        $this->deletingId = $id;
    }

    public function delete(): void
    {
        $this->authorize('users.delete');
        $user = $this->deletingId ? User::find($this->deletingId) : null;
        $this->deletingId = null;

        if ($user === null) {
            return;
        }

        $this->ensureCanManage($user);

        if ($user->is(auth()->user()) || $this->isLastActiveSuperAdmin($user)) {
            session()->flash('error', $user->is(auth()->user()) ? 'Anda tidak bisa menghapus akun sendiri.' : 'Super admin aktif terakhir tidak bisa dihapus.');

            return;
        }

        $user->delete();
        session()->flash('status', "User {$user->name} dihapus.");
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        $search = trim($this->search);

        return User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderByRaw('case role when ? then 0 when ? then 1 when ? then 2 else 3 end', [AccessControl::ROLE_SUPERADMIN, AccessControl::ROLE_ADMIN, AccessControl::ROLE_GM])
            ->orderBy('name')
            ->get();
    }

    /**
     * Permissions the signed-in user may hand out: their own (a super admin has all of them).
     *
     * @return list<string>
     */
    #[Computed]
    public function grantablePermissions(): array
    {
        $user = auth()->user();

        return $user->isSuperAdmin() ? AccessControl::permissions() : AccessControl::normalize($user->permissions ?? []);
    }

    /**
     * Levels the signed-in user may give: only a super admin may create another super admin.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        return auth()->user()->isSuperAdmin()
            ? AccessControl::ROLES
            : array_diff_key(AccessControl::ROLES, [AccessControl::ROLE_SUPERADMIN => true]);
    }

    public function render(): View
    {
        return view('livewire.users.index', [
            'menus' => AccessControl::MENUS,
            'abilities' => AccessControl::ABILITIES,
        ]);
    }

    /**
     * Only a super admin may change or remove a super admin account.
     */
    protected function ensureCanManage(User $user): void
    {
        abort_if($user->isSuperAdmin() && ! auth()->user()->isSuperAdmin(), 403, 'Hanya super admin yang bisa mengubah akun super admin.');
    }

    protected function isLastActiveSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() && User::query()->active()->where('role', AccessControl::ROLE_SUPERADMIN)->count() <= 1;
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->username = '';
        $this->email = '';
        $this->password = '';
        $this->role = AccessControl::ROLE_SECTION;
        $this->isActive = true;
        $this->permissions = array_values(array_intersect(AccessControl::defaultPermissions(AccessControl::ROLE_SECTION), $this->grantablePermissions));
    }
}
