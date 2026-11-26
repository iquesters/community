<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/** A paid membership period (from a commerce order's membership line). */
class MembershipPeriod extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'membership_periods';

    protected $fillable = [
        'uid', 'organisation_id', 'membership_id', 'member_record_id', 'user_id', 'order_id', 'order_line_id', 'product_id',
        'reference', 'name', 'email', 'valid_from', 'valid_until', 'amount', 'status',
    ];

    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(function (self $period) {
            $period->uid ??= (string) Str::ulid();
            $period->status ??= self::STATUS_ACTIVE;
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function memberRecord(): BelongsTo
    {
        return $this->belongsTo(MemberRecord::class);
    }

    /** Active periods that cover the date (today by default; open-ended ends count). */
    public function scopeCurrent(Builder $query, ?\DateTimeInterface $on = null): Builder
    {
        $day = ($on ? Carbon::instance($on) : now())->toDateString();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }
}
