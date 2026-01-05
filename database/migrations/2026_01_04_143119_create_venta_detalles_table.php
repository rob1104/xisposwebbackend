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
        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained()->onDelete('cascade');
            $table->foreignId('producto_id')->constrained();

            $table->decimal('cantidad', 12, 3); // Soporta productos pesables
            $table->decimal('precio_unitario', 14, 6); //
            $table->decimal('impuesto_unitario', 14, 6);
            $table->decimal('subtotal', 14, 6);
            $table->decimal('total', 14, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venta_detalles');
    }
};
