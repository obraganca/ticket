<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // JSON [{name, email}, ...], um item por ingresso (mesma ordem em
            // que os tickets são gerados quando o pagamento é aprovado).
            $table->json('ticket_holders')->nullable()->after('buyer_document');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ticket_holders');
        });
    }
};
