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
        Schema::create('venta_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained()->onDelete('cascade');
            $table->enum('metodo_pago', ['Efectivo', 'Tarjeta Debito', 'Tarjeta Credito', 'Transferencia']);
            $table->decimal('monto', 14, 2); // Monto real que abona a la venta

            // --- Campos para Efectivo ---
            $table->decimal('efectivo_recibido', 14, 6)->nullable();
            $table->decimal('cambio_entregado', 14, 6)->nullable();

            // --- Campos para Tarjetas / Transferencias ---
            $table->string('tarjeta_ultimos_4', 4)->nullable();
            $table->string('referencia_pago')->nullable(); // Número de autorización de la terminal
            $table->string('banco_emisor')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venta_pagos');
    }
};
