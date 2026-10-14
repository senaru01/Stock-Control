<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Partlists\Index as PartlistIndex;
use App\Livewire\Users\Index as UserIndex;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start every test signed out; TestCase signs in a super admin by default.
        Auth::logout();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('daily-stock.index'))->assertRedirect(route('login'));
        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Masuk');
    }

    public function test_a_user_signs_in_with_username_and_lands_on_their_first_menu(): void
    {
        $user = User::factory()->role(AccessControl::ROLE_GM)->create(['username' => 'fatah', 'password' => 'Senaru']);

        Livewire::test(Login::class)
            ->set('username', ' Fatah ')
            ->set('password', 'Senaru')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->get('/')->assertRedirect(route('partlists.index'));
    }

    public function test_wrong_passwords_and_inactive_accounts_cannot_sign_in_and_attempts_are_limited(): void
    {
        User::factory()->create(['username' => 'budi', 'password' => 'rahasia']);
        User::factory()->inactive()->create(['username' => 'lama', 'password' => 'rahasia']);

        Livewire::test(Login::class)->set('username', 'lama')->set('password', 'rahasia')->call('login')->assertHasErrors('username');
        $this->assertGuest();

        $login = Livewire::test(Login::class)->set('username', 'budi');

        foreach (range(1, Login::MAX_ATTEMPTS) as $attempt) {
            $login->set('password', 'salah')->call('login')->assertHasErrors('username');
        }

        $login->set('password', 'rahasia')->call('login')->assertHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan', $login->errors()->first('username'));
        $this->assertGuest();
    }

    public function test_pages_and_actions_follow_the_users_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('stock-parts.index'))->assertOk();
        $this->get(route('partlists.index'))->assertOk()->assertDontSee('Parameter Loading');
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('recipients.index'))->assertForbidden();

        Livewire::test(PartlistIndex::class)->call('create')->assertForbidden();
    }

    public function test_the_sidebar_only_lists_menus_the_user_may_open(): void
    {
        $this->actingAs(User::factory()->withPermissions(['daily_stock.view'])->create());

        $this->get(route('daily-stock.index'))
            ->assertOk()
            ->assertSee(route('daily-stock.index'), false)
            ->assertDontSee(route('partlists.index'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee('Atur Jadwal');
    }

    public function test_a_user_without_any_menu_gets_a_clear_message(): void
    {
        $this->actingAs(User::factory()->withPermissions([])->create());

        $this->get('/')->assertForbidden()->assertSee('belum punya hak akses');
    }

    public function test_a_deactivated_user_loses_access_at_once(): void
    {
        $user = User::factory()->role(AccessControl::ROLE_ADMIN)->create();
        $this->actingAs($user)->get(route('partlists.index'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get(route('partlists.index'))->assertForbidden();
    }

    public function test_super_admin_creates_a_user_with_level_defaults_and_custom_permissions(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(UserIndex::class)
            ->call('create')
            ->set('name', 'Pak GM')
            ->set('username', 'pak gm')
            ->set('password', 'rahasia')
            ->set('role', 'gm')
            ->assertSet('permissions', AccessControl::defaultPermissions('gm'))
            ->call('toggleMenu', 'daily_stock')
            ->call('save')
            ->assertHasErrors('username');

        Livewire::test(UserIndex::class)
            ->call('create')
            ->set('name', 'Pak GM')
            ->set('username', 'pak.gm')
            ->set('password', 'rahasia')
            ->set('role', 'gm')
            ->set('permissions', ['daily_stock.view', 'daily_stock.edit', 'partlists.edit'])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('username', 'pak.gm')->sole();

        // Editing a menu needs seeing it, so partlists.edit without partlists.view is dropped.
        $this->assertSame('gm', $user->role);
        $this->assertSame(['daily_stock.view', 'daily_stock.edit'], $user->permissions);
        $this->assertTrue(Auth::attempt(['username' => 'pak.gm', 'password' => 'rahasia']));
    }

    public function test_admins_cannot_create_super_admins_grant_more_than_they_have_or_touch_super_admins(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin = User::factory()->withPermissions(['users.view', 'users.edit', 'partlists.view'])->create(['role' => 'admin']);
        $this->actingAs($admin);

        $component = Livewire::test(UserIndex::class)->call('create');
        $this->assertArrayNotHasKey('superadmin', $component->instance()->assignableRoles);

        $component->set('name', 'Staff')
            ->set('username', 'staff')
            ->set('password', 'rahasia')
            ->set('permissions', ['partlists.view', 'daily_stock.view'])
            ->call('save')
            ->assertHasErrors('permissions.1');

        $component->set('role', 'superadmin')->assertSet('role', 'section');

        Livewire::test(UserIndex::class)->call('edit', $superAdmin->id)->assertForbidden();
    }

    public function test_users_cannot_lock_themselves_out_and_the_last_super_admin_stays(): void
    {
        $me = User::factory()->superAdmin()->create();
        $this->actingAs($me);

        Livewire::test(UserIndex::class)
            ->call('edit', $me->id)
            ->set('isActive', false)
            ->call('save')
            ->assertHasErrors('role');

        Livewire::test(UserIndex::class)->call('toggleActive', $me->id);
        Livewire::test(UserIndex::class)->call('confirmDelete', $me->id)->call('delete');

        $this->assertTrue($me->fresh()->is_active);
        $this->assertNotNull($me->fresh());

        // Another super admin may not remove the last active one either.
        User::query()->whereKeyNot($me->id)->where('role', 'superadmin')->update(['is_active' => false]);
        $other = User::factory()->superAdmin()->create();
        $other->update(['is_active' => false]);
        $this->actingAs(User::factory()->role('admin')->withPermissions(['users.view', 'users.edit', 'users.delete'])->create());
        Livewire::test(UserIndex::class)->call('confirmDelete', $me->id)->call('delete')->assertForbidden();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_create_command_makes_a_super_admin(): void
    {
        $this->artisan('user:create', ['username' => 'Fatah', '--name' => 'Fatah', '--password' => 'Senaru'])->assertSuccessful();

        $user = User::query()->where('username', 'fatah')->sole();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Auth::attempt(['username' => 'fatah', 'password' => 'Senaru']));

        $this->artisan('user:create', ['username' => 'fatah', '--password' => 'Senaru'])->assertFailed();
    }
}
