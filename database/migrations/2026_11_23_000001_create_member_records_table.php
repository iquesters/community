<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The member directory: one row per person, with or without a login.
     * App-specific fields (batch, section, roll number…) live in member_record_metas.
     */
    public function up(): void
    {
        Schema::create('member_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('organisation_id')->nullable();
            // Linked once the person's registration is approved (step 2); null for records without a login.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            // Stored normalised (digits, optional leading +), so matching can compare exactly.
            $table->string('phone', 32)->nullable();
            // bulk_upload | manual | registration
            $table->string('source', 32)->default('manual');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(['organisation_id', 'email']);
            $table->index(['organisation_id', 'phone']);
            $table->index(['organisation_id', 'name']);
            $table->index('user_id');
            $table->foreign('organisation_id')->references('id')->on('organisations')->nullOnDelete();
        });

        Schema::create('member_record_metas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ref_parent');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->unique(['ref_parent', 'meta_key']);
            $table->index('meta_key');
            $table->foreign('ref_parent')->references('id')->on('member_records')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_record_metas');
        Schema::dropIfExists('member_records');
    }
};
