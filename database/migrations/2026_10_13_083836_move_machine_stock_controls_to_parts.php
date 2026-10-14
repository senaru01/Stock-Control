<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Min / max days are set per part instead of per machine: each machine-wide setting is copied
     * to every part of the machine's latest snapshot, keeping its effective date.
     */
    public function up(): void
    {
        Schema::table('machine_stock_controls', function (Blueprint $table) {
            $table->string('store', 50)->nullable()->after('monitored_machine_id');
            $table->string('part_no', 100)->nullable()->after('store');
            $table->index(['monitored_machine_id', 'part_no', 'store'], 'machine_stock_controls_part_index');
        });

        DB::table('machine_stock_controls')->whereNull('part_no')->orderBy('id')->each(function (object $control) {
            $latestSnapshotId = DB::table('machine_snapshots')
                ->where('monitored_machine_id', $control->monitored_machine_id)
                ->orderByDesc('taken_at')
                ->orderByDesc('id')
                ->value('id');

            $parts = $latestSnapshotId === null ? collect() : DB::table('machine_snapshot_items')
                ->where('machine_snapshot_id', $latestSnapshotId)
                ->get(['store', 'part_no']);

            DB::table('machine_stock_controls')->insert($parts->map(fn (object $part) => [
                'monitored_machine_id' => $control->monitored_machine_id,
                'store' => $part->store,
                'part_no' => $part->part_no,
                'min_days' => $control->min_days,
                'max_days' => $control->max_days,
                'effective_from' => $control->effective_from,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });

        DB::table('machine_stock_controls')->whereNull('part_no')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_stock_controls', function (Blueprint $table) {
            $table->dropIndex('machine_stock_controls_part_index');
            $table->dropColumn(['store', 'part_no']);
        });
    }
};
