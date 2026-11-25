# Local simulator and isolated broker testing

No hardware required. Run from backend root. Do not use operational DB/broker or copy operational credentials. No firmware modified. The Phase8.2 simulator is additive; old mqtt:simulate remains legacy and can write to DB/broker when explicitly requested, so do not use that legacy command for this read-only preview.

## Offline contract and preview (PowerShell)

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL='(null)'
php artisan firmware:contract-check
php artisan firmware:simulate indoor
php artisan firmware:simulate outdoor
php artisan firmware:simulate indoor --scenario=partial
php artisan firmware:simulate outdoor --scenario=invalid-unit
php artisan firmware:simulate indoor --scenario=stale
php artisan firmware:simulate indoor --scenario=out-of-order
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/FirmwareReadinessTest.php tests/Feature/StagingMqttTransportTest.php
```

Preview only generates JSON, no database writes or network. Scenario invalid-unit intentionally produces rejected input. Duplicate/replay/reconnect/config/ACK/report/reboot/display require the repeatable PHPUnit virtual harness, not preview alone. The harness registers two virtual board contexts in isolated tests, feeds actual ingestor, stages actual configuration transaction, dispatches through FakeMqttTransport and processes actual DeviceFeedback. It does not bypass test-only safety gates, and labels simulated GPIO feedback as unverified physical. Tests initialize/migrate only in-memory SQLite or externally verified disposable MySQL.

## Actual loopback broker (optional, NOT RUN in this session)

Audit found no mosquitto/docker executable and no local broker process. No broker was installed or contacted. The following setup and automated smoke are provided for a machine with Mosquitto installed. An existing service on 1883 or a localhost tunnel to production is **not** an isolated test broker. Use a dedicated foreground process, a new directory and unused port18884; no bridge, no shared persistence, no production credentials.

Configuration uses listener, allow_anonymous=false, password_file and exact-topic ACLs following [Mosquitto configuration documentation](https://mosquitto.org/man/mosquitto-conf-5.html). Use interactive password entry, not a password command-line argument; `mosquitto_passwd -c` overwrites a file, so the example guards a newly created path. See [mosquitto_passwd documentation](https://mosquitto.org/man/mosquitto_passwd-1.html).

Terminal A (PowerShell; choose installed binaries explicitly):

```powershell
$taskBrokerExe='C:\Program Files\mosquitto\mosquitto.exe'
$taskPasswdExe='C:\Program Files\mosquitto\mosquitto_passwd.exe'
if (!(Test-Path -LiteralPath $taskBrokerExe)) { throw 'Install an isolated Mosquitto binary first' }
if (Get-NetTCPConnection -LocalPort 18884 -State Listen -ErrorAction SilentlyContinue) { throw 'Port occupied: do not stop the existing listener' }
$taskBrokerRoot=Join-Path (Get-Location) ('.runtime\broker-virtual-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $taskBrokerRoot | Out-Null
$taskPasswordFile=Join-Path $taskBrokerRoot 'passwd'
if (Test-Path -LiteralPath $taskPasswordFile) { throw 'Password file must be new' }
& $taskPasswdExe -c $taskPasswordFile virtual-backend
& $taskPasswdExe $taskPasswordFile virtual-indoor
& $taskPasswdExe $taskPasswordFile virtual-outdoor
Copy-Item -LiteralPath deploy/mosquitto.virtual.acl.example -Destination (Join-Path $taskBrokerRoot 'acl')
$taskConfig=@(
 'listener 18884 127.0.0.1'
 'allow_anonymous false'
 ('password_file '+$taskPasswordFile.Replace('\','/'))
 ('acl_file '+(Join-Path $taskBrokerRoot 'acl').Replace('\','/'))
 'persistence false'
 'log_dest stdout'
 'log_type error'
 'log_type warning'
)
$taskConfig | Set-Content (Join-Path $taskBrokerRoot 'mosquitto.conf') -Encoding ascii
& $taskBrokerExe -c (Join-Path $taskBrokerRoot 'mosquitto.conf')
# Foreground; after terminal B completes, Ctrl+C for normal shutdown of THIS process.
```

Terminal B, same repository, transient environment only (never commit passwords):

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL='(null)'
$env:MQTT_STAGING_ENABLED='true'
$env:MQTT_STAGING_VIRTUAL_ONLY='true'
$env:MQTT_STAGING_HOST='127.0.0.1'
$env:MQTT_STAGING_PORT='18884'
$env:MQTT_STAGING_USERNAME='virtual-backend'
$taskCredential=Get-Credential -UserName virtual-backend -Message 'Dedicated virtual broker password only'
$env:MQTT_STAGING_PASSWORD=$taskCredential.GetNetworkCredential().Password
$env:MQTT_STAGING_VIRTUAL_SCOPES='{"VIRTUAL_INDOOR":1,"VIRTUAL_OUTDOOR":1}'
$env:MQTT_STAGING_RETAINED_AUTHORIZED='false'
php artisan firmware:broker-smoke
Remove-Item Env:MQTT_STAGING_PASSWORD
$env:MQTT_STAGING_ENABLED='false'
```

Avoid cached application configuration from another environment; these commands must resolve the dedicated variables. The smoke opens two fresh clients, waits for SUBACK, publishes one nonretained operational **virtual** config, waits for PUBACK and confirms matching JSON on the observer. It returns nonzero if disabled/timeout/mismatch. It does not call Laravel telemetry/ACK ingress, prove broker ACL deny enforcement, validate TLS, or prove device apply. That boundary must be retained in the evidence report.

The config-only real adapter rejects production environment, nonliteral127.0.0.1 host, credentials missing, missing virtual-only opt-in, non-VIRTUAL identifiers, wrong coop/topic/domain/hash/expiry/ID, commands and unauthorized retained publication **before connection**. Attempt budget2 (hard cap3), per-operation timeout5 (cap10); identical payload/idempotency key across retries. Unknown broker outcome can duplicate a snapshot; virtual/firmware version/hash dedup is mandatory. PUBACK success never updates DB applied/report state. Driver remains unbound; production ConfigurationDispatcher still rejects anything except test FakeMqttTransport. This intentionally prepares a transport without bypassing firmware-auth safety gates.

TLS support: use an isolated CA-signed server certificate with IP SAN127.0.0.1, configure broker certfile/keyfile appropriately, set MQTT_STAGING_TLS=true and MQTT_STAGING_CA_FILE to the CA PEM. Peer/name checks remain enabled; self-signed bypass is not supported. TLS handshake/certificate acceptance is **not** verified by mocked tests. Remote/production host support requires a separately accepted adapter/security design, not changing virtual-only flags.

After Ctrl+C in terminal A, verify port18884 has no listener and record process/log evidence. Never kill all mosquitto processes or alter an installed service. Keep ignored test logs if needed; no recursive cleanup required.

## Disposable MySQL

The checkpoint records actual instance identity/port/datadir and safe shutdown. Before running MySQL tests always verify listener PID/parent/commandline and `SELECT @@datadir` belong to a freshly initialized `.runtime` dedicated server; database must be smart_kandang_test. Empty password in PowerShell uses DB_PASSWORD='(empty)', DB_URL='(null)', DB_SOCKET='(empty)' to prevent fallback to .env. Do not run generic artisan migrate/fresh against default .env. Keep SQLite and MySQL results separate. The report must label fake integration, mocked MQTT client, real broker and physical tests independently.
