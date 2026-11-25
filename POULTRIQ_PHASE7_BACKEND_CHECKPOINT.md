# Checkpoint Phase 7 Backend

> Status terbaru: lihat **Verifikasi kelanjutan — 25 November 2026** di akhir dokumen. Implementasi lokal telah selesai; bagian sebelumnya dipertahankan sebagai riwayat.

Tanggal: 24 November 2026. Branch: `feat/phase7-backend-contract`. HEAD: `f58e5a995a298a1664e16372d4d57ea5b6f4370f`.

## Tahap 1 — audit selesai

- Baseline backend sesuai commit yang diminta; perubahan awal hanya `?? AGENTS.md`, dipertahankan. SHA256: `6414B063736C7CD26BD9562CEA7D61325B1E117DF9929E1B5D8736D0CF7C5EAE`.
- Flutter bersih, HEAD `117861f325c4d47b9e9325f241fc375c3beb7d52`; hanya dibaca. Kedua AGENTS dan gap audit telah dibaca.
- Tidak ada commit/stage/push/deploy, migrasi operasional, koneksi broker, atau aktuator.

| CURRENT | TARGET / GAP | RISIKO / DEPENDENCY | MIGRASI YANG DIPILIH |
| --- | --- | --- | --- |
| Minimum outdoor dipakai alert | Minimum indoor eksplisit | Nilai lama bukan ambang indoor aman | Kolom nullable baru, tanpa backfill; outdoor legacy tetap tersimpan, alert outdoor retired |
| Latest menggabungkan sensor fresh lintas waktu | Lima sensor satu batch/device/waktu | Partial dapat terlihat lengkap | Identitas batch ingest baru, validasi konsisten latest/chart, legacy exact-time single-device diberi provenance |
| Settings tanpa versi | Compare-and-swap atomik | Draft lama menimpa nilai baru | Versi server + expected_config_version; 409 stale, 428 write tanpa precondition; field/read legacy tetap tersedia |
| Fixture dua ESP32, gas outdoor | Indoor ESP32 / outdoor NodeMCU | ID riil belum terpetakan | Perbaiki metadata default fixture saja; tidak rename/update registered ID |
| Tidak ada delta tervalidasi | Indoor average minus outdoor | Toleransi pairing belum disahkan | Exact timestamp saja sebagai batas konservatif; unavailable di luar itu |
| MQTT v1 aktif, lighting blocked | Konfigurasi versioned + outbox + ACK | Firmware/ACL/units belum disahkan | Outbox operasional blocked transaksional; rencana kompatibilitas, tanpa publisher live |
| Belum ada kontrol aman | Demand suhu OR gas | Interlock/fault/recovery belum jelas | Hanya fungsi demand terisolasi bila aman; aktuator dan lighting tetap disabled |

## Rencana verifikasi

PHPUnit langsung dengan SQLite `:memory:`, tanpa config cache; baseline terfokus lalu tes tiap tahap dan full regression akhir. MySQL hanya bila instance disposable terisolasi tersedia (belum tersedia/terverifikasi). Jangan gunakan koneksi MySQL operasional.

## Status saat ini

- Implementasi: belum dimulai; branch lokal dibuat.
- Tes: belum dijalankan.
- File baru: checkpoint ini; AGENTS.md adalah file pengguna yang sudah ada.
- Berikutnya: baseline targeted tests; tahap 2 migrasi minimum indoor + alert retirement + tes, kemudian batch, versioning, fixtures, delta, outbox, kontrol dan laporan.
- Hardware pending: identitas fisik, lima humidity, kalibrasi gas/LDR, timing, relay interlock/fault/hysteresis, NVS/ACK, topic/ACL/TLS, LCD, provider push.

## Tahap 2 ? selesai dan teruji

