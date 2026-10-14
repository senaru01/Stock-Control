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
        Schema::table('partlists', function (Blueprint $table) {
            $table->string('po_day', 50)->nullable()->after('cek_startfinish');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partlists', function (Blueprint $table) {
            $table->dropColumn('po_day');
        });
    }
};
