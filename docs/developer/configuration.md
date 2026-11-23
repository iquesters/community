# Settings

Community's settings live in the DB conf (`CommunityConf`, identifier `iquesters/community`), like the other modules. Read them with:

```php
use Iquesters\Community\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;

ConfProvider::from(Module::COMMUNITY)->organisation_mode;
```

| Setting | Default | Meaning |
|---|---|---|
| `organisation_mode` | `single` | `single`: every record uses one organisation. `multiple`: the admin picks one per upload (and later per record) |
| `default_organisation_uid` | empty | Single mode: the organisation's uid. Empty: the first active organisation |
| `member_label` | `Member` | Wording in the UI (e.g. "Alumnus") |
| `record_label` | `Member record` | Wording in the UI (e.g. "Alumni record") |
| `directory_form` | `community-member-record` | Form schema whose fields are the directory |
| `directory_preset` | `alumni` | `alumni`, `club` or `association`. Used only when the seeder creates the directory form |
| `match_keys` | `email,phone,batch+name` | How records are matched: comma-separated keys; `+` joins fields that must all be equal. A key naming a field the directory doesn't have is ignored |
| `import_max_rows` | `5000` | Most rows in one bulk upload (1–50000) |

On a live install, add a changed key as one meta row; `community:seed` doesn't overwrite stored settings.
