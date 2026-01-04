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
        Schema::create('caja_momivientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_turno_id')->constrained('caja_turnos'); // Vinculado al turno activo
            $table->enum('tipo', ['Entrada', 'Retiro']);
            $table->decimal('monto', 15, 2);
            $table->string('concepto');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caja_momivientos');
    }
};
