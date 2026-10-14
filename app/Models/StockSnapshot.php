<?php

namespace App\Models;

use Database\Factories\StockSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['taken_at', 'local_date', 'local_time', 'total_stock', 'item_count', 'source'])]
class StockSnapshot extends Model
{
    /** @use HasFactory<StockSnapshotFactory> */
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
            'total_stock' => 'integer',
            'item_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<StockSnapshotStore, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(StockSnapshotStore::class);
    }
}
