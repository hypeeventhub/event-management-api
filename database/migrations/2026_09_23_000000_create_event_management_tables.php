<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('qr_description')->nullable();
            $table->string('category')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('venue')->nullable();
            $table->string('meeting_url')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone')->default('UTC');
            $table->dateTime('registration_opens_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('registration_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'version']);
            $table->index(['event_id', 'is_active']);
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_form_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('system_key')->nullable();
            $table->string('type');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('placeholder')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->json('validation_rules')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['registration_form_id', 'key']);
            $table->index(['registration_form_id', 'position']);
        });

        Schema::create('field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['form_field_id', 'value']);
        });

        Schema::create('attendees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('email_normalized')->unique();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->timestamps();
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_form_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->string('registration_code')->unique();
            $table->string('status')->default('confirmed');
            $table->string('source')->default('public_form');
            $table->string('qr_token_hash', 64)->nullable()->unique();
            $table->dateTime('registered_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'attendee_id']);
            $table->index(['event_id', 'status']);
            $table->index('registered_at');
        });

        Schema::create('registration_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained()->restrictOnDelete();
            $table->json('answer')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'form_field_id']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_in_at');
            $table->string('gate')->nullable();
            $table->string('device_id')->nullable();
            $table->string('result');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['registration_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('registration_answers');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('attendees');
        Schema::dropIfExists('field_options');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('registration_forms');
        Schema::dropIfExists('events');
    }
};
