<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B14: a linha de email_deliveries só vale como "enviado" quando sent_at está
 * preenchido. Antes, ela era gravada ANTES do envio e um SMTP fora do ar
 * fazia o retry pular o e-mail para sempre (venda sem comprovante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->timestamp('attempted_at')->nullable();
        });
        DB::statement('ALTER TABLE email_deliveries ALTER COLUMN sent_at DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE email_deliveries SET sent_at = COALESCE(sent_at, now())');
        DB::statement('ALTER TABLE email_deliveries ALTER COLUMN sent_at SET NOT NULL');
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropColumn('attempted_at');
        });
    }
};
