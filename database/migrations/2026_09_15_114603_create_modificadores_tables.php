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
        Schema::create('modificador_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // Ej: "Extras", "Mitad y Mitad"
            $table->enum('tipo', ['opcion_multiple', 'opcion_unica', 'mitad_y_mitad'])->default('opcion_multiple');
            $table->integer('min_selecciones')->default(0);
            $table->integer('max_selecciones')->default(1);
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        Schema::create('modificador_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('modificador_grupos')->onDelete('cascade');
            $table->string('nombre'); // Ej: "Extra Queso", "Mitad Pepperoni"
            $table->decimal('precio_adicional', 14, 6)->default(0);
            
            // Relaciones para heredar recetas/ingredientes
            $table->foreignId('producto_receta_id')->nullable()->constrained('productos')->onDelete('set null')->comment('Si es mitad y mitad, hereda la receta de este producto');
            $table->foreignId('ingrediente_id')->nullable()->constrained('productos')->onDelete('set null')->comment('Si descuenta un ingrediente específico');
            $table->decimal('cantidad_descuento', 12, 3)->default(0)->comment('Cantidad del ingrediente_id a descontar');
            
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        Schema::create('producto_modificadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->onDelete('cascade');
            $table->foreignId('grupo_id')->constrained('modificador_grupos')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->json('modificadores_json')->nullable()->after('total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modificadores_tables');
    }
};
