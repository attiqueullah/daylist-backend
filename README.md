# Daylist backend

REST JSON API for **Daylist**, the iOS to-do app (a Microsoft To Do–style clone). It handles lists, groups, tasks, steps, repeating tasks, My Day and the other smart lists, shared lists with invite links, 25 MB attachments and offline sync.

Laravel 13 · PHP 8.3 · MySQL 8 · Sanctum tokens · Docker Compose.

The full design (schema, every endpoint, decisions) is in [docs/BACKEND_SPEC.md](docs/BACKEND_SPEC.md). A Postman collection is in [docs/daylist.postman_collection.json](docs/daylist.postman_collection.json).

## Run it on a Mac

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/) running.

```sh
git clone https://github.com/attiqueullah/daylist-backend.git
cd daylist-backend
make setup      # builds containers, installs packages, creates .env, migrates
make fresh      # optional: reset the database and load demo data
```

The API is now at **http://localhost:8080/api/v1** (check `http://localhost:8080/api/v1/health`).
MySQL is on `localhost:3307` (user `daylist`, password `secret`) for TablePlus or Sequel Ace.

Demo accounts after `make fresh`: `demo@daylist.test` / `password123` and `sam@daylist.test` / `password123` (they share the Groceries list).

| Command | What it does |
| --- | --- |
| `make up` / `make down` | Start or stop the containers |
| `make test` | Run the test suite (uses the separate `daylist_test` database) |
| `make migrate` | Run new migrations |
| `make fresh` | Drop everything, migrate, load demo data |
| `make logs` | Follow app and nginx logs |
| `make shell` | Shell inside the PHP container (`php artisan …`) |

### Calling it from the iOS simulator
The simulator shares your Mac's network, so `http://localhost:8080/api/v1` works. On a real iPhone use your Mac's LAN address (e.g. `http://192.168.1.20:8080/api/v1`) and allow HTTP for that host in `Info.plist` while developing.

## Configuration (.env)

| Key | Purpose |
| --- | --- |
| `APPLE_CLIENT_IDS` | The iOS bundle id. Sign in with Apple tokens must be issued for it |
| `GOOGLE_CLIENT_IDS` | The iOS OAuth client id from Google Cloud Console |
| `DAYLIST_INVITE_URL` | Base of share links (`…/invite/{token}`), handled as a universal link |
| `DAYLIST_PASSWORD_RESET_URL` | Where the reset email points. Locally, emails go to `storage/logs/laravel.log` |
| `DAYLIST_MAX_UPLOAD_KB` | Attachment limit, default 25600 (25 MB) |
| `DAYLIST_PURGE_AFTER_DAYS` | How long deleted rows are kept for sync, default 30 |

## How the app should use it

1. **Sign in.** `POST /auth/apple`, `/auth/google`, `/auth/login` or `/auth/register` with a `device_name`. Keep the returned `token` in the Keychain and send it as `Authorization: Bearer <token>`.
2. **Send "today".** Smart-list calls take `?today=YYYY-MM-DD` in the phone's local date, so My Day resets at the user's midnight.
3. **Work offline.** Generate a UUID for every new group, list, task, step or attachment and send it as `id`. Writes made offline can be replayed safely: repeating a create with the same `id` returns the existing row.
4. **Sync.** Call `GET /sync` (first time without `since`), store `cursor`, and next time call `GET /sync?since=<cursor>`. Upsert every row by `id`; a row with `deleted_at` set means delete it locally. If `full_resync` is `true`, clear local data and start over.
5. **Repeating tasks.** Completing a task with a `recurrence` returns `next_task`. Don't create the next occurrence on the device.

## Tests

78 tests (feature tests for every endpoint group, plus unit tests for the repeat rules) run against MySQL:

```sh
make test
```

GitHub Actions runs the same suite on every push to `main`.

## Project layout

```
app/Http/Controllers/Api/V1   one controller per area (Auth, Me, Group, TaskList, Task, Step, Attachment, SmartList, Search, Sharing, Sync, Health)
app/Http/Resources            JSON shapes sent to the app
app/Models                    Eloquent models (UUID keys, soft deletes, microsecond timestamps)
app/Services                  ListService, TaskService, RecurrenceService, SmartListService, SyncService, Access, Auth/*
app/Console/Commands          daylist:purge-deleted (runs daily from the scheduler container)
database/migrations           schema
database/seeders/DemoSeeder   demo users and lists
docker/                       PHP, nginx and MySQL config
tests/Feature, tests/Unit     test suite
```
