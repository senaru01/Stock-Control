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
        Schema::create('machine_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_machine_id')->constrained()->cascadeOnDelete();
            $table->timestamp('taken_at');
            $table->date('local_date');
            $table->string('local_time', 5);
            $table->string('slot_time', 5);
            $table->unsignedInteger('item_count');
            $table->string('source', 20)->default('schedule');
            $table->timestamps();

            $table->index(['monitored_machine_id', 'local_date', 'slot_time'], 'machine_snapshots_slot_index');
        });

        Schema::create('machine_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('store', 50)->nullable();
            $table->string('part_no', 100)->nullable();
            $table->string('rack_no', 50)->nullable();
            $table->unsignedInteger('stock');
            $table->unsignedInteger('stock_prod');
            $table->unsignedInteger('std_min');
            $table->unsignedInteger('max_pcs')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_snapshot_items');
        Schema::dropIfExists('machine_snapshots');
    }
};
