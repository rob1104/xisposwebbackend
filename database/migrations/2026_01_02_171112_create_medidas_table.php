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
        Schema::create('medidas', function (Blueprint $table) {
            $table->id();
            $table->string('c_ClaveUnidad', 5)->unique(); // Código SAT (ej. H87)
            $table->string('nombre', 100);               // Nombre SAT (ej. Pieza)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medidas');
    }
};
