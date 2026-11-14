# Daylist backend — schema, endpoints, folder layout

Laravel 13 (PHP 8.3) REST JSON API on MySQL 8, run locally with Docker Compose on macOS.
Status (14 Nov 2026): milestones M1–M9 are built; 78 tests pass on MySQL 8.
Source of truth for features: the clone analysis doc (58 use cases, data model) and the Figma file.

## Key decisions

| Topic | Decision | Why |
| --- | --- | --- |
| Framework | Laravel, Sanctum personal access tokens (Bearer) | Mobile clients, no cookies |
| IDs | UUID v4 on every synced entity; clients may send their own `id` on create | The app creates rows offline and replays them later; repeating a create with the same `id` returns the existing row (idempotent) |
| Deletes | `deleted_at` (soft) on groups, lists, memberships, tasks, steps, attachments. Deleting a list or task soft-deletes its children in the same transaction | Every delete reaches other devices as a tombstone through `/sync` |
| Purge | `daylist:purge-deleted` (daily) hard-deletes tombstones older than 30 days and their files | Keeps tables small. A client whose cursor is older than 30 days gets `full_resync: true` |
| Timestamps | `DATETIME(6)` in UTC, ISO-8601 with microseconds in JSON | Sync cursor needs sub-second precision |
| Conflicts | Last write wins per request, in server arrival order | Matches the analysis doc (UC-49) |
| Smart lists | Queries over `tasks`, never stored | One task can sit in several smart lists |
| "Today" | Client sends `today=YYYY-MM-DD` (its local date); fallback is the user's `timezone` | My Day resets at the user's local midnight, not the server's |
| Recurrence | RFC 5545 RRULE string, validated with `rlanvin/php-rrule` | Covers every repeat-picker option; maps to EventKit |
| Next occurrence | Created by the server when a recurring task goes from open to completed; stored in `next_task_id` so it is created once | Two offline devices completing the same task cannot spawn two copies |
| List placement | Group and sort order of a list live on the user's `list_members` row (the owner has one too) | Groups are per user (analysis doc), so a shared list can sit in a different group for each member |
| Files | Private local disk `storage/app/attachments`, downloaded through an authorised endpoint; 25 MB cap | Swappable for S3 later via Laravel's filesystem config |
| Apple / Google sign-in | The app sends the provider's ID token; the server verifies its signature against Apple's / Google's public keys (JWKS), audience and expiry | No client secrets needed for sign-in |

## Database schema (MySQL 8, utf8mb4)

All synced tables have `created_at`, `updated_at` as `DATETIME(6)`; soft-deleted ones also `deleted_at DATETIME(6) NULL`.
Every synced table has an index on `updated_at` for `/sync`.

### users
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | UUID |
| name | VARCHAR(120) | |
| email | VARCHAR(255) NULL, unique | Null for Apple users who hide their email and never share it |
| email_verified_at | DATETIME NULL | |
| password | VARCHAR(255) NULL | Null for Apple/Google-only accounts |
| avatar_url | VARCHAR(500) NULL | |
| timezone | VARCHAR(64) default `UTC` | IANA name, e.g. `Asia/Karachi` |
| settings | JSON | `add_to_top`, `starred_to_top`, `completion_sound`, `confirm_delete`, `link_previews`, `recognise_dates`, `remove_recognised_dates`, `week_start`, `default_reminder_time`, `hidden_smart_lists[]`, `carry_over_my_day` |
| remember_token, created_at, updated_at | | Account deletion is a hard delete (App Store rule) |

### social_accounts
| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT PK | |
| user_id | CHAR(36) FK users, cascade | |
| provider | ENUM('apple','google') | |
| provider_user_id | VARCHAR(255) | Apple `sub` / Google `sub` |
| email | VARCHAR(255) NULL | As given by the provider |
| created_at, updated_at | | unique(`provider`, `provider_user_id`) |

### personal_access_tokens
Laravel Sanctum's table with a UUID morph (`tokenable_id` CHAR(36)). One token per device (`name` = device name).

### password_reset_tokens
Laravel default (email, token, created_at).

