<?php

use App\Livewire\Andon\Index as AndonIndex;
use App\Livewire\Andon\Show as AndonShow;
use App\Livewire\Auth\Login;
use App\Livewire\DailyStock\Index as DailyStockIndex;
use App\Livewire\MachineStock\Index as MachineStockIndex;
use App\Livewire\MachineStock\Machines as MachineStockMachines;
use App\Livewire\Partlists\Index as PartlistIndex;
use App\Livewire\Recipients\Index as RecipientIndex;
use App\Livewire\StockHistory\Index as StockHistoryIndex;
use App\Livewire\StockParts\Index as StockPartIndex;
use App\Livewire\Users\Index as UserIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', Login::class)->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    // Each user lands on the first menu they may open.
    Route::get('/', function (Request $request): RedirectResponse {
        $route = $request->user()->homeRoute();
        abort_if($route === null, 403, 'Akun ini belum punya hak akses ke menu apa pun. Hubungi administrator.');

        return redirect()->route($route);
    })->name('home');

    Route::post('/logout', function (Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::livewire('/partlists', PartlistIndex::class)->middleware('can:partlists.view')->name('partlists.index');
    Route::livewire('/stock-parts', StockPartIndex::class)->middleware('can:stock_parts.view')->name('stock-parts.index');
    Route::livewire('/stock-history', StockHistoryIndex::class)->middleware('can:stock_history.view')->name('stock-history.index');
    Route::livewire('/daily-stock', DailyStockIndex::class)->middleware('can:daily_stock.view')->name('daily-stock.index');
    Route::livewire('/machine-stock', MachineStockIndex::class)->middleware('can:machine_stock.view')->name('machine-stock.index');
    Route::livewire('/machine-stock/machines', MachineStockMachines::class)->middleware('can:machines.view')->name('machine-stock.machines');
    Route::livewire('/users', UserIndex::class)->middleware('can:users.view')->name('users.index');
    Route::livewire('/recipients', RecipientIndex::class)->middleware('can:recipients.view')->name('recipients.index');
    Route::livewire('/andon', AndonIndex::class)->middleware('can:andon.view')->name('andon.index');
    Route::livewire('/andon/{line}', AndonShow::class)->middleware('can:andon.view')->where('line', '.*')->name('andon.show');
});
