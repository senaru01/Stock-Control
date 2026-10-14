<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('machine_stock_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_machine_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_days', 5, 2);
            $table->decimal('max_days', 5, 2);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->index(['monitored_machine_id', 'effective_from'], 'machine_stock_controls_effective_index');
        });

        /* Machines added before parameters existed start with the default 1 – 2 days, covering their whole history. */
        DB::table('monitored_machines')->orderBy('id')->each(function (object $machine) {
            $firstSnapshot = DB::table('machine_snapshots')->where('monitored_machine_id', $machine->id)->min('taken_at');

            DB::table('machine_stock_controls')->insert([
                'monitored_machine_id' => $machine->id,
                'min_days' => 1,
                'max_days' => 2,
                'effective_from' => min(array_filter([$firstSnapshot, $machine->created_at])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_stock_controls');
    }
};
