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
        Schema::table('cfdis', function (Blueprint $table) {
            $table->string('motivo_cancelacion', 2)->nullable();
            $table->timestamp('fecha_cancelacion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cfdis', function (Blueprint $table) {
            $table->dropColumn(['motivo_cancelacion', 'fecha_cancelacion']);
        });
    }
};
