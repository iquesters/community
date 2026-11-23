<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Applications and their approval: one membership per person per
     * organisation, the history of its status, and the proof sent with it.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('organisation_id');
            // The applicant's login. Null for memberships an admin adds for someone without one (later).
            $table->unsignedBigInteger('user_id')->nullable();
            // Set on approval: the existing record it was linked to, or the one created from the application.
            $table->unsignedBigInteger('member_record_id')->nullable();
            // applied | on_hold | approved | rejected
            $table->string('status', 16)->default('applied');
            // What the applicant entered (directory field id => value), kept as sent.
            $table->json('application_data')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
            $table->index(['user_id', 'organisation_id']);
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
            $table->foreign('member_record_id')->references('id')->on('member_records')->nullOnDelete();
        });

        Schema::create('membership_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('membership_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('membership_id');
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
        });

        Schema::create('application_proofs', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('membership_id');
            // The proof field's id from the conf (e.g. registration_number).
            $table->string('field', 64);
            // identifier | file
            $table->string('kind', 16);
            $table->string('label');
            // identifier: the value. file: null.
            $table->string('value')->nullable();
            // file: path on the private disk, never under public/.
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index('membership_id');
            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_proofs');
        Schema::dropIfExists('membership_status_histories');
        Schema::dropIfExists('memberships');
    }
};
