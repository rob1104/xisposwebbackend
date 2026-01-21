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
        Schema::create('auditoria_inventario_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditoria_inventario_id')->constrained('auditoria_inventarios')->onDelete('cascade');
            $table->foreignId('producto_id')->constrained();
            $table->decimal('stock_sistema', 14, 6);
            $table->decimal('stock_fisico', 14, 6);
            $table->decimal('diferencia', 14, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditoria_inventario_detalles');
    }
};
