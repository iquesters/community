<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A member's school records: what they completed at the school and when. One
 * row per level (ICSE 2003, ISC 2005) or "left" (class left after, year).
 * Replaces the single "batch" field: Indian schools have a Class 10 and a
 * Class 12 batch, possibly at two schools, and alumni who left earlier.
 *
 * Existing data is kept: each record's "batch" becomes a school record of the
 * first level (the conf's school_levels, e.g. ICSE). The batch metas stay in
 * member_record_metas; the directory form stops asking for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_school_records')) {
            Schema::create('member_school_records', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('member_record_id');
                // A level key of the organisation (e.g. icse, isc), or "left".
                $table->string('level', 32);
                $table->unsignedSmallInteger('year');
                // Only for "left": the class they left after.
                $table->unsignedTinyInteger('class_left')->nullable();
                $table->string('stream', 64)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['member_record_id', 'level']);
                $table->index(['level', 'year']);
                $table->foreign('member_record_id')->references('id')->on('member_records')->cascadeOnDelete();
            });
        }

        $this->moveBatches();
        $this->dropBatchFromDirectoryForm();
    }

    public function down(): void
    {
        Schema::dropIfExists('member_school_records');
    }

    private function moveBatches(): void
    {
        if (! Schema::hasTable('member_record_metas')) {
            return;
        }

        $level = 'icse';
        DB::table('member_record_metas')
            ->where('meta_key', 'batch')
            ->whereNotNull('meta_value')
            ->orderBy('id')
            ->get(['ref_parent', 'meta_value'])
            ->each(function ($meta) use ($level) {
                $year = (int) $meta->meta_value;
                if ($year < 1900 || $year > 2100) {
                    return;
                }
                $exists = DB::table('member_school_records')->where('member_record_id', $meta->ref_parent)->where('level', $level)->exists();
                if (! $exists) {
                    DB::table('member_school_records')->insert([
                        'uid' => (string) Str::ulid(),
                        'member_record_id' => $meta->ref_parent,
                        'level' => $level,
                        'year' => $year,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /** The directory form (admins' copy) asks for school records instead of "batch". */
    private function dropBatchFromDirectoryForm(): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $row = DB::table('form_schemas')->where('slug', 'community-member-record')->first(['id', 'schema']);
        $schema = $row ? json_decode((string) $row->schema, true) : null;
        if (! is_array($schema['fields'] ?? null)) {
            return;
        }

        $fields = array_values(array_filter($schema['fields'], fn ($f) => ($f['id'] ?? null) !== 'batch'));
        if (count($fields) !== count($schema['fields'])) {
            $schema['fields'] = $fields;
            DB::table('form_schemas')->where('id', $row->id)->update([
                'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }
};