- Migrasi additive minimum_indoor_temperature nullable, model/request/resource/controller, validasi merged-state min < max, low_indoor_temperature, filter event dan preferensi notifikasi diperbarui.
- Outdoor hanya reference; state/event historis tidak dihapus atau diberi recovery palsu. Nilai legacy tetap tersimpan dan dapat dibaca.
- Run awal beririsan dengan edit sehingga bukan baseline murni: 54 tes, 217 assertions, 2 kegagalan ekspektasi alert outdoor retired. Ekspektasi lama diselaraskan dengan kontrak baru dan tes baru retirement/preservasi ditambahkan.
- Verifikasi tahap 2: IndoorThresholdContractTest, MonitoringApiTest, OperationalSettingsAuthorizationTest, MqttIngestionTest: **52 tests, 212 assertions, PASS** (SQLite memory).
- Kegagalan tersisa: tidak ada pada tes terfokus. Berikutnya: identitas batch MQTT additive, konsistensi latest/chart/CSV, tes asynchronous/split-device/partial/invalid.

## Tahap 3 ? selesai dan teruji

- sensor_readings.batch_key nullable menyimpan hash message_id untuk ingest v1 baru, scoped coop/device/timestamp. Tidak mengarang backfill identitas pesan historis.
- Official latest mensyaratkan lima nilai fresh valid, timestamp/device/batch sama. Partial tetap diagnostik. Invalid terbaru menonaktifkan aggregate sambil mempertahankan last-valid value diagnostik (tes perilaku lama disesuaikan secara eksplisit).
- Chart dan CSV memakai syarat device/batch yang sama; history mentah tetap tersimpan. Legacy memakai exact timestamp + single device dan provenance legacy_exact_timestamp; bukan klaim identitas pesan historis terverifikasi.
- Humidity diberi metadata legacy_single_channel/five_sensor_verified=false; tidak ditambah kanal fiktif.
- Tes tahap 3: SensorBatchContractTest + MonitoringApiTest + MqttIngestionTest + ChartHistoryTest + CsvExportTest + IndoorThresholdContractTest: **66 tests, 281 assertions, PASS**.
- Berikutnya: config_version, expected_config_version, CAS, 409/428, transactional rollback dan RBAC tests. Kegagalan terfokus: tidak ada.

## Tahap 4 ? selesai dan teruji

- config_version default 1; write membutuhkan expected_config_version. Conditional SQL CAS di dalam transaksi dan lock coop, stale 409 dengan current settings; missing precondition 428. No-op tidak menaikkan versi. Validasi merged threshold, audit before/after/version, preferensi dan RBAC tetap berlaku.
- GET dan nama field lama dipertahankan; write klien lama perlu adapter versi (sengaja ditolak 428 agar draft usang tidak menimpa). Status stored dipisahkan dari delivery disabled/device_applied=false.
- Tes SettingsVersionContractTest + IndoorThresholdContractTest + MonitoringApiTest + OperationalSettingsAuthorizationTest + CoopAccessTest: **25 tests, 188 assertions, PASS**. Rollback settings/version/audit diuji; dua draft satu versi diuji, bukan klaim stress test konkuren MySQL.
- Berikutnya: koreksi default fixture board/gas tanpa mengubah ID existing, lalu delta exact-time. Kegagalan terfokus: tidak ada.

## Tahap 5 ? selesai dan teruji

- Default nama/type fixture baru: Indoor ESP32 DevKit dan Outdoor NodeMCU ESP8266. ID development legacy dipertahankan; firstOrCreate tidak memperbarui metadata registrasi existing. Gas fixture sekarang mengikuti device indoor.
- SeederTest + CoopAccessTest: **6 tests, 61 assertions, PASS**; idempotensi, preservation existing, device gas/outdoor dan larangan seed production diverifikasi.
- Berikutnya: delta exact-time fresh/valid; tidak menetapkan toleransi hardware yang belum disahkan.

## Tahap 6 ? selesai dan teruji

