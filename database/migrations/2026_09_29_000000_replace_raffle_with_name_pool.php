<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raffle_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->index(['event_id', 'position']);
        });

        Schema::create('event_raffle_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('remove_winners')->default(true);
            $table->unsignedTinyInteger('speed')->default(3);
            $table->string('theme', 40)->default('purple');
            $table->timestamps();
        });

        Schema::table('raffle_winners', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('event_id');
        });

        DB::table('raffle_winners')
            ->join('registrations', 'registrations.id', '=', 'raffle_winners.registration_id')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->select('raffle_winners.id', 'attendees.first_name', 'attendees.last_name')
            ->orderBy('raffle_winners.id')
            ->each(function ($winner): void {
                DB::table('raffle_winners')->where('id', $winner->id)->update([
                    'name' => trim($winner->first_name.' '.$winner->last_name),
                ]);
            });

        Schema::table('raffle_winners', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropForeign(['raffle_draw_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropUnique(['raffle_draw_id']);
            $table->dropUnique(['event_id', 'registration_id']);
            $table->dropColumn(['registration_id', 'raffle_draw_id', 'confirmed_by']);
        });

        Schema::table('raffle_draws', function (Blueprint $table) {
            $table->dropForeign(['registration_id']);
            $table->dropIndex(['event_id', 'registration_id']);
            $table->dropColumn('registration_id');
            $table->foreignId('raffle_entry_id')->nullable()->after('event_id')->constrained('raffle_entries')->nullOnDelete();
            $table->index(['event_id', 'raffle_entry_id']);
        });

        DB::table('raffle_draws')->where('status', 'pending')->whereNull('raffle_entry_id')->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('This forward-only raffle migration cannot be rolled back without restoring a database backup.');
    }
};
