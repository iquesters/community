<?php

namespace Iquesters\Community\Support;

/**
 * Normalises values before they are saved or compared, so "A@B.com " and
 * "a@b.com" (or "+91 98300-12345" and "+919830012345") are the same person.
 */
class MemberValue
{
    public static function email(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /** Digits only, keeping a leading +. */
    public static function phone(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : (str_starts_with($value, '+') ? '+'.$digits : $digits);
    }

    /** Single spaces, trimmed. */
    public static function text(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /** For comparing: lower case, single spaces. */
    public static function key(?string $value): string
    {
        return mb_strtolower((string) self::text($value));
    }

    public static function forField(string $id, ?string $value): ?string
    {
        return match ($id) {
            'email' => self::email($value),
            'phone' => self::phone($value),
            default => self::text($value),
        };
    }
}
