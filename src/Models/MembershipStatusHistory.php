<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Iquesters\Foundation\Support\UserModel;

/** One change of a membership's approval status: who, when, from what to what, and why. */
class MembershipStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'membership_status_histories';

    protected $fillable = [
        'membership_id',
        'from_status',
        'to_status',
        'note',
        'changed_by',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::name(), 'changed_by');
    }
}
