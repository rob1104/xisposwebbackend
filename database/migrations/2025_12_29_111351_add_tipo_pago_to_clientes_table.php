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
        Schema::table('clientes', function (Blueprint $table) {
            // 0=DESHABILITADO, 1=CONTADO, 2=CREDITO, 3=CONTADO Y CREDITO
            $table->tinyInteger('tipo_pago')->default(1)->after('saldo_actual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('tipo_pago');
        });
    }
};
