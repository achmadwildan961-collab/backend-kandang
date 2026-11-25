# Phase 8.2 Backend Readiness Report

25 November 2026. Baseline terverifikasi `50e3f84fba214553fd7ee3ea9b7168b32343f357`; branch `feat/phase8-2-hardware-readiness`, initial working tree bersih. Phase7/8/8.1 source, migrations dan 180 tests dipertahankan. Implementasi backend lokal, simulator dan handoff selesai: full SQLite dan MySQL disposable masing-masing **198 tests /1821 assertions PASS**. Broker nyata dan hardware belum diverifikasi; bukan persetujuan aktivasi produksi.

## CURRENT / TARGET / IMPLEMENTED / DEPENDENCY

| Area | CURRENT baseline | TARGET / IMPLEMENTED Phase8.2 | Risk / dependency tersisa |
| --- | --- | --- | --- |
| Contract | v1 parser + Phase8 draft, dokumen lama bertentangan | Package1.0.0-staging-proposal.1, machine lock topic/sensors, 8 schemas,9 full fixtures/checksums, field reference dan changelog | Firmware acceptance, canonical number encoding dan version integer precision |
| Telemetry | Five temp/humidity, AHT20, BH1750 sudah teruji | Dipakai ulang oleh virtual devices; v1 tetap tidak diganti | Physical IDs/allowlists/time sync/sampling/calibration |
| Config lifecycle | Transactional immutable outbox/lease/retry/ACK/session guarded | Harness config receive/ack/applied/rejected/reboot/lost ACK/crash recovery; no service reimplementation | Authenticated session ingress, actual NVS, offline authority |
| Real MQTT | Production DisabledMqttTransport | Unbound config-only loopback adapter + opt-in smoke command; bounded retry/PUBACK check/TLS support/env credentials/scopes | Broker tidak tersedia untuk live isolated execution; TLS/ACL network tests pending |
| Simulator | Legacy mixed catalog CLI | New preview CLI per board; pure VirtualDevice and actual-services fake harness | Simulated NVS/GPIO bukan firmware atau physical feedback |
| LCD | Six-metric DTO/TTL existing | Schema fixture, virtual NodeMCU receive/target/expiry check, proposed topic | Numeric ID provisioning, layout/address, receiver/publisher acceptance |
| Control | Pure temperature/gas/lux engines | Existing deterministic tests dipertahankan, Arduino behavioral specification dan unknown/fault contract | GPIO/polarity/interlock/fail-safe/minimum runtime/threshold sign-off |
| Push | Token/outbox/provider fake foundation | Explicit provider/mobile handoff, existing tests unchanged | Real provider credentials/mobile consent/token integration/delivery evidence |

## Implemented and tested locally

- `ContractSchema`: strict offline JSON Schema subset, unknown keyword fails; required/types/enums/constants/ranges/nested fields validated. `firmware:contract-check` verifies all checked-in schema/fixture SHA256. LF stored bytes follow repository eol policy; no runtime automatic checksum regeneration.
- `ContractTime`: exact UTC whole/fractional timestamps; rejects invalid dates, offsets, >6 fractional digits and leap-second normalization. Used only new staging/simulator boundary, no v1 parser change.
- `VirtualDevice`: Indoor11 channels (5 temp+5 humidity+gas ADC), Outdoor3 channels (AHT20 temp/humidity+BH1750lux), partial/invalid-unit/stale/out-of-order payloads, status/disconnect/reconnect, configuration domain/hash/version validation, memory NVS, explicit ACK/rejection/applied, boot rollover, monotonically shared feedback sequence, bool|null simulated GPIO, scoped/expiring LCD acceptance. No database/network/GPIO in simulator itself.
- `FirmwareReadinessTest`: registers virtual device in isolated test DB, invokes **existing** TelemetryIngestor/ConfigurationDelivery/ConfigurationDispatcher/DeviceFeedback/IntegrationMonitor/LcdPayload. Covers complete/partial, duplicate, spoofed coop/unknown device, time ordering, offline/reconnect, two boards/config domains, rejected→new-version recovery, retired ACK, same-version collision, expired config/LCD, crashed lease retries stable payload. Actual generator output validated against schema/topic manifest.
- `StagingMqttTransport`: real php-mqtt/client adapter prepared but **not bound** to production MqttTransport. Explicit local/testing/staging environment + staging enabled + virtual_only + literal127.0.0.1 + VIRTUAL_* allowlist/coop + env credentials. Only exact config operational/lighting_lux topic. Unknown command fields/topics, wildcard/scope/hash/key/expiry mismatch, unauthorized retained fail before connecting. Two attempts default/hardcap3, timeout5/cap10, retry bytes/key stable. Checks outgoing queue empty after bounded loop; timeout cannot masquerade as PUBACK. Each connection closed; sanitized structured logs with coop/device/attempt, no payload/credentials/exception chain. Success never writes applied/reported database state.
- `firmware:simulate`: new per-board JSON preview, default no DB/network; production denied. `firmware:broker-smoke`: explicit-gated two-client QoS1 SUBACK/PUBACK + observer-match check, nonretained config; no database/physical command.
- Documentation: firmware contract with every field/type/presence and full JSON, classification/before-after/Arduino steps, NVS/control behavior, mandatory checklist, isolated testing/ACL example, root handoff. Older conflicting MQTT/hardware documents point to current contract while preserving historical text.

