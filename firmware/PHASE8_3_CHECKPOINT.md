# Phase 8.3B checkpoint - safe staging software selesai, live acceptance pending

26 November 2026: resumed on `feat/phase8-3-firmware`, clean initial HEAD `c3ae546` (Phase8.3A committed by owner). Original backend baseline65a7ef5 preserved. Earlier Phase8.3B request explicitly authorized backend + firmware safe integration. Latest resume Phase8.3 instruction preserves those existing backend changes; final resume only verifies source/evidence/handoff, does not restart completed implementation. No new branch, staging/commit/push/deploy or operational broker/DB access.

Selesai: scoped staging credentials/capability, reuse transactional deliveries/dispatcher/feedback, strict session/ACK/report/telemetry ingress, LCD staging transport, actual C++ -> Laravel DB/API integration tests, Arduino MQTT retain metadata adapter. Production bindings remain DisabledMqttTransport/DisabledPushProvider; physical commands disabled. Locked schemas/fixtures unchanged. New integration flag defaultfalse; VIRTUAL identities and isolated database only. No schema migration. No production subscriber, Arduino live session/ACK/report sender, broker identity bridge or physical control added; these remain unapproved protocol/hardware decisions.

## Bukti final Phase8.3B

- Full SQLite:207 tests/1944 assertions PASS48.474s; full disposable MySQL8.4.3:207 tests/1944 assertions PASS156.939s. No skips on this workstation; all198 previous backend tests preserved +9 integration tests.
- Focused integration/delivery/transport:26 tests/237 assertions PASS;2 added replay/session cases25 assertions PASS, included in full207.
- Native C++ rebuilt PASS;49 Python tests PASS1.909s (45 existing +4 MQTT metadata tests). Same headers as sketches; mock storage/bus, not NVS silicon.
- ESP32 development build PASS flash1073964/RAM58840. NodeMCU development/display PASS flash404480/RAM47800/IRAM61171. CLI1.6.0, ESP32 core3.3.11, ESP8266core3.1.2, ArduinoJson7.4.3, MQTT2.5.2; no upload.
- Lock:9 fixtures/8schemas PASS, unchanged. Full Pint,9 changed/new PHP lint, clang-format dry-run, git diff --check PASS. No migration/routes/provider/Flutter changes.
- Git:HEADc3ae54641c015a2d46d73cbe6a07017409559c99;18 tracked files modified +4 new files, none staged. No branch creation/switch/commit/push/reset/clean/merge/deploy.
- BROKER-LIVE NOT VERIFIED: no Mosquitto/Docker in PATH, standard Mosquitto install locations absent, no Mosquitto process. MQTT IO in tests uses mocked client. Actual native payloads and database writes are real test executions; no physical hardware/push acceptance.

Evidence logs: ignored `.runtime/phase8-3-tools/phase83b-{focused,sqlite,mysql-final,native-tests,indoor,outdoor,pint}.log`. MySQL was initialized in `.runtime/phase8-3b-mysql-ea476e67914e4ca2a309d2bde5f185a2/data`, loopback58083, dedicated smart_kandang_test user/DB; new instance PID3196 verified against datadir/port then shut down with mysqladmin. No operational MySQL service stopped. First MySQL attempt failed auth because Windows empty env password fell back to dotenv; explicit disposable user fixed the harness. Do not copy any operational credentials or weaken DB guard. Test artifacts retained, ignored.

No build/test session needs resuming. Backend integration can pause for Flutter UI using existing schema2 REST with null/blocked/applied distinctions; it cannot be called production IoT ready. Next only after decisions: authenticated broker ACL/identity bridge and approved session/display wire/network senders, isolated broker acceptance, physical NVS/TLS/watchdog/memory/sensor/pin/calibration/relay safety verification. Exact handoff and file list in PHASE8_3_REPORT.md and docs/MQTT_COMPATIBILITY.md. Re-read those before enabling anything; do not reimplement completed software or drain blocked snapshots.

Corrections this phase: inherited Laravel test helper name collision fixed; synthetic BH1750 capability explicitly configured in isolated tests; LCD reported_at normalized from DB timestamp to locked UTC format; stale Eloquent test restoration fixed. Tests/contracts were not weakened.

Historical Phase8.3A evidence below is superseded where noted, not current Git status.

## Historical Phase8.3A checkpoint

26 November 2026 (Asia/Jakarta). Workspace `D:\FlutterProjects\smart-kandang-backend`; branch `feat/phase8-3-firmware`; HEAD/baseline `65a7ef565d88bc11c112789594323802bdd8ae97`. Git status aktual hanya `?? firmware/`, 27 file baru, index kosong, tidak ada tracked backend/locked contract change. Main/dev, Flutter, services/DB/broker operasional dan relays tidak disentuh. Tidak commit/stage/push/reset/clean/merge/deploy/upload.

Instruksi eksplisit Phase8.3 mengotorisasi firmware dalam repo ini, menggantikan pembatasan firmware lama di AGENTS untuk tugas ini. Tidak ada kebutuhan mengulang Phase7/8 backend atau membuat branch baru.

## Pekerjaan selesai

