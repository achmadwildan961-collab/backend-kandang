# Three-VM deployment and presentation handover

10 November 2026. No remote systems were contacted. Replace uppercase placeholders with confirmed values; examples are not real infrastructure addresses. Architecture: Flutter Android/Web → Laravel REST on VM 2 → MySQL on VM 1. Devices → Mosquitto on VM 3 → Laravel subscriber on VM 2. VM 3 also handles backups.

## Local Windows startup

Existing tool paths:

This machine currently has Edge; the previously documented Chrome executable is absent. Use `flutter run -d edge` with the same Web arguments locally. The Chrome command below is for machines with Chrome installed at that path (or set CHROME_EXECUTABLE to its actual location). This session's release browser verification uses Edge.

```powershell
$env:Path = 'D:\flutter_windows_3.47.5-stable\flutter\bin;C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;' + $env:Path
$env:CHROME_EXECUTABLE = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
# Only if MySQL is stopped; use the EXISTING instance/data directory:
Start-Process -FilePath 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini','--bind-address=127.0.0.1','--mysqlx-bind-address=127.0.0.1' -WindowStyle Hidden
Set-Location D:\FlutterProjects\smart-kandang-backend
php artisan serve --host=127.0.0.1 --port=8000
```

Separate terminal:

```powershell
Set-Location D:\FlutterProjects\project_kandangayam
$env:CHROME_EXECUTABLE = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
flutter run -d chrome --web-hostname=localhost --web-port=58111 --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
# Android Emulator (replace the device selector from flutter devices):
flutter run -d ANDROID_DEVICE_ID --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
# Physical Android DEBUG, same trusted LAN:
flutter run -d ANDROID_DEVICE_ID --dart-define=API_BASE_URL=http://LAPTOP_LAN_IP:8000/api/v1
```

For physical LAN access run Laravel with `--host=0.0.0.0`, allow port 8000 only on the trusted LAN, and use the laptop's actual IPv4 address. Android debug permits HTTP; release requires HTTPS. Do not use a phone's own localhost as its backend. Web development CORS must include exactly `http://localhost:58111` (and `http://127.0.0.1:58111` if used). Credentials stay in private backend `.env`; never put them in Dart defines.

## Build commands

```powershell
Set-Location D:\FlutterProjects\project_kandangayam
flutter analyze
flutter test
flutter build web --release --no-pub --csp --no-web-resources-cdn --dart-define=API_BASE_URL=/api/v1
flutter build apk --release --no-pub --dart-define=API_BASE_URL=https://VM2_PUBLIC_DOMAIN/api/v1
```

Artifacts: `build/web/` and `build/app/outputs/flutter-apk/app-release.apk`. API_BASE_URL is compile-time; changing the backend requires rebuilding. Release Web defaults to same-origin `/api/v1`. Absolute development loopback/emulator URLs are rejected in deployed releases. Localhost Web preview remains allowed for testing. Android requires an absolute HTTPS URL. The locally built release APK uses `https://configure-backend.invalid/api/v1` as an explicit inert placeholder if no confirmed domain is available; **rebuild before a connected mobile presentation**. Existing release signing uses the debug key: suitable for local testing, not store distribution.

Optional debug APK for the emulator demonstration:

```powershell
flutter build apk --debug --no-pub --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
```

### This workstation's build-tool recovery

C: ran out of temporary space during the first native build. Use task-local caches on D: without deleting user files or changing global settings:

```powershell
cd D:\FlutterProjects\project_kandangayam
New-Item -ItemType Directory -Force .runtime\temp,.runtime\android-user,.runtime\gradle-cache | Out-Null
$env:TEMP = 'D:\FlutterProjects\project_kandangayam\.runtime\temp'
$env:TMP = $env:TEMP
$env:ANDROID_USER_HOME = 'D:\FlutterProjects\project_kandangayam\.runtime\android-user'
$env:GRADLE_USER_HOME = 'D:\FlutterProjects\project_kandangayam\.runtime\gradle-cache'
```

The installed new Android CLI lists NDK packages with slash syntax. Its sdkmanager compatibility wrapper failed when Flutter requested the semicolon form. Install the exact Flutter-required package directly (no NDK version change):

```powershell
& 'D:\Android\Sdk\cmdline-tools\latest\bin\android.exe' sdk install ndk/28.2.13676358 --sdk=D:\Android\Sdk
```