## Batas keamanan dan kesiapan

Production bindings remain DisabledMqttTransport and DisabledPushProvider. ConfigurationDispatcher and DeviceFeedback **tetap test-only guarded**, tidak menerima adapter real baru atau membuka session dari payload unauthenticated. Physical command endpoints tetap409 Admin/403 User. No migration, new public HTTP route, operational environment modification, scheduler activation, MQTT v1 contract replacement, or backfill. Existing history/chart/CSV/schema2/RBAC/push tests all retained.

Adapter real disiapkan terpisah karena authenticated device ingress belum disahkan. MQTT callback biasa tidak memberikan authenticated username publisher; registry/topic identity saja tidak cukup. Tidak menambahkan blind session router atau membuka gate agar demo berjalan. Proposed session topic/schema hanyalah staging proposal, tidak ada live session receiver. LCD tetap DTO/virtual receiver, tidak ada broker publisher. Operational config ke Outdoor tidak memberi authority relay. Last-known NVS data boleh dimodelkan, offline control authority masih decision-required.

Semua config/ACK/report wire field yang sudah ada tetap `phase8_draft`, schema1 dan namespace `integration/v1`; package version baru adalah versi handoff, bukan mengganti wire silently. Contract changes setelah firmware acceptance harus versioned/adapter eksplisit. Sensor gas ADC tidak berubah menjadi ppm; ADC light legacy tidak berubah menjadi lux. Enam Dashboard metrics tetap API schema2 Phase8.1, temperature/humidity batch completeness independen dan null unavailable.

## PERUBAHAN BACKEND YANG WAJIB DISAMAKAN DI CODING ARDUINO

Rincian wajib di [firmware changelog](docs/firmware/POULTRIQ_FIRMWARE_CHANGELOG.md):

1. SYNC-01: firmware legacy single indoor_humidity → lima paired DHT22 humidity dalam satu batch. Kanal legacy tetap kompatibel, tidak menjadi official aggregate.
2. SYNC-02: MQ135 pada Indoor raw ADC; BH1750 Outdoor lux; AHT20 temp/humidity dengan explicit mapping. Hapus asumsi gas outdoor/ppm/LDR conversion.
3. SYNC-03: exact versioned operational/lighting_lux config fields, scope/hash/expiry, separate versions, validated NVS before applied. min/max adalah indoor.
4. SYNC-04: scoped UUID/boot/sequence ACK/report, broker receipt berbeda dari device application dan GPIO/physical feedback.
5. DECISION-01/02: authenticated session enrollment dan LCD wire/provisioning/layout belum disahkan. Tidak ada firmware yang sudah diubah/diapprove. NO_FIRMWARE_CHANGE: backend lock/tests/new CLI/isolated adapter dan existing parser/gates.

SYNC items merekonsiliasi firmware lama dengan kontrak Phase7/8/8.1, bukan breaking wire baru Phase8.2. Setiap item memuat previous/new/reason/board/topic/fields/before-after/publisher-parser responsibilities/compatibility tests/backward safety/approval required. Arduino parser memory/number formatting/buffer/TLS stack harus diuji oleh tim firmware sendiri.

## Bukti pengujian final