- Latest menambah temperature_comparison: delta_temp, available, unavailable_reason, kedua timestamp dan pairing_policy=exact_timestamp. Hanya batch indoor lengkap + outdoor valid fresh pada timestamp sama; selisih microsecond ditolak.
- TemperatureComparisonTest + SensorBatchContractTest + ChartHistoryTest + CsvExportTest: **18 tests, 90 assertions, PASS**. Delta bertanda positif/negatif dan unavailable diperiksa. Chart/history/CSV tidak diubah formatnya.
- Berikutnya: outbox operasional blocked transaksional dengan scope device/coop/version, idempotency; rencana MQTT v1/v2 dan retry/ACK/offline. Tidak ada publisher live atau activation switch yang akan ditambahkan.

## Tahap 7 ? selesai dan teruji sesuai batas software

- operational_configuration_outbox additive, scope FK device/coop, unique target/version + key idempotensi, internal payload draft, blocked permanen tanpa publisher/worker. Save settings + versi + outbox + event + alert satu transaksi.
- POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md merinci enam target topic, transisi v1/v2, retry/crash/idempotency, ACK/reported per device, offline/NVS dan blocker. Live delivery/ACK tetap NOT IMPLEMENTED.
- Run gabungan 57 tests/297 assertions: 1 kegagalan asumsi test ordering baris (bukan data berubah); test diperbaiki memakai primary ID. Retest OperationalConfigurationOutboxTest: **3 tests, 39 assertions, PASS**. 54 tes lain pada run gabungan lulus, termasuk MQTT v1 transport mock dan lighting disabled.
- Berikutnya: pure demand preview suhu + OR gas, tanpa wiring API/relay. Kemudian checkpoint sebelum full regression dan final audit.

## Tahap 8 ? selesai dan teruji sesuai batas software

- ControlDemandPreview adalah fungsi murni terisolasi, tidak wired ke API/job/MQTT. Batas suhu strict, OR gas, input unknown/nonfinite/unconfigured => unavailable, actuator_commands=null dan automation_enabled=false selalu.
- Lighting lamp terpisah, belum ada interlock/fallback/hysteresis/konversi gas/lux fiktif. Pure preview tidak membuktikan kontrol fisik.
- ControlDemandPreviewTest + LightingFoundationTest: **9 tests, 168 assertions, PASS**.

## Sebelum tahap 9 ? full regression dan final audit

- Tahap 2?8 sudah lulus tes terfokus masing-masing; tidak ada critical failure tersisa.
- Berikutnya: full PHPUnit SQLite memory, Pint check file berubah, diff review migration/API/aggregation/outbox, checksum AGENTS, status Flutter/backend, final report dan inventaris file.
- MySQL disposable belum tersedia (docker tidak ditemukan); tidak menyentuh MySQL operasional. Uji konkurensi nyata/MySQL-specific tetap PENDING VERIFICATION.

### Audit tahap 9 ? hasil awal dan tindak lanjut

- Full SQLite: **112 tests, 992 assertions, PASS**. Pint menemukan 6 file dengan format/line ending; telah diperbaiki hanya pada file yang berubah.
- SHA256 AGENTS identik baseline; Flutter status bersih dan HEAD tetap 117861f; index backend kosong.
- Ditemukan binary MySQL 8.0.30/8.4.3 di Laragon (Docker tidak ada). Akan menyiapkan instance disposable pada direktori .runtime baru dan port loopback khusus, tanpa membaca config operasional.
- Review menambahkan guard rollback agar minimum terisi, versi yang sudah berubah, batch accepted, dan outbox intent tidak bisa dihapus diam-diam. Tes DDL dipisah dari transaksi fixture supaya valid untuk MySQL. History raw juga mengekspose batch_key diagnostik.
- Berikutnya: migration guard/roundtrip tests, isolated MySQL setup, full regression setelah perubahan ini, lint dan laporan final.

### MySQL disposable terverifikasi

