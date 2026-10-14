<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['stock_date', 'taken_at', 'local_time', 'item_count', 'source', 'emailed_at', 'emailed_count'])]
class DailyStock extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_date' => 'string',
            'taken_at' => 'immutable_datetime',
            'item_count' => 'integer',
            'emailed_at' => 'immutable_datetime',
            'emailed_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<DailyStockItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DailyStockItem::class);
    }
}
