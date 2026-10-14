<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `composer run dev` also runs the scheduler, so stock history snapshots are taken locally.
        DevCommands::artisan('schedule:work', 'scheduler');

        // Menu permissions ("daily_stock.edit", ...) come from the user's account; inactive users may do nothing.
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }

            if (AccessControl::isPermission($ability)) {
                return $user->hasPermission($ability);
            }

            return $user->isSuperAdmin() ? true : null;
        });
    }
}
