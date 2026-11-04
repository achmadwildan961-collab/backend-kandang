# Phase 3: MQTT ingestion and local verification

The existing Laravel project now accepts sensor telemetry and heartbeats through a separate subscriber process. Flutter UI, ESP32 firmware, physical control and automation are unchanged. Remote VMs are being prepared by another team member on a different laptop. No remote broker connection is required for local development, and none was attempted after this clarification.

## Architecture and package

```text
Simulator / future ESP32 -> Mosquitto -> php artisan mqtt:listen
                                      -> topic/device resolution
                                      -> strict payload validation
                                      -> transaction: readings + receipt + device state
                                      -> existing aggregation / alert transitions
Flutter <- existing authenticated REST APIs <- MySQL
```

Composer installs `php-mqtt/client` **2.3.2** (constraint `^2.3`, locked in `composer.lock`). Its MQTT 3.1.1 transport is used without a Laravel wrapper. See the [upstream client documentation](https://github.com/php-mqtt/client).

`MqttConnectionFactory` owns connection settings; `MqttSubscriber` subscribes on one connection; `DeviceResolver` checks the topic against the existing device/coop relationship; `PayloadValidator` validates the message; `TelemetryIngestor` performs transactional persistence; `DeviceStatusService` handles timeouts. Existing `SensorCatalog`, `SensorAggregationService`, `AlertEvaluationService`, `EventRecorder`, models and API contracts are reused.

The small `CommitAwareMqttClient` adapter handles incoming QoS 1 publications before sending PUBACK. This is necessary because upstream 2.3.2 acknowledges before invoking callbacks and catches callback exceptions. Our adapter allows a database failure to escape without acknowledgement; the subscriber disconnects and reconnects with the same persistent session. Protocol-level acknowledgement ordering and duplicate delivery are covered by local tests. Recheck this adapter when upgrading the dependency.

## Configuration

The local `.env` and `.env.example` use these safe defaults:

```dotenv
MQTT_ENABLED=false
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_USERNAME=
MQTT_PASSWORD=
MQTT_CLIENT_ID=smart-kandang-laravel
MQTT_TOPIC_PREFIX=smart-kandang
MQTT_TLS=false
MQTT_TLS_CA_FILE=
MQTT_OFFLINE_AFTER_SECONDS=120
MQTT_FUTURE_TOLERANCE_SECONDS=30
```

Keep MQTT disabled while the infrastructure is unavailable. Preview and direct local simulation work without a broker. Enabling the subscriber requires a configured host, username and password; it refuses anonymous connections. Set credentials privately in `.env`, never in tracked files. Use a different stable client ID for each independent subscriber; do not run two instances with the same ID. For VM deployment enable TLS, use the broker certificate's DNS name, and configure a trusted CA file where needed. Certificate and hostname verification remain enabled.

After changing environment settings, run `php artisan config:clear` and restart the subscriber. Production deployment may then use `php artisan config:cache`. Database connections continue using the existing `DB_*` variables and UTC MySQL session timezone.

## Topics and device mapping

```text
smart-kandang/coops/{coopId}/devices/{deviceIdentifier}/telemetry
smart-kandang/coops/{coopId}/devices/{deviceIdentifier}/status
```

The subscriber uses `smart-kandang/coops/+/devices/+/telemetry` and the corresponding `/status` filter, both at QoS 1. Publishers must use QoS 1 and **retain=false**. Retained deliveries are rejected to prevent a broker snapshot from masquerading as a fresh heartbeat. QoS 0 cannot provide redelivery guarantees and is not recommended.

The coop must exist and the exact, case-sensitive device identifier must already belong to that coop. Existing seeded identifiers include `ESP32_DEVICE_A` and `ESP32_DEVICE_B`; inspect actual IDs through the existing admin API or database instead of assuming coop ID 1. Unknown devices, cross-coop mappings, altered topic suffixes and payload-supplied IDs are rejected. No device is automatically registered by MQTT.

The current database registers devices per coop, not individual sensor ownership per device. Any registered device can report catalog sensors in its own coop. The simulator can therefore send all nine values through one registered device; future firmware may divide sensors between A and B without changing identifiers. Broker ACLs must prevent each device credential from publishing under another device's topic.

## Payload contract

Simulated five-temperature example (use a current timestamp for a fresh reading):

```json
{
  "message_id": "sim-a-000001",
  "sent_at": "2026-11-04T12:00:00.123456Z",
  "readings": [
    {"sensor_identifier": "indoor_dht22_1", "value": 28.0},
    {"sensor_identifier": "indoor_dht22_2", "value": 29.2},
    {"sensor_identifier": "indoor_dht22_3", "value": 31.0},
    {"sensor_identifier": "indoor_dht22_4", "value": 33.5},
    {"sensor_identifier": "indoor_dht22_5", "value": 30.2}
  ]
}
```

Heartbeat on `/status`:

```json
{"message_id":"heartbeat-a-000001","sent_at":"2026-11-04T12:00:00Z","status":"online"}
```

`status` is `online` or `offline`. `unknown` is a backend state for devices without a known last-seen time. A graceful device shutdown can publish a fresh `offline` message. Use timeout detection for unexpected disconnects; a static, old-timestamp Last Will is not a substitute for this heartbeat contract.

Validation rules:

- Exactly `message_id`, `sent_at`, and either `readings` or `status`; no extra fields. Reading objects contain only `sensor_identifier` and `value`.
- Message ID: 1–128 ASCII letters/digits or `_ . : -`. IDs are case-sensitive and unique per registered device across both topics. Generate a new ID for every new message.
- Timestamp: UTC ISO 8601 ending in `Z`, optional 1–6 fractional digits. Calendar dates are checked. Values must fit the existing MySQL TIMESTAMP range (1970-01-01 00:00:01 through 2038-01-19 03:14:07 UTC) and be no more than the configured tolerance ahead of server time.
- Historical timestamps are preserved, not replaced with arrival time. Slightly future samples within tolerance are stored but not used by aggregation until their timestamp is reached.
- Maximum payload: 16,384 bytes; maximum JSON nesting depth: 16; 1–9 distinct sensor readings. Missing sensors are permitted and never fabricated.
- Values must be finite JSON numbers, not strings, nulls or booleans. NaN/Infinity, out-of-range values, duplicate and unknown sensors reject the whole message.

| Sensor identifiers | Type / unit resolved by catalog | Inclusive range |
|---|---|---|
| `indoor_dht22_1` through `indoor_dht22_5` | indoor_temperature / celsius | -40 to 80 |
| `outdoor_temperature` | outdoor_temperature / celsius | -40 to 80 |
| `indoor_humidity`, `outdoor_humidity` | corresponding humidity type / percent | 0 to 100 |
| `gas_adc` | gas / adc | 0 to 4095 |

The existing `DECIMAL(10,2)` reading storage remains authoritative for precision. `sent_at` is preserved to microseconds. Type, unit and coop/device database keys come from the server, never arbitrary payload fields.

## Transactions, duplicates, aggregation and alerts

An additive migration creates `mqtt_receipts`. It stores the device ID, message ID, SHA-256 identity key, topic/payload hash, sent time and received time. A unique `(device_id, message_key)` constraint preserves case-sensitive identity even under MySQL's default collation. Receipt records are not automatically pruned: removing them shortens the deduplication guarantee, particularly for heartbeats.

Ingestion locks the coop then the device, matching the existing alert/settings lock order. Readings, receipt, device update, alert state, events and notifications commit together. A repeated exact topic/payload returns `duplicate` without inserting samples, extending last-seen time or generating alerts. Reusing an ID with changed bytes is rejected, including reformatted JSON; retry the original payload verbatim. A new message that conflicts with an existing sensor/timestamp is rejected atomically, preserving the existing sample. No upsert overwrites history.

The existing five-sensor calculation yields **30.38°C**, which Flutter's existing one-decimal presentation displays as **30.4°C**. Outdoor temperature is separate. Each individual sensor remains in the heatmap response. All five latest valid readings must be within the existing 300-second stale window for `complete=true` and a non-null average. Partial/stale groups remain incomplete. As before, latest aggregation uses each sensor's latest fresh reading; it does not require their timestamps to match. The Flutter historical grouping expects a common timestamp for each five-sensor batch.

Successful ingestion invokes the existing threshold evaluator: indoor average above maximum, outdoor below minimum and gas above threshold. Persistent transition state prevents repeated warnings, creates recovery events when normal readings return, and respects notification preferences. Unknown/stale sensor data does not imply recovery. Delayed old readings cannot supersede newer readings or trigger warnings from those old values.

Device last-seen time is the latest accepted sent time, capped at arrival time, rather than delivery time. This conservative policy prevents queued old messages from making an offline device appear newly online. Receipts preserve ordering to microseconds; older or equal-timestamp messages do not override a newer device status. Fresh telemetry or heartbeat restores online status; old messages and duplicate retransmissions do not reset the timeout. Explicit fresh `offline` status is supported.

`mqtt:check-devices` marks devices offline when last-seen exceeds `MQTT_OFFLINE_AFTER_SECONDS` (default 120). No last-seen means unknown, regardless of old sensor records. The command reuses alert evaluation for deduplicated disconnect/reconnect events. It is scheduled every minute with overlap prevention, so detection may take the timeout plus up to one scheduler interval. Run the scheduler as well as the subscriber; device status does not update automatically if neither the check nor scheduler runs.

## Local commands and simulator

From PowerShell, with existing local MySQL running:

```powershell
cd D:\FlutterProjects\smart-kandang-backend
$env:Path = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;' + $env:Path
php artisan migrate
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8000
```

The migration is additive; do not use `migrate:fresh`, `migrate:refresh` or reseeding against development/production. Preserve the existing environment and application key.

Preview only, using your existing coop/device identifiers (example assumes coop 1):

```powershell
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=sample
```

Direct local ingestion uses the same validator, database transaction, aggregation and alert path without MQTT networking. This mode **writes simulated readings/events to the currently configured database**:

```powershell
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=sample --transport=local --repeat=2
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=normal --transport=local
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=high-indoor --transport=local
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=low-outdoor --transport=local
php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=high-gas --transport=local
php artisan mqtt:simulate 1 ESP32_DEVICE_A --status=online --transport=local
php artisan mqtt:check-devices
```

`sample` produces the requested 30.38 average. Other scenarios use fixed simulated values; whether they warn depends on the coop's configured thresholds. New invocations generate UUIDs and current timestamps; `--repeat=2` repeats identical bytes to exercise deduplication. `--message-id` and `--sent-at` support reproducible delayed/duplicate tests. The simulator is restricted to local/testing environments, and preview is the default.

Once an authenticated broker is available, run these in separate terminals:

```powershell
php artisan mqtt:listen
php artisan schedule:work
```

Then publish with `php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=sample --transport=mqtt --repeat=2`. A publish acknowledgement alone is not proof of database ingestion: check receipt rows, readings and the API below. The subscriber uses one persistent session for both topics, retries failed sessions with 2, 4, 8, 16, then 30-second capped delays, and logs sanitized result/error types. It acknowledges invalid messages after rejecting them so they do not create endless delivery loops. Database failures remain unacknowledged for redelivery. Broker persistence and queue limits determine what survives broker outages.

`mqtt:listen --once --max-seconds=15` is a bounded diagnostic session once enabled. Linux PCNTL SIGINT/SIGTERM handlers request loop shutdown and disconnect. Windows lacks PCNTL; Ctrl+C terminates the process, so committed receipt deduplication is still necessary. Real Linux signal behavior and broker reconnect behavior require deployment verification.

## Mosquitto publishing and ACL recommendations for later

The following are deployment examples only; no broker settings were changed locally. Keep `allow_anonymous false`, use separate subscriber and per-device credentials, and grant only required topic permissions. For a password/ACL-file Mosquitto configuration, example ACL content is:

```text
user smart-kandang-backend
topic read smart-kandang/coops/+/devices/+/telemetry
topic read smart-kandang/coops/+/devices/+/status

user device-a
topic write smart-kandang/coops/1/devices/ESP32_DEVICE_A/telemetry
topic write smart-kandang/coops/1/devices/ESP32_DEVICE_A/status

user device-b
topic write smart-kandang/coops/1/devices/ESP32_DEVICE_B/telemetry
topic write smart-kandang/coops/1/devices/ESP32_DEVICE_B/status
```

Replace coop IDs and usernames with registered deployment identities. Do not give device accounts wildcard write access. A temporary simulator publisher needs its own narrowly scoped write credentials; the backend subscriber's read-only account should not be granted write access just to run simulation. Broker settings should enforce the 16,384-byte message limit, persistence, bounded queues and TLS on the deployed listener. Adapt the ACL mechanism to the installed Mosquitto version. See the [official Mosquitto configuration reference](https://mosquitto.org/man/mosquitto-conf-5.html).

For command-line publish/subscribe tests, store authentication options in the tool's private default config files rather than process arguments: `%USERPROFILE%\mosquitto_pub` and `%USERPROFILE%\mosquitto_sub` on Windows, or `~/.config/mosquitto_pub` and `~/.config/mosquitto_sub` on Linux. Use file permissions appropriate for secrets. The publisher file contains the device/simulator account; the subscriber file contains a read-only test account. See the [Mosquitto publish reference](https://mosquitto.org/man/mosquitto_pub-1.html).

Generate a fresh payload and topic without writing database records:

```powershell
$sample = php artisan mqtt:simulate 1 ESP32_DEVICE_A --scenario=sample | ConvertFrom-Json
$sample.payload | ConvertTo-Json -Depth 6 -Compress | Set-Content -Encoding ascii telemetry.json
mosquitto_sub -h BROKER_DNS -p 8883 --cafile broker-ca.crt -V mqttv311 -q 1 -t 'smart-kandang/coops/1/devices/ESP32_DEVICE_A/telemetry' -v
# Another terminal, with the subscriber already running:
mosquitto_pub -h BROKER_DNS -p 8883 --cafile broker-ca.crt -V mqttv311 -q 1 -t $sample.topic -f telemetry.json
mosquitto_pub -h BROKER_DNS -p 8883 --cafile broker-ca.crt -V mqttv311 -q 1 -t $sample.topic -f telemetry.json
```

Do not add `-r`. The repeated file intentionally uses the same ID/timestamp. Generate a new file for a new measurement. `$sample` must be available in the publishing terminal. For an isolated authenticated non-TLS development listener use its actual configured port and omit TLS options; do not send cleartext credentials over untrusted networks.

## Verify MySQL and REST results

Use an authenticated MySQL client (password prompt or private login file). These queries are read-only:

```sql
SELECT id, coop_id, device_identifier, connection_status, last_seen_at
FROM devices ORDER BY id;

SELECT device_id, message_id, sent_at, received_at
FROM mqtt_receipts ORDER BY id DESC LIMIT 10;

SELECT coop_id, device_id, sensor_identifier, sensor_type, value, unit, recorded_at
FROM sensor_readings ORDER BY recorded_at DESC, id DESC LIMIT 20;

SELECT event_type, device_id, occurred_at FROM system_events ORDER BY id DESC LIMIT 10;
```

After publishing twice, the message should have one receipt and one set of readings. Obtain an existing active account's API token through `/api/v1/auth/login`, then check:

```powershell
# API_TOKEN is held privately in this terminal; do not commit it.
$headers = @{ Authorization = "Bearer $env:API_TOKEN"; Accept = 'application/json' }
Invoke-RestMethod 'http://127.0.0.1:8000/api/v1/coops/1/sensors/latest' -Headers $headers
Invoke-RestMethod 'http://127.0.0.1:8000/api/v1/coops/1/sensors/history' -Headers $headers
Invoke-RestMethod 'http://127.0.0.1:8000/api/v1/coops/1/devices' -Headers $headers
```

Flutter already reads these APIs. Use its existing refresh action to fetch new readings; Phase 3 adds no WebSocket/push polling or Flutter redesign. Reuse the Phase 2 Flutter run command with `API_BASE_URL=http://127.0.0.1:8000/api/v1` for local Chrome.

## Final three-VM deployment handoff

| VM | Responsibility | Later configuration |
|---|---|---|
| VM 1 | MySQL database | Existing schema/data, scoped application account; accept DB traffic only from VM 2 and authorized backup access |
| VM 2 | Laravel HTTP API + MQTT subscriber | `DB_HOST` points to VM 1; `MQTT_HOST` points to VM 3; independent API, subscriber and scheduler processes |
| VM 3 | Mosquitto broker + backup | Authenticated TLS listener, topic ACLs, durable broker state, restricted backup storage/jobs |

Handoff sequence once the other team member supplies working VM networking:

1. Confirm actual routable addresses/DNS across laptops; a host-only network on one laptop does not establish cross-laptop connectivity. No planned address is assumed here. Synchronize clocks with NTP.
2. Back up the existing database before deployment. Move data using a controlled dump/restore, preserve the Laravel application key, and configure least-privilege DB access on VM 1. Apply additive migrations on VM 2 with `php artisan migrate --force`; do not reseed or reset production.
3. On VM 3, provision subscriber and per-device credentials, ACLs and TLS. Enable broker persistence. Configure encrypted/restricted database backups from VM 1 plus broker/config backups; verify restoration, not just backup-file creation.
4. On VM 2, install locked Composer dependencies, configure the private `.env`, update API URL/CORS for the deployed frontend, and enable MQTT. Run the API under the deployment web server/PHP-FPM. Run `php artisan mqtt:listen` under systemd/Supervisor with automatic restart and a single stable client ID. Run Laravel's scheduler every minute (cron `php artisan schedule:run`, or a separately supervised `schedule:work`). Ensure writable storage/cache directories and log rotation.
5. Verify connectivity and authentication, authorized topic publication, denied cross-device publication, real QoS 1 publish/receive/MySQL/API flow, duplicate delivery, broker/subscriber restart and database outage recovery. Check heartbeat timeout/recovery and Linux SIGTERM shutdown. No such remote checks are claimed by local mock tests.
6. ESP32 firmware comes later: provision its own broker credential and device topic, synchronize UTC time, send catalog sensor IDs, unique durable message IDs, QoS 1 non-retained payloads and regular heartbeats. Firmware, physical fan/lamp commands and Smart Automation are outside this phase.

## Verification record

Commands used for verification:

```powershell
php artisan test
php vendor/bin/phpunit -c phpunit.mysql.xml --colors=never
php artisan migrate
php artisan migrate:status
php artisan schedule:list
```

`php artisan test` uses the default SQLite in-memory test configuration. The explicit PHPUnit command uses the existing dedicated `smart_kandang_test` MySQL database; `Tests\TestCase` refuses any other MySQL test database. Use the direct PHPUnit command for the alternate XML configuration: the Artisan wrapper supplies its own configuration argument.

Verified on **2026-11-04 (Asia/Jakarta)**:

| Check | Executed result |
|---|---|
| `php artisan test` (SQLite in memory) | **66 passed, 325 assertions** |
| `php vendor/bin/phpunit -c phpunit.mysql.xml --colors=never` (local MySQL 8.4.3, dedicated test database) | **66 passed, 325 assertions**, exit 0 |
| PHP syntax checks across app/config/migrations/routes/tests | **88 files, zero failures** |
| Laravel Pint on changed PHP files | Passed |
| `composer validate --strict`, `composer check-platform-reqs` | Passed |
| Development migration and rerun | Receipt table added; rerun reported nothing to migrate; all six migrations ran |
| Development data preservation | Hashes of all audited pre-existing data tables unchanged; only the migration ledger and new empty receipt table were added |
| Scheduler registration | `mqtt:check-devices` registered every minute |
| Simulator preview with existing coop 1 / ESP32_DEVICE_A | Valid fresh JSON generated; no database write or network connection |
| Disabled subscriber CLI | Refused startup with exit 1 as intended; no connection attempted |

The 41 added MQTT tests/cases cover strict validation, device/coop mapping, duplicate and conflicting IDs, microsecond ordering/history bounds, partial/stale data, aggregation, threshold/recovery deduplication, notification preferences, heartbeat/timeouts, transactional rollback, local simulation, mocked transport/reconnect/shutdown and commit-before-ack behavior. The 25 existing API tests remain passing. Verification discovered and fixed fractional-second filtering in both latest aggregation and sensor history; REST response shapes were preserved.

MQTT tests use mocked connections/protocol delivery, while database ingestion is exercised against real local MySQL. Development telemetry was not inserted by the verification run; simulator ingestion tests ran only in test databases. No local or remote Mosquitto publish/receive test is claimed. TLS handshakes, live broker authentication/ACLs, persistent queue replay, actual Linux signals and remote network behavior remain deployment checks.

## File inventory

Preserved and completed the six initial Phase 3 files: `config/mqtt.php`, `database/migrations/2026_11_04_000000_create_mqtt_receipts_table.php`, and `app/Services/Mqtt/{DeviceResolver,DeviceStatusService,PayloadValidator,TelemetryIngestor}.php`.

Added `app/Services/Mqtt/{CommitAwareMqttClient,MqttConnectionFactory,MqttSubscriber}.php`, `app/Console/Commands/{MqttListen,MqttCheckDevices,MqttSimulate}.php`, `tests/Feature/{MqttIngestionTest,MqttTransportTest}.php`, and this document. Updated `composer.json`/`composer.lock`, private `.env`, `.env.example`, `routes/console.php`, README and timestamp precision handling in the existing sensor API path. No Flutter files were modified.
