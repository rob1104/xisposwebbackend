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
        Schema::create('auditoria_inventarios', function (Blueprint $table) {
            $table->id();$table->foreignId('sucursale_id')->constrained(); // Conteo por sucursal
            $table->foreignId('user_id')->constrained();      // Quién realizó el conteo
            $table->timestamp('fecha');
            $table->text('observaciones')->nullable();
            $table->enum('status', ['Finalizado', 'Cancelado'])->default('Finalizado');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria_inventarios');
    }
};
