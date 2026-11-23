# Member directory and bulk upload

## Records

A **member record** is a person in the directory. It may have no login: a 1962 alumnus from the school's register is a full record. A user is linked later, when their registration is approved (step 2).

| Table | Model | Holds |
|---|---|---|
| `member_records` | `MemberRecord` | `uid`, `organisation_id`, `user_id` (nullable), `name`, `email`, `phone` (normalised), `source` (`bulk_upload` / `manual` / `registration`), `status` |
| `member_record_metas` | `MemberRecordMeta` | The app's own fields: `ref_parent`, `meta_key`, `meta_value` (unique per record and key) |

Model names follow Laravel: singular class, plural table.

## Directory fields

`DirectoryFields::all()` returns the directory's fields from the form schema named by `directory_form`:

- `name`, `email` and `phone` are `member_records` columns; every other field is a meta field (`"meta": true` in the form, so the form saves it in `meta`);
- `name` is always there, even if removed from the form;
- value-less types (hidden, spacer, heading, file, image…) are skipped.

The seeder creates the form once from `directory_preset` (`DirectoryPresets`), then leaves it to the admins. After changing the form's fields, run `php artisan community:seed`: it writes the meta fields onto the `member_records` entity (`DirectoryFields::syncEntity()`), which the entity API needs to save them.

## Matching

`MemberMatcher::find($organisationId, $data)` returns existing records that match any key of `match_keys`, with the keys each matched on. Values are compared case-insensitively; emails lower-case and phones as digits (`MemberValue`).

## Applications and approval

`MembershipService`:

- `apply($user, $organisation, $data, $proofs)`: checks the directory fields (`DirectoryFields::rulesFor`) and `ProofFields::rules()`, then creates a `Membership` (`applied`), or sends an application on hold back to `applied`. Refused while one is waiting or once approved; after a rejection it creates a new one.
- `matches($membership)`: `MemberMatcher::find()` on the application, flagging records linked to another account.
- `approve($membership, $adminId, ?$linkRecordUid, ?$note)`: links the account to the record (same organisation, not linked to someone else), or creates one from the application (source `registration`, values normalised, meta fields written).
- `hold(...)` / `reject(...)` need a note, which the applicant sees.

Moves allowed (`Membership::TRANSITIONS`): applied → on hold / approved / rejected; on hold → applied / approved / rejected. Each move adds a `membership_status_histories` row.

Approval status is separate from payment and validity, which come with membership periods (step 3).

## Bulk upload

`MemberImportService`:

1. `preview($path, $organisation, $userId)` reads the CSV, maps columns to fields (by id or label), checks each row and sorts it into `valid`, `error`, `duplicate_in_file` or `existing`. The result is cached for 30 minutes under a token, for that user only.
2. `import($token, $userId, 'skip'|'update')` creates the valid rows in one transaction. `existing` rows are skipped, or updated (only cells with a value) when exactly one record matches. The token can be used once.

Web: `community.members.import.index | template | preview | store` (`/community/members/import`). API: see the [CHANGELOG](../../CHANGELOG.md). Both need `ensure.admin` (super-admin); organisation admins come with the tenant policy.
