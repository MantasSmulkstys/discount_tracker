<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'logo_path')) {
            return;
        }

        DB::table('stores')
            ->where('logo_path', 'like', '/storage/store-logos/%')
            ->update([
                'logo_path' => DB::raw("REPLACE(logo_path, '/storage/store-logos/', '/store-logos/')"),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stores', 'logo_path')) {
            return;
        }

        DB::table('stores')
            ->where('logo_path', 'like', '/store-logos/%')
            ->update([
                'logo_path' => DB::raw("REPLACE(logo_path, '/store-logos/', '/storage/store-logos/')"),
            ]);
    }
};
