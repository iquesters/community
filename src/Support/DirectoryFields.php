<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Iquesters\Community\Config\CommunityConf;
use Iquesters\Community\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;

/**
 * The directory's fields, read from the directory form schema (conf
 * `directory_form`). One definition drives the record form, the bulk upload
 * columns, validation and matching.
 */
class DirectoryFields
{
    /** member_records columns a directory field may write. */
    public const CORE = ['name', 'email', 'phone'];

    /** Columns the form may show that aren't directory fields (the upload sets them itself). */
    public const NOT_DIRECTORY = ['organisation_id', 'user_id'];

    /** Field types that carry no value. */
    private const SKIPPED_TYPES = ['hidden', 'spacer', 'heading', 'divider', 'html', 'file', 'image'];

    public static function conf(): CommunityConf
    {
        /** @var CommunityConf */
        return ConfProvider::from(Module::COMMUNITY);
    }

    public static function formSlug(): string
    {
        return (string) self::conf()->directory_form ?: 'community-member-record';
    }

    /**
     * @return array<int, array{id: string, label: string, type: string, required: bool, meta: bool, options: array, field: array}>
     */
    public static function all(): array
    {
        $fields = self::formFields() ?? DirectoryPresets::fields((string) self::conf()->directory_preset);

        $result = [];
        foreach ($fields as $field) {
            $id = is_array($field) ? (string) ($field['id'] ?? '') : '';
            $type = strtolower((string) ($field['type'] ?? 'text'));

            if ($id === '' || in_array($id, self::NOT_DIRECTORY, true) || in_array($type, self::SKIPPED_TYPES, true)) {
                continue;
            }

            $isCore = in_array($id, self::CORE, true);
            // A non-core field is always a meta, whatever the form says: only core columns exist.
            $result[] = [
                'id' => $id,
                'label' => (string) ($field['label'] ?? Str::headline($id)),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'meta' => ! $isCore,
                'options' => self::options($field),
                'field' => $field,
            ];
        }

        // The name column is required even if an admin removed it from the form.
        if (! collect($result)->contains('id', 'name')) {
            array_unshift($result, [
                'id' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true,
                'meta' => false, 'options' => [], 'field' => DirectoryPresets::coreFields()[0],
            ]);
        }

        return $result;
    }

    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }

    /** Validation rules for one directory field (bulk upload rows and applications). */
    public static function rulesFor(array $field): array
    {
        $definition = $field['field'];
        $rules = [$field['required'] ? 'required' : 'nullable'];

        switch ($field['type']) {
            case 'email':
                $rules[] = 'email';
                break;
            case 'number':
                $rules[] = 'numeric';
                break;
            case 'date':
                $rules[] = 'date';
                break;
            case 'select':
            case 'radio':
                if ($field['options'] !== []) {
                    $rules[] = Rule::in($field['options']);
                }
                break;
        }

        if ($field['type'] === 'number') {
            if (isset($definition['min'])) {
                $rules[] = 'min:'.$definition['min'];
            }
            if (isset($definition['max'])) {
                $rules[] = 'max:'.$definition['max'];
            }
        } elseif (isset($definition['maxLength'])) {
            $rules[] = 'max:'.(int) $definition['maxLength'];
        }

        return $rules;
    }

    /** Entity meta_fields for member_records, so the entity API saves the directory's meta fields. */
    public static function entityMetaFields(): array
    {
        return collect(self::all())
            ->where('meta', true)
            ->map(fn (array $f) => array_filter([
                'meta_key' => $f['id'],
                'label' => $f['label'],
                'type' => match ($f['type']) {
                    'number' => 'integer',
                    'email' => 'email',
                    'date' => 'date',
                    default => 'string',
                },
                'required' => $f['required'],
                'maxlength' => $f['field']['maxLength'] ?? null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /** Write entityMetaFields() onto the member_records entity (seeder, after the form changes). */
    public static function syncEntity(): void
    {
        if (! Schema::hasTable('entities')) {
            return;
        }

        DB::table('entities')->where('entity_name', 'member_records')->update([
            'meta_fields' => json_encode(self::entityMetaFields(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private static function formFields(): ?array
    {
        if (! Schema::hasTable('form_schemas')) {
            return null;
        }

        $raw = DB::table('form_schemas')->where('slug', self::formSlug())->where('status', 'active')->value('schema');
        $schema = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($schema['fields'] ?? null) && $schema['fields'] !== [] ? $schema['fields'] : null;
    }

    /** Allowed values of a select / radio field (static options only). */
    private static function options(array $field): array
    {
        $values = [];
        foreach ((array) ($field['options'] ?? []) as $key => $option) {
            if (is_array($option)) {
                $values[] = (string) ($option['value'] ?? $option['id'] ?? $key);
            } else {
                $values[] = is_string($key) ? $key : (string) $option;
            }
        }

        return $values;
    }
}
