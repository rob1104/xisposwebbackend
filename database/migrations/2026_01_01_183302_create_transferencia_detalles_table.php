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
        Schema::create('transferencia_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('transferencias')->onDelete('cascade');
            $table->foreignId('producto_id')->constrained('productos');

            // Cantidades con precisión 14,6
            $table->decimal('cantidad_enviada', 14, 6);
            $table->decimal('cantidad_recibida', 14, 6)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transferencia_detalles');
    }
};
