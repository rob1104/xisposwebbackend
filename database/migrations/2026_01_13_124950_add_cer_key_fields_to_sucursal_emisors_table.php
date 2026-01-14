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
        Schema::table('sucursal_emisors', function (Blueprint $table) {
            $table->string('cer_path')->nullable()->after('registro_patronal');
            $table->string('key_path')->nullable()->after('cer_path');
            $table->string('password_csd')->nullable()->after('key_path'); // Contraseña para desencriptar la llave
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sucursal_emisors', function (Blueprint $table) {
            //
        });
    }
};
