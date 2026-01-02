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
        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('user_id')->constrained('users'); // Quién hizo el movimiento

            // Entrada, Salida, Traspaso, Ajuste, Venta, Compra
            $table->string('tipo_movimiento', 50);

            // Usamos la misma precisión que sucursal_productos
            $table->decimal('cantidad', 14, 6);
            $table->decimal('stock_anterior', 14, 6);
            $table->decimal('stock_nuevo', 14, 6);

            // Para saber de dónde viene (ID de venta, ID de compra, etc.)
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventario_movimientos');
    }
};
