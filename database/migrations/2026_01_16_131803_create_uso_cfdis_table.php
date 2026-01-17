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
        Schema::create('uso_cfdis', function (Blueprint $table) {
            $table->id();
            $table->string('c_UsoCFDI', 5)->unique();
            $table->string('descripcion', 191);
            $table->boolean('aplica_fisica')->default(true);
            $table->boolean('aplica_moral')->default(true);
            $table->date('inicio_vigencia')->nullable();
            $table->date('fin_vigencia')->nullable();
            $table->text('regimen_receptor')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uso_cfdis');
    }
};
