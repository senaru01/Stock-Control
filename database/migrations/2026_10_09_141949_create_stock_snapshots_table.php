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
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('taken_at')->index();
            $table->date('local_date')->index();
            $table->string('local_time', 5);
            $table->unsignedBigInteger('total_stock');
            $table->unsignedInteger('item_count');
            $table->string('source', 20)->default('schedule');
            $table->timestamps();
        });

        Schema::create('stock_snapshot_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('store', 50)->index();
            $table->unsignedBigInteger('total_stock');
            $table->unsignedInteger('item_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_snapshot_stores');
        Schema::dropIfExists('stock_snapshots');
    }
};