### list_groups
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| user_id | CHAR(36) FK users, cascade | Groups are private to one user |
| name | VARCHAR(120) | |
| sort_order | DOUBLE | Fractional ordering: insert between neighbours without renumbering |
| is_collapsed | BOOLEAN default false | |
| created_at, updated_at, deleted_at | | Index (`user_id`, `updated_at`) |

### task_lists
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| owner_id | CHAR(36) FK users, cascade | |
| name | VARCHAR(255) | |
| emoji | VARCHAR(16) NULL | |
| theme_color | VARCHAR(32) NULL | Figma token name, e.g. `violet`, `lagoon` |
| theme_image | VARCHAR(255) NULL | Photo theme key |
| sort_mode | ENUM('manual','importance','due_date','alphabetical','created_at','my_day') default `manual` | |
| sort_direction | ENUM('asc','desc') default `asc` | |
| show_completed | BOOLEAN default true | |
| is_default | BOOLEAN default false | The built-in "Tasks" list; one per user; cannot be deleted, shared or renamed |
| is_shared | BOOLEAN default false | True once a second member joins |
| sharing_limited | BOOLEAN default false | "Limit access": existing invite links stop working for new people |
| created_at, updated_at, deleted_at | | |

### list_members
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| list_id | CHAR(36) FK task_lists, cascade | |
| user_id | CHAR(36) FK users, cascade | |
| role | ENUM('owner','member') | Owner row is created with the list |
| group_id | CHAR(36) NULL FK list_groups, set null | This user's group for the list |
| sort_order | DOUBLE | This user's position for the list on Home |
| notifications_muted | BOOLEAN default false | |
| joined_at | DATETIME(6) | |
| created_at, updated_at, deleted_at | | unique(`list_id`, `user_id`). A removed member gets a tombstone so their devices drop the list |

### share_invites
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| list_id | CHAR(36) FK task_lists, cascade | |
| token | CHAR(43) unique | 43 random letters and digits |
| created_by | CHAR(36) FK users, cascade | |
| expires_at | DATETIME NULL | Null = no expiry |
| revoked_at | DATETIME NULL | Set by "stop sharing" |
| created_at, updated_at | | |

### tasks
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| list_id | CHAR(36) FK task_lists, cascade | |
| created_by | CHAR(36) NULL FK users, set null | |
| title | VARCHAR(500) | |
| notes | TEXT NULL | |
| is_completed | BOOLEAN default false | |
| completed_at | DATETIME(6) NULL | |
| completed_by | CHAR(36) NULL FK users, set null | |
| is_important | BOOLEAN default false | |
| due_date | DATE NULL | Day only, as in the original |
| reminder_at | DATETIME NULL | UTC |
| recurrence | VARCHAR(255) NULL | RRULE without the `RRULE:` prefix, e.g. `FREQ=WEEKLY;BYDAY=MO,WE` |
| next_task_id | CHAR(36) NULL | The occurrence this task spawned when completed |
| my_day_date | DATE NULL | In My Day when equal to the client's today |
| assignee_id | CHAR(36) NULL FK users, set null | Must be a member of the list |
| sort_order | DOUBLE | |
| created_at, updated_at, deleted_at | | Indexes: (`list_id`, `is_completed`), `assignee_id`, `my_day_date`, `due_date`, `updated_at` |

### steps
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| task_id | CHAR(36) FK tasks, cascade | |
| title | VARCHAR(500) | |
| is_completed | BOOLEAN default false | |
| sort_order | DOUBLE | |
| created_at, updated_at, deleted_at | | No dates, no nesting |

### attachments
| Column | Type | Notes |
| --- | --- | --- |
| id | CHAR(36) PK | |
| task_id | CHAR(36) FK tasks, cascade | |
| uploaded_by | CHAR(36) NULL FK users, set null | |
| file_name | VARCHAR(255) | Original name, shown in the app |
| mime_type | VARCHAR(127) | |
| size_bytes | INT UNSIGNED | ≤ 26,214,400 (25 MB) |
| disk, path | VARCHAR(32), VARCHAR(512) | Never returned to clients |
| created_at, updated_at, deleted_at | | |

