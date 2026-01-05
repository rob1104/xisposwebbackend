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
            // Saldo contado físicamente por el cajero
            $table->decimal('saldo_cierre', 15, 2)->default(0)->after('saldo_inicial');

            // Diferencia entre efectivo esperado y contado
            $table->decimal('diferencia', 15, 2)->default(0)->after('saldo_cierre');

            // Desglose de billetes y monedas en formato JSON
            $table->text('denominaciones_arqueo')->nullable()->after('diferencia');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->dropColumn(['saldo_cierre', 'diferencia', 'denominaciones_arqueo']);
        });
    }
};
