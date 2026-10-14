<?php

namespace App\Models;

use Database\Factories\MonitoredMachineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A machine (SOS line, e.g. "SW 5.3") whose stock and stock prod are recorded every 2 hours
 * and checked against the min / max (in days) set for each of its parts.
 */
#[Fillable(['line', 'name', 'is_active'])]
class MonitoredMachine extends Model
{
    /** @use HasFactory<MonitoredMachineFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<MachineSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(MachineSnapshot::class);
    }

    /**
     * @return HasMany<MachineStockControl, $this>
     */
    public function controls(): HasMany
    {
        return $this->hasMany(MachineStockControl::class);
    }

    /**
     * @return HasOne<MachineSnapshot, $this>
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(MachineSnapshot::class)->ofMany(['taken_at' => 'max', 'id' => 'max']);
    }

    public function label(): string
    {
        return filled($this->name) ? "{$this->name} ({$this->line})" : $this->line;
    }
}