- Binary aktual tersedia: MySQL 8.4.3; folder 8.0.30 tidak memiliki mysqld. Instance baru --no-defaults, bind 127.0.0.1, port 59134, mysqlx disabled, data `.runtime/phase7-mysql-c34290c5d3da471e9e8d71f0f89cd5be/data`. PID child 13860 diverifikasi dengan parent, command line, listener dan @@datadir sebelum CREATE DATABASE smart_kandang_test.
- Percobaan koneksi pertama: 28 errors/0 assertions (password env kosong dihapus PowerShell, fallback .env). Tidak menyentuh server lain; host/port tetap instance disposable. Diperbaiki dengan DB_PASSWORD=(empty), DB_URL=(null), DB_SOCKET=(empty) untuk menutup fallback.
- MySQL targeted Phase7MigrationTest, SettingsVersionContractTest, OperationalConfigurationOutboxTest, SensorBatchContractTest, TemperatureComparisonTest, ChartHistoryTest, CsvExportTest: **28 tests, 188 assertions, PASS**.
- Guard rollback + roundtrip tambahan SQLite: **5 tests, 36 assertions, PASS**. Tes DDL tidak dibungkus transaksi agar MySQL auto-commit tidak mencemari suite.
- Berikutnya: full SQLite + full MySQL pada tree final, Pint test/diff check, laporan lengkap, shutdown disposable MySQL. Tidak ada migrasi operasional/live broker.

## FINAL ? tahap 9 selesai

- Full tree final SQLite: **113 tests, 1006 assertions, PASS** (42.787s).
- Full tree final disposable MySQL 8.4.3: **113 tests, 1006 assertions, PASS** (57.177s).
- Pint seluruh PHP modified/new: PASS. git diff --check: PASS. Tidak ada known failure tersisa.
- MySQL disposable menerima normal shutdown setelah suite, log/data hanya di .runtime ignored. Koneksi operasional tidak digunakan.
- AGENTS SHA256 identik awal. Flutter bersih pada HEAD 117861f325c4d47b9e9325f241fc375c3beb7d52. Backend branch feat/phase7-backend-contract, HEAD f58e5a995a298a1664e16372d4d57ea5b6f4370f, index kosong.
- File berubah: 16 tracked modified + 17 baru; AGENTS untracked bawaan dipertahankan. Inventaris lengkap ada dalam POULTRIQ_PHASE7_BACKEND_REPORT.md.
- Laporan final: POULTRIQ_PHASE7_BACKEND_REPORT.md. MQTT plan: POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md.
- Remaining backend local work: tidak ada dalam scope software-ready Phase 7. Hardware-dependent transport/ACK/NVS/calibration/control/lighting/LCD/push tetap NOT IMPLEMENTED/PENDING VERIFICATION seperti laporan.
- Exact next actions untuk fase berikutnya: Flutter adapter minimum indoor nullable + config_version/expected_config_version + 409/428 reconciliation; display batch/delta/status stored-vs-applied; kemudian staging/hardware acceptance. Jangan deploy atau mengaktifkan automation hanya berdasarkan hasil suite ini.
- Tidak ada commit/stage/push/merge/PR/deploy, production migration, firmware edit atau live MQTT/actuator command.

## BH1750 Sensor Contract Revision — 24 November 2026

### Pemulihan dan cleanup — selesai

- Branch/HEAD sama: feat/phase7-backend-contract / f58e5a995a298a1664e16372d4d57ea5b6f4370f. Semua pekerjaan Phase 7 sebelumnya masih di working tree; tidak direset/reimplementasi.
- MySQL disposable lama port 59134 tidak memiliki listener atau proses dengan datadir khususnya. Log mencatat Shutdown complete. Tidak ada MySQL operasional yang dihentikan.
- Kedua AGENTS, audit arsitektur, checkpoint, report dan source lighting/migration/test dibaca kembali. Hasil 113/1006 sebelumnya adalah historis, bukan verifikasi revisi BH1750.
- Kedua AGENTS diperbarui atas instruksi pemilik; Flutter hanya dokumentasi. Hash 253 file Flutter non-dokumentasi tersimpan di .runtime/phase7-bh1750-flutter-source-baseline.json.

