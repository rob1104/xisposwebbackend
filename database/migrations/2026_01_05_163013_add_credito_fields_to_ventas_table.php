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
        Schema::table('Ventas', function (Blueprint $table) {
            $table->enum('tipo_pago', ['Contado', 'Credito'])->default('Contado')->after('total');
            $table->date('fecha_vencimiento')->nullable()->after('tipo_pago');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Ventas', function (Blueprint $table) {
            $table->dropColumn('tipo_pago', 'fecha_vencimiento');
        });
    }
};
