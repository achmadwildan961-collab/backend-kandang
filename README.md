# Smart Kandang Ayam API

Laravel 13 REST API with PHP 8.3+, MySQL 8.4, Composer 2, and Sanctum mobile bearer authentication. Flutter REST integration is complete. Phase 3 adds MQTT ingestion and local simulation; see [PHASE3_MQTT_INTEGRATION.md](PHASE3_MQTT_INTEGRATION.md) for commands, tests and the three-VM deployment handoff. ESP32 firmware, fan/lamp automation, payments and subscriptions remain deferred.

## Run the existing installation

The project and databases already exist. Preserve `.env`, `.env.testing`, and the application key. Development login credentials are stored privately in `.env` under `DEV_ADMIN_*` and `DEV_USER_*`.

Start MySQL through Laragon if stopped. Alternatively, run this PowerShell command only when MySQL is not already running:

```powershell
Start-Process -FilePath 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini','--bind-address=127.0.0.1','--mysqlx-bind-address=127.0.0.1' -WindowStyle Hidden
```

If login returns HTTP 500 with `SQLSTATE[HY000] [2002]` in `storage/logs/laravel.log`, check that MySQL is listening on `127.0.0.1:3306` (`Get-NetTCPConnection -LocalPort 3306 -State Listen`). A running Laravel server, successful CORS preflight, validation response, or `/up` response does not establish a working database connection. Start the existing MySQL instance above; do not reset the database or run migrations to fix a stopped server. Use the private `DEV_ADMIN_*` / `DEV_USER_*` credentials from `.env`; an incorrect password or email returns HTTP 422.

Start the API:

```powershell
Set-Location D:\FlutterProjects\smart-kandang-backend
$env:Path = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;' + $env:Path
php artisan config:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Base URL: `http://127.0.0.1:8000/api/v1`. Health check: `GET /up`. Stop with Ctrl+C. The HTTP API runs independently of MQTT. Once a broker is configured, run `php artisan mqtt:listen` separately and `php artisan schedule:work` for device timeout checks. Local simulation needs no broker.

