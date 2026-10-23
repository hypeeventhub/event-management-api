<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->text('name')->change();
        });
    }

    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'length(name)' : 'char_length(name)';
        if (DB::table('voting_contestants')->whereRaw($length.' > 255')->exists()) {
            throw new RuntimeException('Cannot narrow contestant names while names exceed 255 characters.');
        }

        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->string('name')->change();
        });
    }
};
