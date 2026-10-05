<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function ($table) {
            $table->renameColumn('image_url', 'image_path');
        });

        // Corrige os registros que já tinham a URL completa (com host) salva:
        // guarda só o caminho relativo (ex.: "events/arquivo.png"). A URL
        // final volta a ser calculada em tempo de leitura (Event::getImageUrlAttribute).
        DB::table('events')->whereNotNull('image_path')->get()->each(function ($row) {
            $path = $row->image_path;

            if (str_contains($path, '/storage/')) {
                $path = substr($path, strpos($path, '/storage/') + strlen('/storage/'));
            }

            DB::table('events')->where('id', $row->id)->update(['image_path' => $path]);
        });
    }

    public function down(): void
    {
        Schema::table('events', function ($table) {
            $table->renameColumn('image_path', 'image_url');
        });
    }
};
