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
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique(); // Ej: V-00001
            $table->foreignId('sucursale_id')->constrained(); //
            $table->foreignId('user_id')->constrained(); // Cajero que realizó la venta
            $table->foreignId('caja_turno_id')->constrained('caja_turnos'); // Vínculo obligatorio
            $table->foreignId('cliente_id')->nullable()->constrained(); // Default: Público General

            $table->decimal('subtotal', 14, 6);
            $table->decimal('impuestos', 14, 6);
            $table->decimal('total', 14, 6);
            $table->decimal('tipo_cambio', 12, 6); // Se guarda el T.C. al momento de la venta

            $table->enum('status', ['Completada', 'Cancelada'])->default('Completada');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
