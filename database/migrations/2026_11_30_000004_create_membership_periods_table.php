<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid membership periods (e.g. 1 Jan – 31 Dec 2027): one per membership line
 * of a paid commerce order, for the person's membership, or for a member
 * record when they have no account yet (linked when they sign in).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('membership_periods')) {
            return;
        }
        Schema::create('membership_periods', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->unsignedBigInteger('member_record_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // The commerce order line that paid it (one period per line).
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_line_id')->nullable()->unique();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('reference', 48)->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            // active | cancelled
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['organisation_id', 'valid_until']);
            $table->index('user_id');
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
            $table->foreign('membership_id')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('member_record_id')->references('id')->on('member_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_periods');
    }
};
