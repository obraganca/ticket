<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('ticket_type_id')->nullable()->after('event_id')->constrained()->cascadeOnDelete();
        });

        // Backfill: cada lote que já existe vira o seu próprio "tipo de
        // ingresso" (preservando o nome atual, ex.: "Pista 1º lote"), com um
        // lote chamado "1º lote" dentro dele. Depois, use a tela de "Tipos de
        // ingresso" pra renomear/organizar como quiser.
        DB::table('batches')->orderBy('id')->get()->each(function ($batch) {
            $ticketTypeId = DB::table('ticket_types')->insertGetId([
                'event_id' => $batch->event_id,
                'name' => $batch->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('batches')->where('id', $batch->id)->update([
                'ticket_type_id' => $ticketTypeId,
                'name' => '1º lote',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_type_id');
        });
    }
};
