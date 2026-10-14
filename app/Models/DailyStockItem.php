<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Fillable(['daily_stock_id', 'part_no', 'stock_pcs', 'qty_box'])]
class DailyStockItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_pcs' => 'integer',
            'qty_box' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DailyStock, $this>
     */
    public function dailyStock(): BelongsTo
    {
        return $this->belongsTo(DailyStock::class);
    }
}