Relationships: user 1–n groups · user n–m lists through `list_members` · list 1–n tasks · task 1–n steps · task 1–n attachments · list 1–n invites.

### Smart-list queries (all exclude soft-deleted rows and lists the user is not a member of)
| Smart list | Condition |
| --- | --- |
| My Day | `my_day_date = :today` |
| Important | `is_important AND NOT is_completed` |
| Planned | `(due_date IS NOT NULL OR reminder_at IS NOT NULL) AND NOT is_completed`, each task tagged with a bucket: `overdue`, `today`, `tomorrow`, `this_week`, `later` |
| Assigned to me | `assignee_id = :me AND NOT is_completed` |
| All | `NOT is_completed` |
| Completed | `is_completed` |
| My Day suggestions | open tasks with `my_day_date = :yesterday`, open tasks due on or before today and not in My Day, open tasks created in the last 7 days and not in My Day |

## Endpoints (`/api/v1`, JSON, `Authorization: Bearer <token>` unless marked public)

Responses wrap the payload in `{"data": …}`; lists add `meta` when paged. Errors use Laravel's shape: `{"message": "…", "errors": {"field": ["…"]}}` with 401, 403, 404, 409, 413, 422 or 429.

### Health and auth
| Method | Path | Body / query | Returns |
| --- | --- | --- | --- |
| GET | `/health` (public) | | `{status, time}` |
| POST | `/auth/register` (public) | `name, email, password, device_name` | `{token, user}`; creates the default "Tasks" list |
| POST | `/auth/login` (public) | `email, password, device_name` | `{token, user}` |
| POST | `/auth/apple` (public) | `identity_token, name?, device_name` | `{token, user, is_new}`; links to an existing user with the same verified email |
| POST | `/auth/google` (public) | `id_token, device_name` | `{token, user, is_new}` |
| POST | `/auth/forgot-password` (public) | `email` | 202; reset email (logged to `storage/logs` locally) |
| POST | `/auth/reset-password` (public) | `email, token, password` | 200 |
| POST | `/auth/logout` | | 204; revokes this device's token |

### Account
| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/me` | | user with settings |
| PATCH | `/me` | `name?, timezone?, avatar_url?, settings?` (merged) | user |
| DELETE | `/me` | | 204; deletes the user, owned lists (members lose access), memberships, files, tokens |

### Groups
| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/groups` | | groups |
| POST | `/groups` | `id?, name, sort_order?` | group (201, or 200 if `id` already exists) |
| PATCH | `/groups/{id}` | `name?, sort_order?, is_collapsed?` | group |
| DELETE | `/groups/{id}` | | 204; lists in it move to ungrouped |

### Lists
| Method | Path | Body / query | Returns |
| --- | --- | --- | --- |
| GET | `/lists` | | lists the user owns or belongs to, with `group_id`, `sort_order`, `role`, `member_count`, `open_task_count` |
| POST | `/lists` | `id?, name, emoji?, theme_color?, theme_image?, group_id?, sort_order?` | list |
| GET | `/lists/{id}` | | list + members |
| PATCH | `/lists/{id}` | `name?, emoji?, theme_color?, theme_image?, sort_mode?, sort_direction?, show_completed?` (owner/member); `group_id?, sort_order?` (own placement) | list |
| DELETE | `/lists/{id}` | | 204; owner only; soft-deletes tasks, steps, attachments, memberships |
| POST | `/lists/{id}/duplicate` | `name?` | new list with copies of open tasks and their steps |

### Tasks
| Method | Path | Body / query | Returns |
| --- | --- | --- | --- |
| GET | `/lists/{id}/tasks` | `include_completed=1` | tasks in the list's sort mode, with steps count |
| POST | `/lists/{id}/tasks` | `id?, title, notes?, is_important?, due_date?, reminder_at?, recurrence?, my_day_date?, assignee_id?, sort_order?` | task (201). A recurring task with no due date gets today |
| POST | `/tasks` | same + `list_id?` | Creates in the given list or the default "Tasks" list (quick add from a smart list) |
| GET | `/tasks/{id}` | | task + steps + attachments |
| PATCH | `/tasks/{id}` | any task field, `list_id` to move, `is_completed` | task; completing a recurring task returns `next_task` too |
| DELETE | `/tasks/{id}` | | 204 |

