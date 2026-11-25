<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberRecordMeta extends Model
{
    protected $table = 'member_record_metas';

    protected $fillable = [
        'ref_parent',
        'meta_key',
        'meta_value',
        'status',
        'created_by',
        'updated_by',
    ];

    public function memberRecord(): BelongsTo
    {
        return $this->belongsTo(MemberRecord::class, 'ref_parent');
    }
}
