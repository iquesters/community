<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Str;
use Iquesters\Community\Models\ApplicationProof;

/** The proof fields asked for when applying (conf `proof_fields`), cleaned up. */
class ProofFields
{
    /**
     * @return array<int, array{id: string, label: string, kind: string, required: bool, types: string[], max_kb: int}>
     */
    public static function all(): array
    {
        $result = [];
        foreach ((array) DirectoryFields::conf()->proof_fields as $field) {
            $id = is_array($field) ? Str::snake((string) ($field['id'] ?? '')) : '';
            if ($id === '' || isset($result[$id])) {
                continue;
            }

            $kind = ($field['kind'] ?? '') === ApplicationProof::KIND_FILE ? ApplicationProof::KIND_FILE : ApplicationProof::KIND_IDENTIFIER;
            $result[$id] = [
                'id' => $id,
                'label' => (string) ($field['label'] ?? Str::headline($id)),
                'kind' => $kind,
                'required' => (bool) ($field['required'] ?? false),
                'types' => $kind === ApplicationProof::KIND_FILE
                    ? array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($field['types'] ?? 'pdf,jpg,jpeg,png'))))))
                    : [],
                'max_kb' => max(1, min(20480, (int) ($field['max_kb'] ?? 5120))),
            ];
        }

        return array_values($result);
    }

    /** Validation rules, keyed proof.{id}. */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::all() as $field) {
            $rules["proof.{$field['id']}"] = $field['kind'] === ApplicationProof::KIND_FILE
                ? [$field['required'] ? 'required' : 'nullable', 'file', 'mimes:'.implode(',', $field['types']), 'max:'.$field['max_kb']]
                : [$field['required'] ? 'required' : 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public static function attributes(): array
    {
        return collect(self::all())->mapWithKeys(fn ($f) => ["proof.{$f['id']}" => $f['label']])->all();
    }
}
