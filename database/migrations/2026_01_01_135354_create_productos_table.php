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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_barras', 100)->unique();
            $table->string('nombre', 255);
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->string('clave_prod_serv', 8)->nullable();
            $table->string('clave_unidad', 10);
            $table->string('objeto_imp', 2)->default('02');
            $table->enum('tipo_producto', ['Inventariable', 'Compuesto', 'Servicio'])->default('Inventariable');
            $table->decimal('ultimo_costo_compra', 13, 4)->default(0);
            $table->string('usuario_creador', 128);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
