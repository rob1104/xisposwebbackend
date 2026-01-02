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
        Schema::create('producto_composicion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_padre_id')->constrained('productos')->onDelete('cascade');
            $table->foreignId('producto_hijo_id')->constrained('productos');
            $table->decimal('cantidad', 14, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_composicions');
    }
};