Then rerun the build command above. These environment variables apply only to that terminal. See Android's [environment-variable reference](https://developer.android.com/tools/variables) for ANDROID_USER_HOME; the package syntax was verified with the installed CLI's help and package list.

## VM 1: existing MySQL

Install/operate MySQL on its private interface. Preserve the existing database and users; back up before normal migrations. Give a dedicated application account privileges only on the application database and only from VM 2's private address. Restrict TCP 3306 to VM 2 (and a separately scoped backup account/source if required). Do not expose unrestricted public MySQL. Use verified database TLS if traffic leaves a trusted private network. Do not use `migrate:fresh`, reset users or rerun development seeds on production.

## VM 2: Laravel, HTTPS Web, subscriber, scheduler

Install PHP 8.3+ with required Composer extensions (PDO MySQL, mbstring, OpenSSL, XML, curl, fileinfo, and Linux pcntl for graceful subscriber shutdown), Composer, PHP-FPM and Nginx. Paths in templates: `/srv/smart-kandang-backend`, `/srv/smart-kandang-web`.

Create a dedicated unprivileged service account once:

```sh
sudo useradd --system --home /srv/smart-kandang-backend --shell /usr/sbin/nologin smart-kandang
```

Deploy source, install dependencies, and provide private `.env` readable by the app/service account. Keep the existing APP_KEY on upgrades; generate a key only for a genuinely new installation. Let PHP-FPM and the subscriber write `storage` and `bootstrap/cache` using a shared group or ACL; code is read-only. Do not recursively make the application world-writable. Use a matching FPM pool user/group and socket permissions for Nginx.

Environment requirements (replace placeholders; never commit secrets):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://VM2_PUBLIC_DOMAIN
DB_CONNECTION=mysql
DB_HOST=VM1_PRIVATE_IP
DB_PORT=3306
DB_DATABASE=EXISTING_DATABASE
DB_USERNAME=SCOPED_APP_USER
DB_PASSWORD=PRIVATE_SECRET
CORS_ALLOWED_ORIGINS=https://VM2_PUBLIC_DOMAIN
MQTT_ENABLED=true
MQTT_HOST=VM3_BROKER_DNS
MQTT_PORT=8883
MQTT_USERNAME=laravel-subscriber
MQTT_PASSWORD=PRIVATE_SECRET
MQTT_CLIENT_ID=smart-kandang-vm2
MQTT_TOPIC_PREFIX=smart-kandang
MQTT_TLS=true
MQTT_TLS_CA_FILE=/etc/ssl/certs/ca-certificates.crt
MQTT_OFFLINE_AFTER_SECONDS=120
MQTT_FUTURE_TOLERANCE_SECONDS=30
MQTT_RETRY_MIN_SECONDS=2
MQTT_RETRY_MAX_SECONDS=30
MQTT_DEVICE_SENSORS='{}'
SENSOR_STALE_AFTER_SECONDS=300
```

The client ID must be stable and unique; only one subscriber instance should use it. Confirm device sensor ownership before enabling the strict JSON mapping described in HARDWARE_MQTT_CONTRACT.md. An unauthenticated broker is not supported by the deployment contract. Never disable TLS peer verification. Keep all VM clocks synchronized.

```sh
cd /srv/smart-kandang-backend
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs
php artisan migrate:status
# After backup and confirming DB_HOST / DB_DATABASE:
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Copy contents of Flutter `build/web` to `/srv/smart-kandang-web`. Adapt `deploy/nginx-flutter-web.conf.example` with actual DNS, certificate paths and installed PHP-FPM socket. It serves Flutter `/`, proxies `/api/` to a loopback-only Laravel Nginx/PHP-FPM server on 8080, forwards bearer authorization, and sets CSP/security headers. Do not run `artisan serve` in production. Validate TLS before enabling HSTS. Secure Web storage requires HTTPS or localhost; plain HTTP on a remote IP is not an acceptable production browser deployment.

```sh
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl status nginx php8.3-fpm --no-pager
sudo cp deploy/smart-kandang-mqtt.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smart-kandang-mqtt
sudo systemctl status smart-kandang-mqtt --no-pager
sudo journalctl -u smart-kandang-mqtt -n 100 --no-pager
```

The systemd service reads Laravel `.env`/cached config through the application; do not add secrets to ExecStart. Its logs contain results/error classes, not credentials or payloads. Restart it after deployment/config changes: `sudo systemctl restart smart-kandang-mqtt`.

