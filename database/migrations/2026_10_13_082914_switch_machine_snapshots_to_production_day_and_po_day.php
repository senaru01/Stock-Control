<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production days start at 07:00, so slots become 07:00, 09:00, ... 05:00 and each snapshot gets its production date.
     * Min / max now come from the machine parameter (days x PO/day), so items keep PO/day instead of the partlist min / max.
     */
    public function up(): void
    {
        Schema::table('machine_snapshots', function (Blueprint $table) {
            $table->date('production_date')->nullable()->after('local_time');
        });

        DB::table('machine_snapshots')->orderBy('id')->each(function (object $snapshot) {
            $local = CarbonImmutable::parse("{$snapshot->local_date} {$snapshot->local_time}");
            $sinceDayStart = ($local->hour - 7 + 24) % 24;

            DB::table('machine_snapshots')->where('id', $snapshot->id)->update([
                'production_date' => $local->subHours(7)->toDateString(),
                'slot_time' => sprintf('%02d:00', (7 + $sinceDayStart - $sinceDayStart % 2) % 24),
            ]);
        });

        Schema::table('machine_snapshots', function (Blueprint $table) {
            $table->index(['monitored_machine_id', 'production_date', 'slot_time'], 'machine_snapshots_production_slot_index');
        });

        Schema::table('machine_snapshots', function (Blueprint $table) {
            $table->dropIndex('machine_snapshots_slot_index');
        });

        Schema::table('machine_snapshot_items', function (Blueprint $table) {
            $table->unsignedInteger('po_day')->nullable()->after('stock_prod');
        });

        DB::table('machine_snapshot_items')->orderBy('id')->each(function (object $item) {
            $poDay = DB::table('partlists')->where('part_no', $item->part_no)->where('level', $item->store)->value('po_day');

            if (is_numeric($poDay) && (float) $poDay > 0) {
                DB::table('machine_snapshot_items')->where('id', $item->id)->update(['po_day' => (int) round((float) $poDay)]);
            }
        });

        Schema::table('machine_snapshot_items', function (Blueprint $table) {
            $table->dropColumn(['std_min', 'max_pcs']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_snapshot_items', function (Blueprint $table) {
            $table->dropColumn('po_day');
            $table->unsignedInteger('std_min')->default(0);
            $table->unsignedInteger('max_pcs')->nullable();
        });

        Schema::table('machine_snapshots', function (Blueprint $table) {
            $table->index(['monitored_machine_id', 'local_date', 'slot_time'], 'machine_snapshots_slot_index');
        });

        Schema::table('machine_snapshots', function (Blueprint $table) {
            $table->dropIndex('machine_snapshots_production_slot_index');
            $table->dropColumn('production_date');
        });
    }
};
