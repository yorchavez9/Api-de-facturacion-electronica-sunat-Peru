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
        Schema::table('boletas', function (Blueprint $table) {
            // Agregar columna para el método de envío (individual o resumen_diario)
            $table->string('metodo_envio', 20)->default('individual')->after('moneda');

            // Agregar relación con resumen diario (nullable porque solo aplica si metodo_envio es resumen_diario)
            // Sin foreign key por ahora, se agregará cuando exista la tabla daily_summaries
            $table->unsignedBigInteger('daily_summary_id')->nullable()->after('client_id')->index();

            // Agregar columnas para impuestos adicionales
            $table->decimal('mto_igv_gratuitas', 12, 2)->default(0)->after('mto_oper_gratuitas');
            $table->decimal('mto_base_ivap', 12, 2)->default(0)->after('mto_igv');
            $table->decimal('mto_ivap', 12, 2)->default(0)->after('mto_base_ivap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn([
                'metodo_envio',
                'daily_summary_id',
                'mto_igv_gratuitas',
                'mto_base_ivap',
                'mto_ivap'
            ]);
        });
    }
};