For local Flutter Web, keep this exact allowlist in the existing backend `.env`:

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:58111,http://127.0.0.1:58111
```

After changing it, run `php artisan config:clear` and restart any running API server. Laravel's global `HandleCors` middleware handles OPTIONS for `api/*`, including `/api/v1/*`. Sanctum uses bearer tokens; cross-origin cookies remain disabled. Production must set its own explicit HTTPS frontend origins; the shared CORS config has no localhost or wildcard fallback.

In a second terminal:

```powershell
Set-Location D:\FlutterProjects\project_kandangayam
flutter run -d chrome --web-hostname=localhost --web-port=58111 --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
```

The define is `API_BASE_URL` and includes `/api/v1`. Keep the fixed web port; a randomly selected port will not match the allowlist. Refresh the browser after restarting the backend. CORS regression tests run with `php artisan test --filter=CorsTest` using the default in-memory SQLite configuration.

## Setup on another machine

1. Install PHP 8.3+ and Composer. Extensions include PDO MySQL, mbstring, OpenSSL, XML/DOM, ctype, fileinfo, and tokenizer; SQLite tests additionally require PDO SQLite. Run `composer install` and `composer check-platform-reqs`.
2. Create separate MySQL databases `smart_kandang` and `smart_kandang_test`, with `utf8mb4` / `utf8mb4_unicode_ci`. Create a private local application account with privileges scoped to these two databases.
3. Copy `.env.example` to `.env` only if absent. Set `DB_*` and all four `DEV_ADMIN_*` / `DEV_USER_*` fields. Seed emails must differ and passwords must be at least 12 characters. Run `php artisan key:generate` only for a new installation.
4. Run `php artisan migrate` and `php artisan db:seed`. Never use `migrate:fresh`, `migrate:refresh`, or database deletion against development data.
5. Copy `.env` to `.env.testing` only if absent. Set `APP_ENV=testing`, `DB_DATABASE=smart_kandang_test`, `CACHE_STORE=array`, and `BCRYPT_ROUNDS=4`. Both environment files are ignored by Git.

Defaults: `SENSOR_STALE_AFTER_SECONDS=300`, `SANCTUM_EXPIRATION=43200` minutes (30 days), and comma-separated `CORS_ALLOWED_ORIGINS`. Set explicit browser origins for Flutter web. Bearer authentication uses no cookies/CSRF endpoint. Application timestamps and MySQL sessions use UTC. The environment template contains no credentials.

## Endpoints

Paths are relative to `/api/v1`. Only login is public. Other endpoints require an active account and `Authorization: Bearer <token>`.

| Method | Path | Purpose / access |
|---|---|---|
| POST | `/auth/login` | Public, rate limited |
| POST | `/auth/logout` | Revoke current token |
| GET | `/auth/me` | Current user |
| GET, PUT | `/profile` | Own profile |
| PUT | `/profile/password` | Change password; revoke all own tokens |
| GET, POST | `/users` | Admin lists/creates regular users |
| GET, PUT, PATCH | `/users/{user}` | Admin reads/edits regular users |
| PATCH | `/users/{user}/status` | Admin activates/deactivates |
| GET, POST | `/coops` | List accessible coops; admin creates |
| GET, PUT | `/coops/{coop}` | Read accessible coop; admin edits |
| PUT | `/coops/{coop}/users` | Admin replaces user assignments |
| GET, POST | `/coops/{coop}/devices` | Read accessible devices; admin creates |
| GET, PUT | `/devices/{device}` | Read accessible device; admin edits |
| GET | `/coops/{coop}/sensors/latest` | Sensor snapshot |
| GET | `/coops/{coop}/sensors/indoor` | Five sensors and aggregation |
| GET | `/coops/{coop}/sensors/history` | Paginated individual readings |
| GET | `/coops/{coop}/sensors/chart` | Bounded chart aggregation and weighted summaries |
| GET, PUT | `/coops/{coop}/settings` | Read/update accessible settings |
| GET | `/notifications` | Own notifications for accessible coops |
| PATCH | `/notifications/read-all` | Mark accessible own notifications read |
| PATCH | `/notifications/{notification}/read` | Mark one own notification read |
| GET | `/coops/{coop}/events` | Paginated event history |

Regular users access only assigned coops; admins access all coops. No public registration, role promotion, deletion, ingestion, or actuator endpoints exist. User-management endpoints cannot modify administrator accounts. Deactivation/password changes revoke tokens; expiry is enforced. Logout revokes only the current token.

Responses use `data`; paginated collections also include `links` and `meta`. Errors include `message` and `errors`. Status codes: 200 reads/updates, 201 creations, 401 missing/expired/revoked tokens, 403 forbidden/inactive, 404 missing resources or another user's notification, 422 validation/invalid credentials, 429 login throttling. Password hashes and internal alert state are not returned.

## API examples

PowerShell login (enter the private credentials from `.env`):

```powershell
$base = 'http://127.0.0.1:8000/api/v1'
$loginEmail = Read-Host 'Email'
$loginPassword = Read-Host 'Password' -AsSecureString
$credential = [pscredential]::new($loginEmail, $loginPassword)
$login = Invoke-RestMethod -Method Post -Uri "$base/auth/login" -ContentType 'application/json' -Body (@{
    email = $loginEmail
    password = $credential.GetNetworkCredential().Password
    device_name = 'Local API verification'
} | ConvertTo-Json)
$headers = @{ Authorization = "Bearer $($login.data.token)"; Accept = 'application/json' }
$coops = Invoke-RestMethod "$base/coops" -Headers $headers
$coopId = $coops.data[0].id
Invoke-RestMethod "$base/coops/$coopId/sensors/latest" -Headers $headers
Invoke-RestMethod "$base/coops/$coopId/sensors/indoor" -Headers $headers
Invoke-RestMethod "$base/coops/$coopId/sensors/history?sensor_identifier=indoor_dht22_1&per_page=50" -Headers $headers
Invoke-RestMethod "$base/notifications?filter=unread" -Headers $headers
Invoke-RestMethod -Method Post "$base/auth/logout" -Headers $headers
```

Example body for `PUT /coops/{coop}/settings`:

```json
{
  "minimum_outdoor_temperature": 25,
  "maximum_indoor_temperature": 30,
  "gas_threshold": 1500,
  "notification_preferences": {
    "push_notifications": true,
    "email_notifications": false,
    "critical_alerts": true,
    "temperature_alerts": true,
    "gas_alerts": true,
    "maintenance_alerts": false
  }
}
```

All thresholds are required: outdoor -20–60, indoor 0–60 (at most two decimals), gas 1–4095. Preferences may be omitted or partially supplied.

- Create regular user: `POST /users` with `name`, `email`, optional `phone`, `password`, matching `password_confirmation` (minimum 12 characters). Do not send `role`.
- Update profile: `PUT /profile` with `name`, `email`, optional `phone`.
- Change password: `PUT /profile/password` with `current_password`, `password`, `password_confirmation`; then log in again.
- Account status: `PATCH /users/{user}/status` with `{"status":"inactive"}` or `{"status":"active"}`.
- Assign users: `PUT /coops/{coop}/users` with `{"user_ids":[2]}`; `{"user_ids":[]}` removes all assignments.
- Create coop: `POST /coops` with `name`, optional `location` and `description`.
- Create device: `POST /coops/{coop}/devices` with `device_identifier`, `name`, `type` (e.g. `esp32`), optional `connection_status` (`online`, `offline`, `unknown`). Identifiers are globally unique. Updates require identifying/name/type fields; coop and last-seen fields are not client-writable.

History accepts `from`, `to`, `sensor_identifier`, `sensor_type`, `page`, and `per_page` (maximum 100). Events accept date bounds and `event_type`. Date-only `to` includes that UTC day; omitted bounds select roughly 30 days. Maximum range is 366 days. Notifications accept `filter=all|read|unread` and pagination, and report total unread count.

Example login response shape (token is a placeholder):

```json
{"data":{"token":"<bearer-token>","token_type":"Bearer","user":{"id":2,"name":"Development User","email":"user@kandang.local","phone":null,"role":"user","status":"active","created_at":"2026-11-03T00:00:00.000000Z","updated_at":"2026-11-03T00:00:00.000000Z"}}}
```

Example incomplete indoor aggregation fields (excerpt, not the entire response):

```json
{"average":null,"partial_average":29.8,"complete":false,"expected_count":5,"valid_count":4,"missing_sensors":["indoor_dht22_5"],"stale_sensors":[],"invalid_sensors":[],"status":"unknown"}
```

## Sensor and alert behavior

Readings are stored individually. Indoor identifiers are `indoor_dht22_1` through `indoor_dht22_5`, type `indoor_temperature`, unit `celsius`. Other series: `outdoor_temperature` (celsius), `indoor_humidity` / `outdoor_humidity` (percent), `gas_adc` (type `gas`, unit `adc`). Composite foreign keys enforce device/coop consistency. A unique constraint prevents duplicate samples for a coop, identifier, type, and timestamp.

The latest valid, non-future sample per series is selected; invalid newer samples do not hide older valid samples. Valid ranges: temperature -40–80, humidity 0–100, gas 0–4095, with matching units. Indoor `average` requires all five fresh readings; otherwise it is null, `complete=false`, and `status=unknown`. `partial_average`, `valid_count`, missing/stale/invalid lists, and individual readings explain incomplete data. A partial average must not be presented as the complete dashboard average.

Seeded fresh readings `[28,29.2,31,33.5,30.2]` give 30.38 Celsius. These become stale after 300 seconds. Seeder reruns preserve readings and do not refresh timestamps. Seeding is restricted to local/testing environments and preserves existing account passwords and monitoring history.

Indoor alerts require a complete average strictly above the maximum, outdoor alerts a fresh value strictly below the minimum, and gas alerts a fresh value strictly above its threshold. Equality is normal. `AlertEvaluationService` persists transition state and locks the coop row in a transaction. Repeated warnings create no duplicate events/notifications; recovery is recorded once. Unknown/stale data preserves prior state and cannot falsely recover an alert. Device transitions are likewise deduplicated. Changed settings record a configuration event and reevaluate; identical settings create no duplicate event.

Events remain available regardless of notification preferences. Notifications target active admins and assigned users, with a unique recipient/event pair. Preferences apply at event creation; enabling them does not replay suppressed notifications. `push_notifications` gates in-app notification creation; sensor categories also honor critical/category switches, and configuration notices honor maintenance preferences. Email preferences are stored for future use. Actual push/email delivery is not implemented.

Phase 1 evaluates seeded samples and settings/device updates. GET requests do not create events. There is no live ingestion, scheduled evaluation, heartbeat timeout detection, or automatic control. Device connection state is stored/admin-managed. Future ingestion can invoke the existing evaluation service.

## Verification and database safety

```powershell
php vendor/bin/phpunit --colors=never
php vendor/bin/phpunit -c phpunit.mysql.xml --colors=never
php vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

Default tests use in-memory SQLite. MySQL tests use `.env.testing` and only `smart_kandang_test`; the bootstrap rejects other MySQL database names before migrations. Tests rebuild only the dedicated test schema. Do not cache configuration when switching test environments.

Verified 2026-11-03: both full suites passed **25 tests / 180 assertions each**, covering authentication, token expiry, inactive accounts, authorization, missing/stale/invalid sensors, threshold precision, alert deduplication, MySQL foreign keys, seeder idempotence, and refusal to seed production. All five development migrations are applied. Normal migration rerun reported nothing to migrate; seeder rerun preserved exact counts and content hashes across application tables and migration records. Development contains 2 users, 1 coop, 2 devices, 1,062 readings, 3 events, and 2 notifications. Live HTTP checks passed for both roles, including logout token revocation.

Future Flutter clients should consume `data.user.role/status`, securely store bearer tokens, handle 401 by signing in again, use individual readings for Heatmap, and honor completeness metadata for Dashboard. Flutter files were not changed during verification.

## Stabilization and Flutter Web

See [current stabilization verification and deployment instructions](../project_kandangayam/STABILIZATION_WEB_READINESS.md). The same API and database serve Android and Flutter Web. VM 2 can serve both Flutter static files and Laravel using [the Nginx template](deploy/nginx-flutter-web.conf.example); no fourth VM is required. This template has not been deployed to the remote infrastructure.

The additive chart endpoint accepts `from`, `to`, `page`, `per_page` (maximum 100), and optional `interval_seconds` (60, 300, 900, 3600, 21600, 86400). Date ranges are ordered and limited to 366 days; responses are bounded to 500 buckets, with automatic intervals targeting at most 400. The response contains `data`, pagination `meta` including `interval_seconds`, and `summary`. Indoor averages require all five valid sensor readings at the exact same timestamp before time-bucket aggregation. Separate timestamps never fill missing measurements. Summary averages are calculated over complete original samples, and the indoor maximum is the true complete-sample peak. Raw history remains available with its existing contract. No schema migration was needed for these changes.
# November 11 integration handover

Current deployment: [DEPLOYMENT_HANDOVER.md](DEPLOYMENT_HANDOVER.md). Hardware and MQTT payloads: [HARDWARE_MQTT_CONTRACT.md](HARDWARE_MQTT_CONTRACT.md). Final local evidence and limitations: [PRESENTATION_READINESS.md](../project_kandangayam/PRESENTATION_READINESS.md). Existing setup documentation follows; historical test counts below are not the latest results.