| Jenis | Hasil |
| --- | --- |
| Pure unit suite terpisah | **6 tests /193 assertions PASS**, 00:00.099; includes2 new timestamp unit tests |
| Focused new contract + virtual/fake + mocked adapter + time | **18 tests /141 assertions PASS**, 00:03.502 |
| Full SQLite | **198 tests /1821 assertions PASS**, 00:29.916 |
| Focused MySQL disposable | **18 tests /141 assertions PASS**, 00:10.799 |
| Full MySQL disposable | **198 tests /1821 assertions PASS**, 02:18.806 |
| Schema/fixtures CLI | **9 fixtures /8 schemas PASS** |
| Preview CLI tanpa DB/network | Indoor11 /Outdoor3 channels; simulated=true, physical_feedback=false |
| Pint seluruh PHP baru / git diff --check | **PASS / PASS** |
| Real isolated broker | **NOT RUN**, no mosquitto/docker binary/process available |
| Physical ESP32/NodeMCU, relays, actual NVS | **NOT PERFORMED** |
| Real push/mobile delivery, broker TLS/ACL network verification | **NOT PERFORMED** |

New18 tests terdiri11 feature contract/virtual fake harness,5 mocked MQTT client transport tests,2 pure timestamp unit tests. Unit suite total6 juga termasuk4 pure decision-engine baseline tests. Counts di tabel saling mencakup, jangan dijumlah menjadi jumlah tes unik; full suite198 adalah180 existing+18 new. Mocked connect/PUBACK/error/retry tests bukan real broker verification. No failures observed pada runs recorded ini; focused increments15/123,16/133 lalu18/141 karena penambahan test yang relevan, bukan weakening old tests.

Log `.runtime/phase8-2-{focused-final,unit-final,sqlite-final,mysql-focused,mysql-final,contract-check}.txt`. PHP8.3.30 / PHPUnit12.5.43. Tests isolate SQLite :memory: dan dedicated MySQL smart_kandang_test. Broker smoke tidak dijalankan terhadap endpoint apa pun; hanya failure-before-network production guard diuji secara otomatis. Local setup tersedia pada [LOCAL_TESTING](docs/firmware/LOCAL_TESTING.md) dan exact-topic ACL di deploy/mosquitto.virtual.acl.example; references resmi Mosquitto ditautkan di dokumen tersebut.

MySQL 8.4.3 disposable terisolasi pada127.0.0.1:58663, root `.runtime/phase8-2-mysql-6f3c9b755bbc409a88c13dda45acc54a`; port/proses/@@datadir diverifikasi sebelum tests. Pemeriksaan final25 November2026 23:31:23 WIB menemukan **0 matching processes /0 listeners**. Log server tidak mencatat `Shutdown complete`; normal shutdown tidak dapat dibuktikan setelah interupsi. Instance tidak dihidupkan ulang atau dipaksa berhenti; service operasional tidak disentuh. Datadir/log dipertahankan ignored, bukti final di `.runtime/phase8-2-mysql-final-state.json`.

## Pending dan tidak diimplementasikan

Hardware/security sign-off: physical board IDs, five DHT22 timing/pins, AHT20/BH1750 I2C address/voltage/pull-up/mode, MQ135 ADC calibration/filter/safe thresholds, single automatic controller, relay polarity/GPIO/interlock/anti-short-cycle/fail-safe, time synchronization, authenticated session protocol, NVS durability/readback/expiry, genuine physical feedback, LCD geometry/provisioning. Broker availability/TLS/ACL test environment dan real push credentials/mobile integration juga dependency eksternal, tidak diklaim hardware-only semuanya.

Tidak diimplementasikan/diaktifkan: physical command transport, real authenticated session/ACK subscriber, production configuration delivery, LCD publication, actual firmware/NVS, real push adapter/mobile delivery, remote broker support, production deploy/migration, stress/chaos concurrency. Existing interfaces and safe simulation prepare these boundaries; separate acceptance is mandatory. Scope software-safe adapter/harness/contract can finish without inventing these decisions.

## Git / file inventory

HEAD tetap50e3f84fba214553fd7ee3ea9b7168b32343f357, branch feat/phase8-2-hardware-readiness; index kosong. **4 tracked modified +39 untracked files =43 files.** Tracked docs changed: AGENTS.md, HARDWARE_MQTT_CONTRACT.md, PHASE3_MQTT_INTEGRATION.md, POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md. New: three root Phase8.2 docs; three console commands; six Firmware services; config/firmware.php; lock+8schemas+9fixtures; four docs/firmware files; virtual ACL; three test files.

Tidak mengedit repository lain, Flutter, Arduino atau firmware; tidak commit/stage/push/merge/reset/clean/deploy; tidak mengakses operational database/service/broker/push/relay. Phase7/8/8.1 source/migrations/tests dipertahankan byte-for-byte pada tracked files (hanya historical MQTT compatibility document ditambah notice).
