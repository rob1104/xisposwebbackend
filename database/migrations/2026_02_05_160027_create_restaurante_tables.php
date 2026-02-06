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
        // 1. Catálogo de Mesas
        Schema::create('rest_mesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursale_id')->constrained('sucursales');
            $table->string('nombre'); // "Mesa 1", "Barra 1", "Para Llevar"
            $table->string('zona')->nullable(); // "Terraza", "Salón"
            $table->boolean('ocupada')->default(false);
            $table->timestamps();
        });

        // 2. Encabezado de la Orden (Comanda)
        Schema::create('rest_ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursale_id')->constrained('sucursales');
            $table->foreignId('mesa_id')->nullable()->constrained('rest_mesas'); // Null si es "Para llevar" rápido sin mesa fija
            $table->foreignId('mesero_id')->constrained('users'); // Quién abrió la mesa
            $table->string('nombre_cliente')->nullable(); // Para "Para Llevar"
            $table->decimal('total', 10, 2)->default(0);
            // Estatus: 'Abierta', 'Cocina' (ya se mandó algo), 'Cerrada' (lista para cobrar), 'Pagada'
            $table->string('estatus')->default('Abierta');
            $table->string('codigo_cobro')->unique()->nullable(); // El código de barras (Ej: CMD-10050)
            $table->timestamps();
        });

        // 3. Detalles de la Orden
        Schema::create('rest_orden_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rest_orden_id')->constrained('rest_ordenes');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('precio', 10, 2);
            $table->string('notas')->nullable(); // "Sin cebolla", "Bien cocido"
            $table->boolean('impreso_cocina')->default(false); // Para saber qué items ya se mandaron a imprimir
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rest_mesas');
        Schema::dropIfExists('rest_ordenes');
        Schema::dropIfExists('rest_orden_detalles');
    }
};
