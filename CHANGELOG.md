# Changelog

All notable changes to `iquesters/community` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added (step 2: registration and approval)
- **Apply** (`/community/apply`, any signed-in user): the directory fields (same rules as bulk upload) plus proof: identifiers (e.g. registration number) and files. In multiple-organisation mode the person picks the organisation first. The page then shows the application's status, the administrator's note, and the form again when it is on hold or was rejected.
- **Approval queue** (Community → Applications, `ensure.admin`): tabs Waiting / Applied / On hold / Approved / Rejected / All; each application with its data, proof and **matching records** (by the match keys), and:
  - **Approve**, linking the account to a matching record (the school's data stays as it is), or creating a record from the application (source `registration`);
  - **Hold** with a note: the applicant updates the application and sends it again;
  - **Reject** with a note; the person can apply again with a new application.
  - A record already linked to another account can't be chosen.
- **Statuses** `applied`, `on_hold`, `approved`, `rejected`, with allowed moves only; every change is kept (who, when, note). Payment comes after approval, in a later step.
- Tables `memberships` (one per person per organisation), `membership_status_histories`, `application_proofs`; models `Membership`, `MembershipStatusHistory`, `ApplicationProof`; `MembershipService`.
- **Proof files are private:** stored on the `local` disk under `community/proofs/{membership}`, downloaded only by the applicant or a super-admin (`/community/proofs/{uid}`; anyone else gets 404).
- Settings: `self_registration` (default on) and `proof_fields` (registration number, and a PDF/JPG/PNG up to 5 MB, both optional by default).
- Directory validation rules moved to `DirectoryFields::rulesFor()`, shared by bulk upload and applications.

### Added (step 1: directory and bulk upload)
- **Member directory:** `member_records` (one row per person, with or without a login: organisation, optional user, name, email, phone, source, status) and `member_record_metas` for the app's own fields. Models `MemberRecord` and `MemberRecordMeta`.
- **Configurable directory fields:** the fields come from the directory form schema (`community-member-record`). The seeder creates it once from a preset (`alumni`: batch, section, roll number, house; `club`; `association`); after that admins edit it in the form builder. The same fields drive the form, the bulk upload columns, validation and matching.
- **Bulk upload** (Community → Bulk upload; `ensure.admin`):
  - upload a CSV (comma or semicolon, Excel's UTF-8 BOM is fine), columns named by label or id in any order;
  - a preview first: every row checked (required, email, numbers and their range, options, length), duplicates inside the file, and matches with existing records;
  - then import: valid rows are created (source `bulk_upload`); existing ones are skipped, or updated when exactly one record matches; rows with errors are left out;
  - a template download with the directory's columns.
- **Matching** (`MemberMatcher`) by the conf's match keys (`email,phone,batch+name`). Emails are compared lower-case, phones as digits.
- **One or many organisations** (`organisation_mode`): single uses the configured organisation (or the first active one); multiple asks for one per upload.
- **API v1** (`/api/community/v1`, `auth:sanctum` + `ensure.admin`): `GET directory/fields`, `POST member-records/import/preview`, `POST member-records/import/{token}`. Responses use foundation's `ApiResponse` envelope (data in `response_schema.data`).
- **Settings** in the DB conf: `organisation_mode`, `default_organisation_uid`, `member_label`, `record_label`, `directory_form`, `directory_preset`, `match_keys`, `import_max_rows`.
- Sidebar: **Members** (the directory table, with "Add member") and **Bulk upload**.
- **Every record has an organisation.** Foundation's entity API scopes tables with an `organisation_id` column (organisation's new scope resolver), and hides rows without one. So:
  - the directory form starts with an **Organisation** select (`GET /api/community/v1/organisations`: the default organisation in single mode, every active one in multiple mode); `community:seed` adds it to a form seeded earlier;
  - a bulk upload refuses to run when there is no organisation ("Create an organisation first"); the upload page then shows a notice with a **Create organisation** button instead of the form, and the form's select has one option explaining why;
  - single mode: `community:seed` creates a default organisation (named after the app) when none exists, so a new install works straight away;
  - `organisation_id` and `user_id` are never directory fields or upload columns.
- Requires the organisation module's entity scope resolver (organisation `[Unreleased]`).

### Fixed
- "Source is required" when adding a member through the form: `community:seed` marks the columns the database fills (`source`, `status`, audit columns) as not required on the `member_records` entity.

### Known limitations
- Records added through the directory form (entity API) save email and phone as typed (uploads normalise them). Fixed when registration and approval (step 2) add the record service.
- Meta fields (batch…) aren't table columns yet; the members list with directory filters is step 5.
- Excel files (`.xlsx`) aren't read yet: save as "CSV UTF-8".
