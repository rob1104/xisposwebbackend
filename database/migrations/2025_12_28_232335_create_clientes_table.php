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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_global')->unique(); // El número aleatorio
            $table->string('nombre_comercial', 100);
            $table->string('razon_social', 100)->nullable();
            $table->string('rfc', 24)->nullable();
            $table->string('email', 99)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('telefono2', 30)->nullable();
            $table->string('contacto', 64)->nullable();

            // Dirección
            $table->string('calle', 99)->nullable();
            $table->string('no_exterior', 8)->nullable();
            $table->string('no_interior', 8)->nullable();
            $table->string('colonia', 100)->nullable();
            $table->string('codigo_postal', 5)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('estado', 100)->nullable();
            $table->string('pais', 50)->nullable();

            // Finanzas
            $table->decimal('limite_credito', 13, 2)->default(0);
            $table->decimal('saldo_actual', 13, 2)->default(0);
            $table->date('ultimo_pago')->nullable();

            $table->text('obs')->nullable();
            $table->string('usuario_creador', 128);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
