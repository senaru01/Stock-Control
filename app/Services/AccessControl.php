<?php

namespace App\Services;

/**
 * Levels (roles) and per-menu permissions. A permission is "<menu>.<ability>", e.g. "daily_stock.edit".
 * Super admins may do everything; every other user has exactly the permissions stored on their account,
 * which start from the template of their level and can then be changed per user.
 */
class AccessControl
{
    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_GM = 'gm';

    public const ROLE_SECTION = 'section';

    /** @var array<string, string> */
    public const ROLES = [
        self::ROLE_SUPERADMIN => 'Super Admin',
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_GM => 'GM',
        self::ROLE_SECTION => 'Section',
    ];

    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    /** @var array<string, string> */
    public const ABILITIES = [
        self::VIEW => 'Lihat',
        self::EDIT => 'Ubah',
        self::DELETE => 'Hapus',
    ];

    /**
     * Menus in sidebar order with the abilities that mean something there.
     * Ubah covers adding, editing, schedules and actions such as "Ambil Data Sekarang" or sending email.
     *
     * @var array<string, array{label: string, abilities: list<string>}>
     */
    public const MENUS = [
        'partlists' => ['label' => 'Partlist', 'abilities' => [self::VIEW, self::EDIT, self::DELETE]],
        'stock_parts' => ['label' => 'Stock Part All', 'abilities' => [self::VIEW]],
        'stock_history' => ['label' => 'History Stock', 'abilities' => [self::VIEW, self::EDIT]],
        'daily_stock' => ['label' => 'Daily Stock', 'abilities' => [self::VIEW, self::EDIT]],
        'machine_stock' => ['label' => 'Kontrol Stok Mesin', 'abilities' => [self::VIEW, self::EDIT]],
        'andon' => ['label' => 'Andon', 'abilities' => [self::VIEW]],
        'machines' => ['label' => 'Mesin Monitoring', 'abilities' => [self::VIEW, self::EDIT, self::DELETE]],
        'recipients' => ['label' => 'Penerima Email', 'abilities' => [self::VIEW, self::EDIT, self::DELETE]],
        'users' => ['label' => 'User & Hak Akses', 'abilities' => [self::VIEW, self::EDIT, self::DELETE]],
    ];

    /**
     * Route of each menu's page, used to send a user to the first page they may open.
     *
     * @var array<string, string>
     */
    public const MENU_ROUTES = [
        'partlists' => 'partlists.index',
        'stock_parts' => 'stock-parts.index',
        'stock_history' => 'stock-history.index',
        'daily_stock' => 'daily-stock.index',
        'machine_stock' => 'machine-stock.index',
        'andon' => 'andon.index',
        'machines' => 'machine-stock.machines',
        'recipients' => 'recipients.index',
        'users' => 'users.index',
    ];

    /**
     * Every permission that exists, e.g. ["partlists.view", "partlists.edit", ...].
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $permissions = [];

        foreach (self::MENUS as $menu => $definition) {
            foreach ($definition['abilities'] as $ability) {
                $permissions[] = "{$menu}.{$ability}";
            }
        }

        return $permissions;
    }

    public static function isPermission(string $ability): bool
    {
        return in_array($ability, self::permissions(), true);
    }

    /**
     * The starting permissions of a level: Admin may do everything except manage users, GM may see everything
     * except users, Section may see the stock pages.
     *
     * @return list<string>
     */
    public static function defaultPermissions(string $role): array
    {
        $all = self::permissions();
        $viewOnly = array_values(array_filter($all, fn (string $permission) => str_ends_with($permission, '.'.self::VIEW)));

        return match ($role) {
            self::ROLE_SUPERADMIN => $all,
            self::ROLE_ADMIN => array_values(array_filter($all, fn (string $permission) => ! str_starts_with($permission, 'users.') || $permission === 'users.view')),
            self::ROLE_GM => array_values(array_filter($viewOnly, fn (string $permission) => $permission !== 'users.view')),
            default => ['partlists.view', 'stock_parts.view', 'stock_history.view', 'daily_stock.view', 'machine_stock.view', 'andon.view'],
        };
    }

    /**
     * Keep only permissions that exist; a menu's other abilities require being able to see it.
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $permissions = array_values(array_intersect(self::permissions(), array_map('strval', $permissions)));

        return array_values(array_filter($permissions, function (string $permission) use ($permissions) {
            [$menu] = explode('.', $permission);

            return in_array("{$menu}.".self::VIEW, $permissions, true);
        }));
    }
}
