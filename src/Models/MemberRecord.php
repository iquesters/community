<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * One person in the member directory, with or without a login.
 *
 * @property string $uid
 * @property string $name
 * @property ?string $email
 * @property ?string $phone
 * @property string $source
 */
class MemberRecord extends Model
{
    public const SOURCE_BULK_UPLOAD = 'bulk_upload';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_REGISTRATION = 'registration';

    /** An organisation admin, made a member automatically (AdminMemberships). */
    public const SOURCE_ADMIN = 'admin';

    /** An alumnus guest of a paid event registration, without an account yet (linked when they sign in). */
    public const SOURCE_EVENT = 'event_registration';

    protected $table = 'member_records';

    protected $fillable = [
        'uid',
        'organisation_id',
        'user_id',
        'name',
        'email',
        'phone',
        'source',
        'status',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->uid ??= (string) Str::ulid();
        });
    }

    /** What they completed at the school: levels and years (ICSE 2003, ISC 2005), or the class they left after. */
    public function schoolRecords(): HasMany
    {
        return $this->hasMany(MemberSchoolRecord::class);
    }

    public function metas(): HasMany
    {
        return $this->hasMany(MemberRecordMeta::class, 'ref_parent');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function getMetaValue(string $key): ?string
    {
        return $this->metas()->where('meta_key', $key)->value('meta_value');
    }

    public function setMetaValue(string $key, ?string $value, int $userId = 0): MemberRecordMeta
    {
        return $this->metas()->updateOrCreate(
            ['meta_key' => $key],
            ['meta_value' => $value, 'status' => 'active', 'updated_by' => $userId]
        );
    }

    /** Meta values keyed by meta_key. */
    public function metaValues(): array
    {
        return $this->metas()->pluck('meta_value', 'meta_key')->all();
    }
}
