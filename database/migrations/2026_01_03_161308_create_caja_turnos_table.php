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
        Schema::create('caja_turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('sucursale_id')->constrained();
            $table->decimal('saldo_inicial', 12, 2);
            $table->decimal('tipo_cambio', 12, 4); // Para transacciones en dólares/otra moneda
            $table->timestamp('abierto_at');
            $table->timestamp('cerrado_at')->nullable();
            $table->enum('status', ['Abierto', 'Cerrado'])->default('Abierto');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caja_turnos');
    }
};