- `indoor_esp32/indoor_esp32.ino`: 5 DHT22 suhu+5 RH per-channel/coherent batch, partial/invalid handling, optional MQ135 raw ADC1, tidak ada average legacy/ppm.
- `outdoor_nodemcu/outdoor_nodemcu.ino`: AHT20 bounded CRC/re-init, BH1750 I2C lux/missing recovery, LCD local atau draft feed dengan target/freshness/unknown handling.
- Shared protocol: exact MQTT v1, QoS1/non-retained, UUID+sequence, UTC/SNTP, bounded reconnect/pending retries, expiry/millis handling; clock expiry dilatch sampai event SNTP baru.
- Draft config scope/version/hash/expiry/replay, independent domains, ESP32 Preferences/readback/corruption recovery; Outdoor RAM-only. ACK hanya Serial acknowledged; tidak ada applied/session/report network fabrication.
- Pure heater/two-fan/gas/lux decisions dan simulator host development. Tidak ada GPIO relay driver, LED pin assignment, custom ON/OFF subscriber atau physical enable flag.
- 45 native/PHP contract tests, 6 machine-readable batch vectors, Arduino setup/hardware/configuration/changelog/testing/backend-gap handoff dan laporan lengkap27-file inventory.

Semua network/pin access/MQ135/LCD/draft flags source default OFF. Build development flags hanya untuk compile coverage, credentials kosong, tidak upload. Temuan/gap B0..B10 tersimpan dalam `docs/BACKEND_GAP_REPORT.md` dengan classification dan required backend tests; tidak memperbaiki backend secara diam-diam.

## Bukti verifikasi final

| Check | Hasil aktual |
| --- | --- |
| Native C++17 Zig0.14.1/ArduinoJson7.4.3 | Compile exit0 |
| Python firmware contract suite | 45 tests PASS, final1.452s, exit0; actual shared C++ vs unchanged PHP validators/fixtures/preview |
| Firmware lock check | 9 fixtures/8 schemas verified; hardware approval=false |
| Final ESP32 development/NVS build | PASS exit0; flash1072828/1310720, RAM58800/327680 |
| Final ESP8266 development/display build | PASS exit0; flash403680/1048576, RAM47760/80192, IRAM61171/65536 |
| Default profiles before last draft-parser/clock optimization | Both PASS; ESP32 flash576472/RAM35964; ESP8266 flash354432/RAM33288/IRAM60115. Do not confuse with final development sizes |
| Pint/PHP lint/clang-format dry-run | PASS |
| git diff --check;27-file UTF-8/trailing whitespace/local links | PASS |
| Tracked baseline comparison/index | No changes; nothing staged |
| Actual broker/hardware/upload/physical NVS tests | NOT RUN |
| Full Laravel SQLite/MySQL regression | Not rerun; backend/locked contracts unchanged. Pure validator bridge does not use DB |

Builds complete, no compilation session needs resuming. Source final development builds include all parser/NVS/MQTT/display paths and final clock-expiry guard. Detailed commands/flags/library versions and results: `PHASE8_3_REPORT.md`, `docs/ARDUINO_SETUP.md`.

Toolchain: CLI1.6.0; ESP32 core3.3.11 existing; ESP8266 core3.1.2 added in Arduino15. Libraries isolated `.runtime/phase8-3-tools/sketchbook`: ArduinoJson7.4.3, MQTT256dpi2.5.2, DHT1.4.6/UnifiedSensor1.1.15, LiquidCrystal_PCF8574 2.2.0. No global IDE config change. Zig0.14.1 and clang-format20.1.8 portable under ignored `.runtime/phase8-3-tools`.

Evidence logs there: `native-build.log`, `contract-tests.txt`, `final-indoor-stack-fix.log`, `final-outdoor-stack-fix.log`, earlier default `final-indoor.log`/`final-outdoor.log`, `stack-parser-final.txt`, `final-source-sha256.json`. Default/draft logs preceding stack fix are historical evidence only.

## Corrections completed, do not restart

- ESP8266 TLS API differs from ESP32: conditional handshake/socket timeout fixed and compiled.
- Native ArduinoJson proxy serialization bug fixed without reducing assertions.
- PHP full app bootstrap once exceeded30s; test bridge now uses minimal Laravel container/in-memory config with unchanged pure services, no `.env`/operational providers/DB/cache. Subprocess stderr drained concurrently; response timeout remains30s.
- ESP8266 parser stack review proved recursive frame912 bytes: replaced key copies with bounded16-bit input spans. Final binary frame240 bytes, no extra heap; reordered/pretty/nested-duplicate regression added. Actual total device stack/heap acceptance still pending.
- Clock freshness now expires permanently until new SNTP notification, avoiding stale trust after a very long outage and millis wrap.

## Next work requires external decisions/acceptance

No known incomplete software-ready task remains within the authorized Phase8.3 scope. Read the report and gap table before a future integration phase; do not enable live features by merely toggling compile flags.

1. Hardware owner verifies real coop/device IDs, sensor allowlists/provenance, per-device ACL/TLS credentials, numeric broker IP/IP SAN, NTP/timeouts.
2. Verify five DHT pins, MQ135 voltage/presence/warmup/filter/calibration, AHT20/BH1750 bus/address/timing, LCD geometry/backpack/registry IDs.
3. Separately authorize isolated broker and board tests. No isolated broker was found in PATH/process; no operational broker was contacted. Test packet/PUBACK/retry/UTC/auth/retain/offline and device watchdog/heap/NVS power-cut.
4. Backend authenticated session/ACK/report ingress, authorized outbox transport and LCD publisher are absent. MQTT callback retain metadata adapter and ESP32 lux/lighting authority remain unresolved. Do not fabricate sessions or applied ACK.
5. GPIO/polarity/boot/interlock/anti-short-cycle/calibrated thresholds/offline authority/physical feedback require hardware sign-off and a separate implementation scope. No physical relay activation exists here.

Required pre-upload settings are listed exactly in report/README/changelog under **YANG HARUS DIUBAH TIM IOT SEBELUM UPLOAD**. Final Indonesian response should state completed software/test/build evidence and these external blockers; do not claim broker or hardware acceptance.
