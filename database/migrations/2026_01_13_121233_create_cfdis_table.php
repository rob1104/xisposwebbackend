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
        Schema::create('cfdis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursale_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cliente_id')->constrained();
            $table->string('serie');
            $table->string('folio');
            $table->string('uuid')->nullable()->unique();
            $table->enum('status', ['Vigente', 'Cancelado', 'Pendiente'])->default('Pendiente');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('impuestos', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('forma_pago', 2);
            $table->string('metodo_pago', 3);
            $table->string('uso_cfdi', 3);
            $table->string('exportacion', 2)->default('01');
            $table->text('xml_path')->nullable();
            $table->text('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfdis');
    }
};
