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
        Schema::create('clave_prod_servs', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 8)->unique();
            $table->string('descripcion', 191);
            $table->boolean('estimulo')->default(true);
            $table->text('similares')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clave_prod_servs');
    }
};
