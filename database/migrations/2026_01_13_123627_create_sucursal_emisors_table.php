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
        Schema::create('sucursal_emisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursale_id')->constrained()->onDelete('cascade');

            // Datos obligatorios para el Nodo Emisor CFDI 4.0
            $table->string('rfc', 13);
            $table->string('razon_social'); // Debe coincidir exactamente con la Constancia
            $table->string('regimen_fiscal', 3); // Ejemplo: 601, 612
            $table->string('codigo_postal', 5); // Lugar de expedición

            // Datos adicionales útiles
            $table->string('curp', 18)->nullable(); // Solo para personas físicas
            $table->string('registro_patronal')->nullable();
            $table->string('logo_path')->nullable(); // Para el PDF de la factura

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sucursal_emisors');
    }
};
