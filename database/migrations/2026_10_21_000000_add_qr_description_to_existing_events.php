<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'qr_description')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->text('qr_description')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('qr_description');
        });
    }
};
