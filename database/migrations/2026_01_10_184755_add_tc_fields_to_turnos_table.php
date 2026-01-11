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
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->decimal('tarjeta_esperado', 16, 2)->default(0);
            $table->decimal('tarjeta_contado', 16, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('turnos', function (Blueprint $table) {
            $table->dropColumn(['tarjeta_esperado', 'tarjeta_contado']);
        });
    }
};