Scheduler (required even when no messages arrive): add `/etc/cron.d/smart-kandang`:

```cron
* * * * * smart-kandang cd /srv/smart-kandang-backend && /usr/bin/php artisan schedule:run >> /srv/smart-kandang-backend/storage/logs/scheduler.log 2>&1
```

Set log rotation for scheduler/Laravel logs. `php artisan schedule:list` should show `mqtt:check-devices` every minute. Manual checks: `php artisan mqtt:check-devices`; local development alternative: `php artisan schedule:work`. Do not run multiple scheduler processes for the same app. Without scheduling, stored device statuses can lag; sensor freshness is still separately evaluated.

## VM 3: Mosquitto and backup

Adapt `deploy/mosquitto.conf.example` and `deploy/mosquitto.acl.example`. Obtain certificates matching the broker DNS. Allow broker 8883 only from VM 2 and required device networks. Use distinct subscriber/board accounts. Set passwords interactively (`mosquitto_passwd -c /etc/mosquitto/passwd laravel-subscriber` only when creating a NEW password file; omit `-c` when adding device users). Protect the password file and TLS key; make them readable by Mosquitto only. Do not put passwords on shell command lines or in examples.

```sh
sudo systemctl restart mosquitto
sudo systemctl enable mosquitto
sudo systemctl status mosquitto --no-pager
sudo journalctl -u mosquitto -n 100 --no-pager
```

Backups on VM 3 require a scheduled job, private transport from VM 1, a least-privileged backup DB account, encryption at rest, retention, disk-space monitoring and an isolated restore test. For InnoDB use a consistent `mysqldump --single-transaction` snapshot with a protected defaults file, not command-line passwords. Back up Laravel private configuration/APP_KEY securely and Mosquitto configuration/password/ACL/persistence files as appropriate. Define recovery objectives and retention with teammates; a backup is not proven until restored. No backup credentials, job or restore test is claimed configured here.

## Diagnostics and teammate checklist

```sh
# On VM 2:
nc -vz VM1_PRIVATE_IP 3306
nc -vz VM3_BROKER_DNS 8883
openssl s_client -connect VM3_BROKER_DNS:8883 -servername VM3_BROKER_DNS -verify_return_error
curl -I https://VM2_PUBLIC_DOMAIN/
curl -i https://VM2_PUBLIC_DOMAIN/api/v1/coops
# Expected 401 JSON proves API routing; it does not prove MySQL login.
php artisan migrate:status
php artisan schedule:list
```

Windows equivalents: `Test-NetConnection HOST -Port PORT`; inspect browser Network/Console for HTTPS, CORS and API failures. `/up` alone does not prove DB connectivity. Log in with existing private credentials to verify Laravel→MySQL. Never share bearer tokens in screenshots/logs.

1. Confirm actual VM addresses, public app/broker domains and certificates.
2. Verify VM 2→VM 1 MySQL authentication and migration status.
3. Verify VM 2→VM 3 MQTT TLS/authentication/ACL and subscriber health.
4. Confirm ESP32/NodeMCU identifiers, coop mapping, sensor ownership and ADC scale; preserve identifiers already used by firmware.
5. Verify each board→VM 3 with real non-retained telemetry, UTC timestamp and unique message ID.
6. Verify database receipt/reading count, REST latest/history and Flutter polling. Redeliver identical payload; count must not increase.
7. Check missing/stale sensors, silent device timeout, alert/recovery transitions.
8. Verify laptop/phone→VM 2 HTTPS; rebuild/install APK with confirmed URL, then check protected refresh, logout and CSV save.
9. Run a backup and isolated restore; record remote/hardware evidence separately from local tests.

## CSV semantics

`GET /api/v1/coops/{coop}/sensors/export?from=UTC_ISO&to=UTC_ISO` requires bearer authentication and coop access. Same ordered date validation as chart (maximum 366 days). At most approximately 400 automatically selected time buckets, bounded in memory; no raw-history download. Each row timestamp is the earliest actual measurement time in its bucket, UTC. Indoor value averages only complete five-DHT22 batches; outdoor, indoor humidity and gas are independently averaged. Values need not originate from the same instant within a bucket. Empty fields mean unavailable; zero is not substituted. `X-Aggregation-Seconds` declares bucket duration; `X-Export-Rows` supports empty-result feedback. CSV is UTF-8 BOM with CRLF, suitable for Excel printing. History's date/coop selections apply; metric filters do not remove report columns.
