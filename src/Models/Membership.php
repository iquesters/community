<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Foundation\Support\UserModel;
use Iquesters\Organisation\Models\Organisation;

/**
 * A person's membership of one organisation: their application and its approval.
 * (Paid / unpaid and validity are separate and come with membership periods.)
 *
 * @property string $uid
 * @property string $status
 * @property array|null $application_data
 */
class Membership extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_APPLIED, self::STATUS_ON_HOLD, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** Waiting for an admin. */
    public const OPEN_STATUSES = [self::STATUS_APPLIED, self::STATUS_ON_HOLD];

    /** Which status may follow which. A rejected person applies again with a new application. */
    public const TRANSITIONS = [
        self::STATUS_APPLIED => [self::STATUS_ON_HOLD, self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_ON_HOLD => [self::STATUS_APPLIED, self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [],
        self::STATUS_REJECTED => [],
    ];

    public const LABELS = [
        self::STATUS_APPLIED => 'Applied',
        self::STATUS_ON_HOLD => 'On hold',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $table = 'memberships';

    protected $fillable = [
        'uid',
        'organisation_id',
        'user_id',
        'member_record_id',
        'status',
        'application_data',
        'decision_note',
        'decided_by',
        'decided_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'application_data' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $membership) {
            $membership->uid ??= (string) Str::ulid();
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::name());
    }

    public function memberRecord(): BelongsTo
    {
        return $this->belongsTo(MemberRecord::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MembershipStatusHistory::class)->orderBy('id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(ApplicationProof::class)->orderBy('id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? Str::headline($this->status);
    }
}
