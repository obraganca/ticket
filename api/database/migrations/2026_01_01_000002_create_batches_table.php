<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedInteger('total');
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedBigInteger('revenue_cents')->default(0);
            $table->timestamps();
        });

        // Última linha de defesa contra overselling (P2): mesmo que um bug
        // no código da Action deixasse passar, o banco recusa o commit.
        DB::statement('ALTER TABLE batches ADD CONSTRAINT batches_stock_check CHECK (reserved >= 0 AND sold >= 0 AND reserved + sold <= total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
