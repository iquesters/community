<?php

namespace Iquesters\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Proof sent with an application: an identifier (e.g. a registration number)
 * or a file, stored on the private disk. Only the applicant and the admins who
 * approve applications may see it.
 */
class ApplicationProof extends Model
{
    public const KIND_IDENTIFIER = 'identifier';

    public const KIND_FILE = 'file';

    /** Private disk folder. */
    public const DIRECTORY = 'community/proofs';

    protected $table = 'application_proofs';

    protected $fillable = [
        'uid',
        'membership_id',
        'field',
        'kind',
        'label',
        'value',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $proof) {
            $proof->uid ??= (string) Str::ulid();
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function isFile(): bool
    {
        return $this->kind === self::KIND_FILE;
    }
}
