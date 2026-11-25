<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One of a member's school records: a level passed and its year (e.g. ICSE
 * 2003), or "left" with the class they left after and the year.
 */
class MemberSchoolRecord extends Model
{
    protected $table = 'member_school_records';

    protected $fillable = ['uid', 'member_record_id', 'level', 'year', 'class_left', 'stream', 'created_by', 'updated_by'];

    protected $casts = ['year' => 'integer', 'class_left' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uid ??= (string) Str::ulid());
    }

    public function memberRecord(): BelongsTo
    {
        return $this->belongsTo(MemberRecord::class);
    }
}
