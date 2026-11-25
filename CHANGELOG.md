# Changelog

All notable changes to `iquesters/community` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed (test round 2)
- **School levels and Edit school records saved to a wrong address** (`PUT /community/school-levels`): the pages now give their forms the record's uid. Both open **read-only** with **Edit**.
- **Members edit their own details:** Membership → **Edit my details** (the directory fields and what they completed at the school, with their school's levels; form `community-member-details-{organisation uid}`). Saved straight away and noted in the membership history. A member without school records (e.g. an org-admin made a member automatically) sees **Complete your details**.
- **Members → Member records**, with a one-line description on Member records, Directory and Applications.
- **Directory** opens its table directly (highlighted in the sidebar); someone who isn't a member yet gets an empty list instead of an error.
- **Bulk upload preview:** **Upload another file** (was "Start again") sits beside **Import** in the form's button row.

### Added (school records: Class 10 and Class 12 batches)
- **What a member completed at the school** is a list of **school records** (new table `member_school_records`: member record, level, year, class left, stream), not one "batch". A person can have an ICSE (Class 10) **and** an ISC (Class 12) batch at the same school, one at each of two schools (a membership in each), or have **left earlier** ("Left before finishing": the class they left after, and the year).
- **Levels per school** (Community → **School levels**, new sidebar item for admins): short code, name, class and first year, e.g. ICSE (Class 10) and ISC (Class 12) from 2005. Default levels in the new setting `school_levels`. A level members already have records of can't be removed.
- **Checks:** a year from 1900 to next year; not before the level's first year (no ISC before 2005); the higher class after the lower one (ISC after ICSE); "left" needs the class (1–12); each level once.
- **Application form:** "What you completed at this school" (a list: Completed, Year, Class left after, Stream), at least one row. Each organisation has its own copy of the form with its levels (`community-membership-application-{organisation uid}`, rebuilt by `community:seed` and when the levels are saved).
- **Bulk upload:** new columns **Level** (code or name; empty: the first level), **Year**, **Class left**, **Stream** (in the template). An older **Batch** column is read as the first level's year. The preview has a **School** column.
- **Matching** key `school` (default match keys `email,phone,school+name`; `batch` in saved settings means `school`): the same name and a school record of the same level and year, or of the other exam level within 3 years. So the **ICSE register and the ISC register merge into one record** (choose "Update their record" on import).
- **Directory:** a new **At school** column ("ICSE 2003 · ISC 2005 (Science)", "Left after Class 8, 1998"); search by a year; filter `?level=isc&year=2010`. `directory_public_fields` defaults to `section`.
- **Members:** the record shows **At school** with **Edit school records** (form `community-member-school-records-{organisation uid}`). Approving an application creates the record's school records, or adds the levels a linked record doesn't have yet.
- **Applications:** an **At school** column instead of Batch; match suggestions show the school years.
- **Existing data is kept:** the migration copies each record's `batch` into an ICSE school record and removes Batch from the directory form (the batch values stay in the metas).

### Changed (test round 1)
- **Default organisation name:** single mode's seeder creates it as the new setting `default_organisation_name` (default `iq-test-org`), not after `APP_NAME`.
- **Organisation admins come from the organisation module only** (per-organisation org-admin role). The fallback "global `admin` role + member of it" is gone. Approving a membership adds the person as a **member** of that organisation, never as its admin, and keeps a role they already have there.
- **The application asks to agree to the organisation's Terms and Privacy policy** (required checkbox; the page links that organisation's pages, else the platform's). The version agreed to and the time are kept with the application (`application_data._terms`).

### Fixed
- **Membership and Apply pages use the full content width** like every other page, instead of a narrow centred column with an empty gap beside the sidebar.

### Changed (admins and members)
- **Organisation admins are members of their organisations** without applying: an approved membership (history note "Organisation admin"), a member record from their account (source `admin`) and the `member` role. Created when they open community (dashboard card, Membership, Directory) and by **`community:sync-admins`** for existing admins. They pay membership fees like other members.
- **Super-admins aren't members:** no Membership card on the dashboard, no Membership / Directory items (the seeder takes `use-community` and `view-member-directory` from the super-admin role), and `/community/membership` opens Community's dashboard. They still manage every organisation's members.

### Changed (every form is a form schema)
- **Bulk upload** uses the `community-member-import` form schema (organisation, CSV file) → JSON → the preview has its own address (`/community/members/import/{token}`) with the `community-member-import-confirm` form schema; a plain form post still works.
- **Membership's organisation choice** (multiple organisations) uses `community-organisation-choice`.
- **Directory** is a generic table (`community-directory-table`, rows from `/community/directory/data`): approved members of the viewer's organisation, name and public fields; the table's search matches the name or a public field (e.g. the batch). `/community/directory` opens it (non-members go to Membership).
- Application and Proof sections in the application form.

### Changed (member pages)
- **Membership, for a member,** shows only their membership: the organisation (with a link to its profile), member since, and the details on record. The directory left this page.
- **Directory** (`/community/directory`, new sidebar item, permission `view-member-directory` for the `member` role and admins): approved members of their organisation as cards, searchable by name and filtered by a public field. Non-members are sent to Membership.

### Changed (Applications on the generic table)
- **Community → Applications is the generic table** (`community-application-table`, table and split modes, search, sort, paging, list URLs), with tabs Waiting / On hold / Approved / Rejected / All (`?status=`).
- Rows come from **`GET /community/applications/data`** (user-interface's table `data_url`), limited to the organisations the admin manages (and to the one worked in, if chosen); a record from `/community/applications/data/{uid}`.
- **The record shows as sections:** the application (application form, view mode), the proof files (open in the browser), the decision form while the application is open, and the history. Saving a decision reloads the record in place and refreshes the list.
- The decision answers JSON without a redirect; the form engine shows the toast.
- `/community/applications` and `/community/applications/{uid}` redirect to the table (and the open record). The old pages are gone.
- Needs user-interface's `data_url` and record sections.

### Changed (application review page)
- **The application** shows as the application form in view mode (form schema), like other records.
- **The decision is a form schema** (`community-application-decision`, rebuilt by `community:seed`): "Matching records" (radio; options from `GET /community/applications/{uid}/matches`, one clear match preselected), Approve / Hold / Reject, and the note. Errors show under the fields (e.g. a missing note for hold or reject); the outcome is a toast after the redirect.
- **Proof files open in the browser** (images as thumbnails; PDFs in a new tab); `?download=1` downloads.
- Needs user-interface's `{uid}` support in `optionsFrom.url`.

### Changed (one Membership page, application form on a form schema)
- **One page:** `/community/membership` is where people apply, follow their application and, once approved, find their member home. `/community/apply` redirects there.
- **The application form is a form schema** (`community-membership-application`), drawn by user-interface's form engine like the rest of the app: the directory fields, then the proof fields (`proof__{id}`; files upload as multipart). `community:seed` rebuilds it from the directory form and `proof_fields`, so change those, not this form.
- It posts to `POST /community/membership`: JSON for the form engine (errors under each field, `redirect_url` on success), a redirect for a plain form post.

### Added (roles and access)
- **Organisation context** (organisation module's header switcher): the apply page, the approval queue and the bulk upload default to the organisation the user works in; the queue then lists only that organisation's applications.
- **Who sees what:** the Community module is shown to `super-admin`, `admin`, `user` and `member`.
  - **Membership** (`/community/membership`, permission `use-community`): apply and follow the application; once approved, the **member's home**: their membership and the **member directory** (approved members of their organisation; name plus `directory_public_fields`, default batch and section; searchable; email and phone stay private).
  - **Members, Applications, Bulk upload** (permission `manage-community`): super-admins and **organisation admins**, each limited to the organisations they manage: queue, decisions, matching, uploads and proof downloads. Another organisation's application answers 404.
- **Organisation admins** (`CommunityAccess`): Track B's `TenantAuthorization` (explicit `organisation_role_assignments`) when that table exists; until then, a user with the `admin` role manages the organisations they belong to (logged once as a warning).
- Admin pages use the new `ensure.community.manager` middleware instead of `ensure.admin` (super-admin only since Track B).
- **Community's Dashboard:** admins see counts (waiting applications, members) and their pages; everyone sees their membership card.
- Settings: `directory_public_fields`.
- **Approval adds the user to the organisation** (`model_has_organisations`), so members can open its profile (organisation module).
- Organisation admins are decided by the organisation module's `OrganisationAccess` when it is installed, so both modules agree; super-admins always reach the admin pages, even before any organisation exists.

### Added (step 2: registration and approval)
- **Apply** (`/community/apply`, any signed-in user): the directory fields (same rules as bulk upload) plus proof: identifiers (e.g. registration number) and files. In multiple-organisation mode the person picks the organisation first. The page then shows the application's status, the administrator's note, and the form again when it is on hold or was rejected.
- **Approval queue** (Community → Applications, `ensure.admin`): navigation tabs Waiting (applied and on hold) / On hold / Approved / Rejected / All (managed in Admin → Navigation); each application with its data, proof and **matching records** (by the match keys), and:
  - **Approve**, linking the account to a matching record (the school's data stays as it is), or creating a record from the application (source `registration`);
  - **Hold** with a note: the applicant updates the application and sends it again;
  - **Reject** with a note; the person can apply again with a new application.
  - A record already linked to another account can't be chosen.
- **Statuses** `applied`, `on_hold`, `approved`, `rejected`, with allowed moves only; every change is kept (who, when, note). Payment comes after approval, in a later step.
- Tables `memberships` (one per person per organisation), `membership_status_histories`, `application_proofs`; models `Membership`, `MembershipStatusHistory`, `ApplicationProof`; `MembershipService`.
- **Proof files are private:** stored on the `local` disk under `community/proofs/{membership}`, downloaded only by the applicant or a super-admin (`/community/proofs/{uid}`; anyone else gets 404).
- **Approval makes the user a member:** the role in `member_role` (default `member`, created by `community:seed`) is added; the sign-up role (e.g. `user`) stays.
- **"Apply for membership" on the dashboard:** a Membership card (registered as a `dashboard-cards` service; user-management's dashboard shows them) while the community module is on: Apply, or where the application is (waiting, update it, see why), or "You're a member". In single-organisation mode it disappears from Apply once there is a membership, until a rejection.
- Settings: `self_registration` (default on), `member_role` (default `member`) and `proof_fields` (registration number, and a PDF/JPG/PNG up to 5 MB, both optional by default).
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
