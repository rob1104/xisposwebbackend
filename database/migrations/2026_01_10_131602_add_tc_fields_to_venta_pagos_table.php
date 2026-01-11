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
        Schema::table('venta_pagos', function (Blueprint $table) {
            $table->string('moneda', 5)->default('MXN');
            $table->string('monto_original', 12,6)->default('MXN');
            $table->string('tipo_cambio_usado', 12,6)->default('MXN');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venta_pagos', function (Blueprint $table) {
            $table->dropColumn(['moneda', 'monto_original', 'tipo_cambio_usado']);
        });
    }
};
