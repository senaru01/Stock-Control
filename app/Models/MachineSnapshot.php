<?php

namespace App\Models;

use Database\Factories\MachineSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The stock of every part of one monitored machine at one moment; slot_time is the 2-hour slot it fills
 * and production_date the production day (07:00 – 07:00) it belongs to.
 */
#[Fillable(['monitored_machine_id', 'taken_at', 'local_date', 'local_time', 'production_date', 'slot_time', 'item_count', 'source'])]
class MachineSnapshot extends Model
{
    /** @use HasFactory<MachineSnapshotFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_at' => 'immutable_datetime',
            'local_date' => 'string',
            'production_date' => 'string',
            'item_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MonitoredMachine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(MonitoredMachine::class, 'monitored_machine_id');
    }

    /**
     * @return HasMany<MachineSnapshotItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MachineSnapshotItem::class);
    }
}
