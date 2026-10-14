<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_stocks', function (Blueprint $table) {
            $table->id();
            $table->date('stock_date')->unique();
            $table->timestamp('taken_at');
            $table->string('local_time', 5);
            $table->unsignedInteger('item_count');
            $table->string('source', 20)->default('schedule');
            $table->timestamps();
        });

        Schema::create('daily_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_stock_id')->constrained()->cascadeOnDelete();
            $table->string('part_no', 50);
            $table->unsignedInteger('stock_pcs');
            $table->unsignedInteger('qty_box')->nullable();

            $table->unique(['daily_stock_id', 'part_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stock_items');
        Schema::dropIfExists('daily_stocks');
    }
};
