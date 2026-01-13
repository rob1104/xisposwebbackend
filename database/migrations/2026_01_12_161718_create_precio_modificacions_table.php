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
        Schema::create('precio_modificacions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->nullable()->constrained(); // Opcional, si quieres ligarlo a una venta
            $table->foreignId('producto_id')->constrained();
            $table->foreignId('user_id'); // Cajero
            $table->string('autorizado_por'); // Nombre del gerente que puso la clave
            $table->decimal('precio_original', 14, 4);
            $table->decimal('precio_nuevo', 14, 4);
            $table->string('motivo'); // El motivo capturado
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precio_modificacions');
    }
};