### Audit dan keputusan migrasi

| CURRENT | TARGET / GAP | RISK / DEPENDENCY | Tindakan |
| --- | --- | --- | --- |
| LEGACY outdoor_light_adc, LDR A0 | BH1750 I2C, lux, NodeMCU | Firmware/binding/timing belum disahkan | outdoor_light_lux terpisah, gate mapping NodeMCU dan verification, tanpa konversi ADC |
| Sensor readings sudah punya outdoor_light + unit + measurement_version | Lux metadata dan validasi | Jangan ubah migration lama | Gunakan skema reading yang sudah cukup; katalog/ingest/resource diperluas |
| dark/bright ADC, mode automatic/manual | min_lux/max_lux, auto/manual | Backfill mengubah makna nilai lama | Tabel lux settings/outbox terpisah, nullable/unconfigured, explicit schema 2 |
| Default GET lighting schema 1 dipakai Flutter Phase 6 | Konsumsi lux schema 2 | Silent switch dapat menyesatkan decoder | GET ?schema_version=2, PUT schema_version=2; legacy schema 1 tetap tersedia berlabel deprecated |
| Lighting commands selalu blocked | Pure hysteresis | State/fault/ACK/relay belum sah | Fungsi murni testable, tanpa jalur live; commands false/409 |

Rentang software awal 0–65535 lux berdasarkan ROHM BH1750FVI Technical Note Rev.D (normal advertised 1–65535 lx dan dark 0 lx); extended sensitivity sampai 100000 lux tidak diterima tanpa kontrak mode/measurement version baru. Sumber: https://www.mouser.com/datasheet/2/348/bh1750fvi-e-186247.pdf. Ini validasi domain, bukan safe lighting threshold.

Berikutnya: telemetry BH1750 + isolation latest/history/chart; tes terfokus; additive lux config/outbox + hysteresis; tes terfokus; full SQLite/MySQL disposable baru; laporan/addendum akhir. Firmware, actual measurements, timing/device identity, alamat 0x23/0x5C, voltage/pull-ups/shared I2C serta relay feedback tetap pending.

### Tahap BH1750 telemetry — selesai dan teruji

- SensorCatalog menambah outdoor_light_lux (BH1750/outdoor_light/lux/0–65535); PayloadValidator mempertahankan envelope v1 dan hanya mengizinkan optional unit=lux pada identifier baru. Ingest memeriksa gate terpisah serta registered NodeMCU dan menyimpan measurement_version bh1750_lux_v1.
- Bh1750Telemetry menyatukan source binding/freshness/validity untuk latest; chart menambah seri lux terpisah dari ADC. History raw ADC masih bisa difilter saat legacy ingest gate mati. Seeder tidak membuat pembacaan BH1750 fiktif. DeviceResolver dan subscriber topic tetap tidak diubah.
- Bh1750TelemetryTest + MqttIngestionTest + LightingFoundationTest + ChartHistoryTest + CsvExportTest + SeederTest: **60 tests, 320 assertions, PASS (SQLite)**.
- Berikutnya: migration baru tabel lux settings/outbox, explicit lighting schema 2, CAS/draft status/hysteresis, regression lighting. Tidak ada critical failure pada run ini.

### Tahap BH1750 lighting foundation — selesai dan teruji

