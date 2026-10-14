<?php

namespace App\Models;

use Database\Factories\MachineStockControlFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The min / max stock of one part of a monitored machine in days of PO, valid from effective_from until the next setting.
 */
#[Fillable(['monitored_machine_id', 'store', 'part_no', 'min_days', 'max_days', 'effective_from'])]
class MachineStockControl extends Model
{
    /** @use HasFactory<MachineStockControlFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_days' => 'float',
            'max_days' => 'float',
            'effective_from' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MonitoredMachine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(MonitoredMachine::class, 'monitored_machine_id');
    }
}