### Smart lists and search
| Method | Path | Query | Returns |
| --- | --- | --- | --- |
| GET | `/smart-lists/{name}/tasks` | `today=YYYY-MM-DD`; `name` = `my-day`, `important`, `planned`, `assigned`, `all`, `completed` | tasks (Planned adds `bucket`) |
| GET | `/smart-lists/counts` | `today` | `{my_day, important, planned, assigned, all, completed}` counts for the Home tiles |
| GET | `/my-day/suggestions` | `today` | `{yesterday, due, recent}` task arrays |
| GET | `/search` | `q` (min 1 char), `include_completed=0/1` | tasks grouped by list, matching title, notes or a step title |

### Steps
| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/tasks/{id}/steps` | | steps |
| POST | `/tasks/{id}/steps` | `id?, title, is_completed?, sort_order?` | step |
| PATCH | `/steps/{id}` | `title?, is_completed?, sort_order?` | step |
| DELETE | `/steps/{id}` | | 204 |
| POST | `/steps/{id}/promote` | | new task in the same list; the step is deleted |

### Attachments
| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| GET | `/tasks/{id}/attachments` | | attachments (`id, file_name, mime_type, size_bytes, download_url`) |
| POST | `/tasks/{id}/attachments` | multipart `file` (≤ 25 MB), `id?` | attachment (201); 413/422 over the limit |
| GET | `/attachments/{id}/download` | | the file stream with `Content-Disposition` |
| DELETE | `/attachments/{id}` | | 204 |

### Sharing and members
| Method | Path | Body | Returns |
| --- | --- | --- | --- |
| POST | `/lists/{id}/invites` | `expires_in_days?` | `{token, url}` (url = `DAYLIST_INVITE_URL/{token}`, for the universal link) |
| GET | `/invites/{token}` | | preview: list name, emoji, theme, owner name, member count, `already_member` |
| POST | `/invites/{token}/accept` | | list (joins as member); 410 if revoked/expired, 403 if access is limited |
| PATCH | `/lists/{id}/sharing` | `limited: bool` | list; owner only |
| DELETE | `/lists/{id}/sharing` | | 204; owner only; revokes links, removes all members, clears their assignments |
| GET | `/lists/{id}/members` | | members with name, avatar, role |
| DELETE | `/lists/{id}/members/{userId}` | | 204; owner removes anyone, a member removes only themself (leave) |
| PATCH | `/lists/{id}/members/me` | `notifications_muted` | membership |

Assigning a task = `PATCH /tasks/{id}` with `assignee_id` (must be a current member).

### Sync
| Method | Path | Query | Returns |
| --- | --- | --- | --- |
| GET | `/sync` | `since` (cursor from the last call; omit for a full sync) | `{cursor, full_resync, groups, lists, memberships, tasks, steps, attachments, users}` |

Each array holds every row the user can see that changed after `since`, including tombstones (`deleted_at` set). `memberships` includes the user's own removed memberships so the app can drop lists it lost access to. The server reads with a 5-second overlap before `since`, so a row committed during the previous call is never missed; the app upserts by `id`, so repeats are harmless. Offline writes are replayed through the normal endpoints with client-generated ids.

## Project folder layout

```
daylist-backend/
├── app/
│   ├── Console/Commands/PurgeDeletedRecords.php   daylist:purge-deleted, scheduled daily 03:15
│   ├── Http/
│   │   ├── Controllers/Api/V1/   Auth, Me, Group, TaskList, Task, Step, Attachment,
│   │   │                         SmartList, Search, Sharing (invites + members), Sync, Health
│   │   ├── Middleware/ForceJsonResponse.php
│   │   └── Resources/            User, Group, TaskList, Member, Task, Step, Attachment
│   ├── Models/                   User, SocialAccount, ListGroup, TaskList, ListMember,
│   │                             ShareInvite, Task, Step, Attachment
│   ├── Models/Concerns/SyncableModel.php   UUID keys (client ids kept), microsecond timestamps
│   ├── Providers/AppServiceProvider.php    rate limits, route bindings, reset-link URL
│   ├── Services/
│   │   ├── Access.php            lists a user can see, the user's "today"
│   │   ├── Auth/                 IdTokenVerifier (Apple/Google JWKS), SocialLogin
│   │   ├── ListService.php       create with owner row, default list, duplicate, cascade soft delete
│   │   ├── TaskService.php       create, update (move, complete, assign), cascade soft delete
│   │   ├── RecurrenceService.php validate RRULE, next date, spawn next task once
│   │   ├── SmartListService.php  smart-list queries, Planned buckets, counts, suggestions
│   │   └── SyncService.php
│   └── Support/Iso.php           ISO-8601 microsecond formatting
├── config/daylist.php            upload limit, purge days, invite URL, Apple/Google client ids, default settings
├── database/
│   ├── factories/UserFactory.php
│   ├── migrations/               users, tokens, and all Daylist tables
│   └── seeders/DemoSeeder.php    demo@daylist.test and sam@daylist.test with shared Groceries
├── docker/
│   ├── php/Dockerfile            php:8.3-fpm + pdo_mysql, intl, zip, opcache, composer
│   ├── php/uploads.ini           upload_max_filesize 26M, post_max_size 30M
│   ├── nginx/default.conf        client_max_body_size 30m
│   └── mysql/init.sql            creates the daylist_test database
├── docs/
│   ├── BACKEND_SPEC.md           this file
│   └── daylist.postman_collection.json   all 49 routes, saves token and sync cursor
├── routes/api.php                every route under /api/v1
├── tests/
│   ├── Feature/                  Auth, Account, ListAndGroup, Task (incl. steps), SmartList (incl. search),
│   │                             Sharing, Attachment, Sync (incl. purge)
│   └── Unit/RecurrenceServiceTest.php   every repeat preset, month-end, leap year, UNTIL
├── .github/workflows/tests.yml   CI on MySQL 8.4
├── docker-compose.yml            app, nginx (http://localhost:8080), scheduler, mysql (3307 on the Mac)
├── Makefile                      make setup / up / test / migrate / fresh / logs / shell
└── README.md                     Mac setup and how the app should call the API
```

Validation lives in each controller method (`$request->validate`), next to the endpoint it guards.

## Build milestones

| # | Milestone | Done when (all done) |
| --- | --- | --- |
| M1 | Scaffold, Docker, migrations, `/health` | `make up` serves `/api/v1/health`; migrations run on MySQL |
| M2 | Email, Apple and Google auth, `/me`, account deletion | Feature tests for each provider with signed test tokens |
| M3 | Groups and lists (default list, placement, duplicate, cascade delete) | Feature tests, policies enforced |
| M4 | Tasks and steps, recurrence | Tests for every repeat preset and the single-spawn rule |
| M5 | Smart lists, counts, suggestions, search | Tests for each smart list and Planned buckets across a month boundary |
| M6 | Sharing: invites, join, limit, stop sharing, members, assignment | Tests for owner/member permissions |
| M7 | Attachments up to 25 MB | Tests for upload, download auth, 25 MB limit |
| M8 | Sync with tombstones, purge command | Tests for cursor overlap, lost membership, full resync |
| M9 | Postman collection, demo seeder, README, GitHub Actions CI | CI green on push |

## Open points
- My Day lives on the task (per the analysis doc), so on a shared list a task added to My Day shows in every member's My Day. Moving it to a per-user table is a small change if you want it per person.
- Push notifications for shared-list activity (UC-44) need APNs keys and are left for the iOS phase. Until then, other members' changes arrive through `/sync`.
- When a user deletes their account, their memberships in other people's lists are removed with it (no tombstone). Those lists get a new `updated_at`, so members' apps refresh the member list on the next sync.
