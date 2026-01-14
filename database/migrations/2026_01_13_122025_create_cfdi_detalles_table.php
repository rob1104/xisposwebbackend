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
        Schema::create('cfdi_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cfdi_id')->constrained('cfdis')->onDelete('cascade');
            $table->foreignId('producto_id')->nullable()->constrained(); // Opcional, por si es un concepto genérico

            // Campos requeridos por el SAT (Anexo 20)
            $table->string('clave_prod_serv'); // Ejemplo: 50192100
            $table->string('clave_unidad');    // Ejemplo: H87
            $table->string('unidad')->nullable(); // Ejemplo: Pieza
            $table->string('descripcion');     // Descripción comercial
            $table->decimal('cantidad', 18, 6);
            $table->decimal('valor_unitario', 18, 6);
            $table->decimal('importe', 18, 6);
            $table->decimal('descuento', 18, 6)->default(0);

            // Impuestos por partida (Requisito CFDI 4.0)
            $table->string('objeto_imp')->default('02'); // 02 = Sí objeto de impuesto
            $table->string('impuesto_tipo')->default('002'); // 002 = IVA
            $table->string('impuesto_tasa_cuota')->default('0.080000');
            $table->decimal('impuesto_base', 18, 6);
            $table->decimal('impuesto_importe', 18, 6);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfdi_detalles');
    }
};