- Migration additive 000004 membuat coop_lighting_lux_settings dan lighting_lux_configuration_outbox; tidak mengubah migration lama atau backfill ADC. Rollback populated lux draft/intent diblokir. Phase7MigrationTest kini secara eksplisit menguji empat migrasi awal; BH1750MigrationTest menguji tambahan ini pada DB terisolasi tanpa transactional DDL.
- GET lighting ?schema_version=2 dan PUT settings schema_version=2 memakai auto/manual, min_lux/max_lux nullable berpasangan, min < max, threshold_unit lux, bh1750_lux_v1 dan expected version. Separate CAS namespace mulai 0, no-op idempotent, 409 current, atomic settings/outbox/audit. Legacy schema 1 tetap tersedia dengan deprecated metadata.
- Draft storage software tersedia tanpa mengklaim firmware verified. configuration=false, commands=false, applied null/false dan delivery blocked; tidak ada device ID actuator fiktif. Pure LightingDemandPreview menghitung hysteresis strict/retain boundaries; unknown/fault/manual tetap unavailable, tanpa relay commands.
- Bh1750Devices memakai pemeriksaan case-sensitive yang sama pada SQLite/MySQL, type NodeMCU dan kedua allowlist (khusus BH1750 + optional MQTT global).
- Run awal lighting: 23 tests/327 assertions PASS. Run setelah tambahan migration preservation dan binding/reorder test: **26 tests, 345 assertions, PASS (SQLite)**.
- Berikutnya: Pint, full SQLite, full MySQL disposable baru; periksa diff/source Flutter hashes, lengkapi laporan dan inventory final; shutdown instance test setelah selesai. Tidak ada critical failure tersisa.

### Penutupan revisi BH1750 — selesai lokal

Tahap lanjutan di atas selesai. Semua pekerjaan Phase 7 sebelum revisi dipertahankan; dua AGENTS dan addendum audit Flutter sudah diperbarui. Rincian API, file, compatibility, batas software dan kebutuhan Flutter ada di bagian revisi final `POULTRIQ_PHASE7_BACKEND_REPORT.md`. MQTT compatibility juga sudah diperbarui sesuai implementasi, tanpa mengganti subscriber/topik v1.

Hasil baru: full SQLite **130 tests / 1267 assertions PASS** (11.461 detik), full MySQL 8.4.3 disposable **130 tests / 1267 assertions PASS** (36.214 detik). PHP 8.3.30, PHPUnit 12.5.43. Laravel Pint pada seluruh PHP changed/new dan `git diff --check` kedua repo PASS. Log `.runtime/phase7-bh1750-sqlite-final.txt` dan `.runtime/phase7-bh1750-mysql-final.txt`. Tidak ada kegagalan tersisa. Hasil 113/1006 lama adalah sejarah, bukan bukti revisi ini.

Cleanup: instance lama port 59134 sudah berhenti sebelum revisi. Instance baru dedicated port 52752, parent PID 14520/child 18132, datadir `.runtime/phase7-bh1750-mysql-7fa7552ab2ac4fd5b53f8a7458edca59/data` sudah shutdown normal. Identitas PID/port/command-line/datadir diperiksa sebelum shutdown; verifikasi akhir **0 proses dan 0 listener**, log `Shutdown complete` 2026-11-24 15:57:46 UTC. Tidak menyentuh MySQL operasional. Descriptor, data dan log tetap ignored di `.runtime`.

Backend branch/HEAD tetap `feat/phase7-backend-contract` / `f58e5a995a298a1664e16372d4d57ea5b6f4370f`: **21 tracked modified, 26 untracked**, index kosong. Inventaris tracked modified: LightingController, SettingsController, HistoryRequest, SettingsRequest, CoopSettingResource, SensorReadingResource, CoopSetting, AlertEvaluationService, EventRecorder, LightingContract, Mqtt/PayloadValidator, Mqtt/TelemetryIngestor, SensorAggregationService, SensorCatalog, SensorHistoryService, config/lighting.php, DatabaseSeeder, ApiTestCase, MonitoringApiTest, MqttIngestionTest, SeederTest.

