<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('seating_enabled')->default(false)->after('capacity');
            $table->unsignedInteger('table_count')->nullable()->after('seating_enabled');
            $table->unsignedInteger('seats_per_table')->nullable()->after('table_count');
        });

        Schema::create('event_seat_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('table_number');
            $table->unsignedInteger('seat_number');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'registration_id']);
            $table->unique(['event_id', 'table_number', 'seat_number']);
            $table->index(['event_id', 'table_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seat_assignments');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['seating_enabled', 'table_count', 'seats_per_table']);
        });
    }
};
