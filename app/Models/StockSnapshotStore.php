<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Fillable(['stock_snapshot_id', 'store', 'total_stock', 'item_count'])]
class StockSnapshotStore extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_stock' => 'integer',
            'item_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(StockSnapshot::class, 'stock_snapshot_id');
    }
}