Inventaris untracked: AGENTS.md; tiga dokumen POULTRIQ_PHASE7_BACKEND_CHECKPOINT/BACKEND_REPORT/MQTT_COMPATIBILITY; enam services Bh1750Telemetry, ControlDemandPreview, LightingDemandPreview, LuxLightingSettings, OperationalConfigurationOutbox, TemperatureComparison; lima additive migrations 2026_11_24_000000 sampai 000004; sembilan feature tests Bh1750LightingSettings, Bh1750Migration, Bh1750Telemetry, IndoorThresholdContract, OperationalConfigurationOutbox, Phase7Migration, SensorBatchContract, SettingsVersionContract, TemperatureComparison; dua unit tests ControlDemandPreview, LightingDemandPreview. Tidak ada file Phase 7 yang dihapus.

Flutter branch/HEAD tetap `feat/phase6-ui-language-experiment` / `117861f325c4d47b9e9325f241fc375c3beb7d52`, index kosong; hanya AGENTS.md dan POULTRIQ_ARCHITECTURE_GAP_AUDIT.md modified. SHA256 **253 file di luar dua dokumen identik baseline, 0 mismatch**. Tidak mengubah Dart/firmware. Tidak ada stage/commit/push/merge/reset/clean/PR/deploy, migrasi produksi, live broker atau relay.

Tidak ada pekerjaan software/backend yang masih berjalan. Jika dilanjutkan: baca bagian revisi final laporan; integrasi Flutter membutuhkan schema 2 selector, nullable min/max lux, independent draft version, 409 reconcile, metadata freshness dan stored-versus-applied. Verifikasi firmware BH1750, actual lux/timing/identity, alamat/tegangan/pull-up/shared I2C, threshold operasional, ACK/feedback dan safety acceptance tetap pending. Gate telemetry default false, kedua outbox operasional/lux blocked, physical commands false/409; jangan mengaktifkan transport atau drain backlog dari hasil tes ini.

## Verifikasi kelanjutan — 25 November 2026

Pemulihan berdasarkan source aktual, bukan asumsi pekerjaan terputus: kedua AGENTS, checkpoint/report, branch/HEAD/status, diff PHP, lima additive migrations serta tes telemetry/settings/migration/hysteresis diperiksa. Implementasi BH1750 dan Phase 7 sebelumnya sudah lengkap; tidak ditemukan task implementasi backend yang perlu diulang. Source, migration, tes, firmware, Flutter Dart dan `deploy/smart-kandang-mqtt.service` tidak diubah pada sesi kelanjutan ini.

Kedua descriptor disposable MySQL diperiksa kembali: port 59134 dan 52752 masing-masing **0 listener dan 0 proses yang cocok dengan dedicated datadir**; log keduanya mencatat `Shutdown complete`. Tidak ada proses yang dihentikan atau instance MySQL yang dinyalakan pada sesi ini. Log MySQL revisi sebelumnya dibaca langsung dan berisi PASS 130 tests / 1267 assertions; ini hasil 24 November, bukan run baru 25 November.

Verifikasi sesi ini: Pint seluruh PHP changed/new PASS; `git diff --check` kedua repository PASS; SHA256 253 file Flutter di luar dua dokumen revisi identik baseline (0 mismatch). Full PHPUnit baru pada SQLite `:memory:` **PASS 130 tests / 1267 assertions**, 01:21.628, PHP 8.3.30 / PHPUnit 12.5.43, exit code 0. Lingkungan eksplisit `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=(null)` dan tanpa config cache. Log `.runtime/phase7-bh1750-resume-2026-11-25-sqlite.txt`. Tidak ada kegagalan tersisa atau proses tes yang masih berjalan.

Branch/HEAD dan inventaris tetap sama dengan penutupan 24 November: backend 21 tracked modified + 26 untracked, Flutter hanya AGENTS.md dan audit arsitektur modified. Kedua index kosong. Sisa lintas fase: adapter Flutter schema 2/lux/nullable thresholds/409/freshness/stored-versus-applied serta acceptance firmware, sensor aktual, I2C, device identity, ACK/feedback dan safety. Command tetap disabled; tidak ada stage/commit/push/deploy, operational DB atau live MQTT yang disentuh.
