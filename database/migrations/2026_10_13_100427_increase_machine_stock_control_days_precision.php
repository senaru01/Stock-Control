<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Min / max days keep three decimals (e.g. 0.125 day) instead of two.
     */
    public function up(): void
    {
        Schema::table('machine_stock_controls', function (Blueprint $table) {
            $table->decimal('min_days', 7, 3)->change();
            $table->decimal('max_days', 7, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_stock_controls', function (Blueprint $table) {
            $table->decimal('min_days', 5, 2)->change();
            $table->decimal('max_days', 5, 2)->change();
        });
    }
};
