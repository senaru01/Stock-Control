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
        Schema::create('partlists', function (Blueprint $table) {
            $table->string('code', 100)->primary();
            $table->string('level', 50);
            $table->string('customer_code', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->string('part_no', 50)->index();
            $table->string('part_no_fg', 50)->nullable();
            $table->string('job_no', 50)->nullable();
            $table->string('part_name')->nullable();
            $table->string('type_box', 50)->nullable();
            $table->unsignedInteger('qty_kbn')->default(0);
            $table->string('process', 50)->nullable();
            $table->string('line', 50)->nullable();
            $table->string('line_code', 50)->nullable();
            $table->string('rack_no', 50)->nullable();
            $table->unsignedInteger('cap_rack')->default(0);
            $table->string('jig_no', 100)->nullable();
            $table->unsignedInteger('qty_lot')->default(0);
            $table->unsignedInteger('stock_min')->default(0);
            $table->unsignedInteger('stock_max')->default(0);
            $table->string('image_name')->nullable();
            $table->string('last_routing', 50)->nullable();
            $table->string('remark')->nullable();
            $table->unsignedInteger('lt_pull')->default(0);
            $table->unsignedInteger('lt_prod')->default(0);
            $table->string('code_partset', 100)->nullable();
            $table->string('set_label', 50)->nullable();
            $table->unsignedInteger('prod_point')->default(0);
            $table->string('cat_machine', 50)->nullable();
            $table->unsignedInteger('spm')->default(0);
            $table->unsignedInteger('dandori')->default(0);
            $table->unsignedInteger('cek_startfinish')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partlists');
    }
};
