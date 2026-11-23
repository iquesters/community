# iquesters/community

Members for Laravel apps built on the iquesters modules: a configurable member directory (with or without logins), bulk upload, and — in the next steps — registration with approval, membership plans and periods, and tags.

First used by an alumni association; the directory's fields (batch, section, roll number…) are configured per app, so clubs and associations can use it too.

## Status

| Step | What | State |
|---|---|---|
| 1 | Settings, directory fields, member records, bulk upload with preview | **This release** |
| 2 | Registration, approval queue, matching, status history | Next |
| 3 | Plans and periods (prices from `iquesters/product`), mark paid / waived | Planned |
| 4 | Tags, tag rules, bulk tagging | Planned |
| 5 | Members list with all filters and bulk actions | Planned |

## Install

```bash
composer require iquesters/community
php artisan migrate
php artisan community:seed
```

Requires `iquesters/foundation`, `iquesters/user-interface` and `iquesters/organisation` (2.x).

## Docs

- [Member directory and bulk upload](docs/developer/member-directory.md) (developers)
- [Settings](docs/developer/configuration.md)
- [Bulk upload](docs/user/bulk-upload.md) (admins)
- [CHANGELOG](CHANGELOG.md)
