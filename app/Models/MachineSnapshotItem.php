<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Fillable(['machine_snapshot_id', 'store', 'part_no', 'rack_no', 'stock', 'stock_prod', 'po_day'])]
class MachineSnapshotItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'stock_prod' => 'integer',
            'po_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MachineSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MachineSnapshot::class, 'machine_snapshot_id');
    }
}
