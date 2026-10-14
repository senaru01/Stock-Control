<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Login')]
class Login extends Component
{
    /**
     * Failed sign-ins allowed per username and IP address before waiting a minute.
     */
    public const MAX_ATTEMPTS = 5;

    public string $username = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->username = Str::lower(trim($this->username));

        $this->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ], [], [
            'username' => 'username',
            'password' => 'password',
        ]);

        $throttleKey = Str::transliterate($this->username.'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        if (! Auth::attempt(['username' => $this->username, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($throttleKey);
            $this->reset('password');

            throw ValidationException::withMessages(['username' => 'Username atau password salah, atau akun nonaktif.']);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->redirectIntended(route('home'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
