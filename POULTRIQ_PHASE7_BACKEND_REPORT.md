# Poultriq Phase 7 — Backend Contract Alignment

> Bagian awal laporan berikut adalah hasil Phase 7 **sebelum revisi BH1750**, dipertahankan sebagai sejarah. Pernyataan LDR, status AGENTS dan Git pada bagian awal bersifat historis. Lihat bagian **BH1750 Sensor Contract Revision — 24 November 2026** untuk target dan hasil terbaru.

Tanggal: 24 November 2026. Implementasi lokal pada branch `feat/phase7-backend-contract`, HEAD tetap `f58e5a995a298a1664e16372d4d57ea5b6f4370f`. Flutter tetap `117861f325c4d47b9e9325f241fc375c3beb7d52`.

## IMPLEMENTED

| Area | Sebelum | Implementasi Phase 7 / target yang dicapai |
| --- | --- | --- |
| Minimum temperatur | `minimum_outdoor_temperature` memicu alarm outdoor | `minimum_indoor_temperature` nullable, tanpa backfill; minimum dan maksimum indoor divalidasi bersama |
| Outdoor AHT20 | Referensi sekaligus alarm minimum | Referensi saja; rule `low_outdoor_temperature` retired, nilai/event/state historis tetap ada |
| Latest lima DHT22 | Lima nilai fresh dapat berasal dari waktu berbeda | Harus lima valid fresh, satu timestamp, device dan batch; partial tetap diagnostik |
| History/chart/CSV | Exact timestamp tanpa identity pesan/device lengkap | Official aggregate juga mensyaratkan single device dan batch; history mentah tetap tersimpan |
| Settings | Lock transaksi tanpa precondition versi klien | `config_version`, expected version, conditional SQL CAS, 409 stale dan 428 missing version |
| Konfigurasi device | Belum ada outbox operasional | Draft outbox per coop/device/version dibuat dalam transaksi save; selalu blocked, delivery disabled |
| Fixture | Dua ESP32, gas pada device outdoor | Default metadata indoor ESP32 DevKit / outdoor NodeMCU ESP8266, gas indoor; ID dan metadata registrasi existing tidak dimigrasikan |
| Perbandingan temperatur | Tidak ada delta tervalidasi | `temperature_comparison.delta_temp` hanya untuk pasangan valid fresh exact timestamp |
| Kontrol | Belum ada demand engine | Pure `ControlDemandPreview`, strict temperature boundaries + OR gas; tidak terhubung ke aktuator |

### Database dan rencana migrasi

Empat migration additive baru, berurutan:

1. `2026_11_24_000000_add_indoor_minimum_to_coop_settings.php`: decimal nullable, default null. Tidak mengambil nilai minimum outdoor lama dan tidak menetapkan angka minimum aman.
2. `2026_11_24_000001_add_sensor_batch_identity.php`: `sensor_readings.batch_key` nullable. Ingest v1 baru menyimpan hash message_id; identitas efektif mencakup coop/device/timestamp. Tidak mengarang ID pesan data lama.
3. `2026_11_24_000002_add_operational_config_version.php`: unsigned bigint `config_version`, default 1, untuk existing/new settings.
4. `2026_11_24_000003_create_operational_configuration_outbox.php`: composite FK device/coop, unique target/version dan idempotency key, payload JSON internal, status blocked/reason. Tidak ada publisher atau konfigurasi topic live.

Migrasi hanya dijalankan dalam SQLite memory dan MySQL disposable pada sesi ini. Rencana rilis kelak: backup dan inventaris data; validasi ulang pada staging; koordinasikan adapter Flutter; jalankan migration additive sebelum aplikasi baru; cek null minimum, versi awal, legacy history, RBAC dan telemetry; kemudian Admin mengisi minimum indoor yang disahkan. Jangan menjalankan ulang development seeder untuk memperbaiki perangkat produksi.

`down()` menolak rollback bila minimum indoor sudah terisi, accepted batch identity ada, config_version sudah >1, atau outbox berisi intent. Rollback kosong diuji. Setelah ada data Phase 7, gunakan forward migration yang direview; jangan menghapus data supaya rollback lolos. Rollback executable lama juga dapat mengaktifkan rule outdoor lama, sehingga perlu keputusan operasional terpisah.

### API settings: endpoint yang sama

`GET/PUT /api/v1/coops/{coop}/settings` tetap dipakai. `contract_version=2` adalah versi kontrak isi respons, bukan route MQTT atau endpoint REST baru.

- Field baru: `minimum_indoor_temperature`, `indoor_minimum_configured`, `config_version`, `requires_expected_config_version=true`, `legacy_outdoor_minimum_alert_enabled=false`, `configuration_status`.
- Nama lama `minimum_outdoor_temperature`, `maximum_indoor_temperature`, `gas_threshold`, `notification_preferences`, `updated_at` tetap tersedia. Minimum outdoor hanya nilai legacy tersimpan, tidak dipakai alert/control. Mengubahnya tetap audited/versioned.
- PUT tetap meminta maksimum indoor dan gas threshold; minimum outdoor sekarang opsional. Minimum indoor opsional (preserve existing) atau null eksplisit (disable low rule), angka harus lebih kecil daripada maksimum pada **merged persisted state di dalam lock**. Batas input adalah validasi domain, bukan rekomendasi keselamatan.
- PUT wajib membawa `expected_config_version` dari GET saat draft dibuat. `200` mengembalikan state canonical; hanya perubahan nyata menaikkan versi. No-op dengan versi benar tidak membuat event/outbox baru.
- `409 configuration_version_conflict`: top-level `current` memuat settings server terkini, termasuk versi, sehingga UI dapat reconcile draft. Bahkan retry dengan payload sama tetapi versi lama tetap konflik; GET untuk memastikan apakah save sebelumnya berhasil.
- `428 configuration_version_required`: payload lama tanpa expected version ditolak, dengan `current`. Ini perubahan write-contract yang disengaja untuk mencegah lost update; klien Flutter Phase 6 belum bisa save sampai adapter diperbarui. GET/monitoring tetap kompatibel. Tidak ada fallback blind-write tersembunyi.
- `422`: field/urutan threshold/version tidak valid; `401/403` tetap mengikuti auth, active account dan Admin-only authorization.
- `configuration_status`: persistence `stored`; delivery `disabled`; outbox `blocked` jika ada target pada versi ini, selainnya `not_queued`; `target_count`; applied version null dan device_applied false. Respons save **bukan** bukti konfigurasi diterapkan perangkat.

Settings, version CAS, outbox, audit event dan evaluasi alert berada dalam satu transaksi. Audit memuat before/after, changed_by, previous_config_version dan config_version. Preferensi notifikasi digabungkan seperti sebelumnya. State alert internal tidak dapat ditulis lewat request.

### Alert, batch dan humidity

- `low_indoor_temperature` memakai source `indoor_low` dan average resmi. Batas strict `< minimum`; high strict `> maximum`; equality normal. Event transisi dideduplikasi, recovery tidak dibuat dari data stale/partial/invalid. Preferensi temperature_alerts berlaku pada kedua arah.
- Source `indoor` lama tetap high-indoor, sehingga state high historis tidak salah dimaknai. Source `outdoor` lama tidak lagi dievaluasi atau diberi recovery palsu. Filter event masih menerima `low_outdoor_temperature` untuk membaca riwayat, sekaligus menerima event baru.
- Payload v1 invalid/null/NaN/nonfinite/out-of-range/duplicate/wrong scope tetap ditolak atomik. Subset valid tetap disimpan. Lima pesan parsial dengan timestamp sama juga tidak menjadi satu batch; dua device dengan message_id sama tidak dapat melengkapi satu sama lain.
- `average=null` dan `complete=false` bila batch tidak konsisten. `partial_average`, lima sensor diagnostik, count dan alasan unavailable tetap tersedia. `valid_count=5` **tidak** menjamin complete.
- Newest invalid/future reading dalam storage menonaktifkan official latest; last-valid value tetap sebagai diagnosis dengan status invalid. Data out-of-order yang sah tetap masuk history tanpa mengganti latest yang lebih baru.
- Row historis tanpa batch_key hanya dapat beragregasi dengan exact timestamp + single device; latest menyatakan provenance `legacy_exact_timestamp`, bukan klaim message identity historis terverifikasi. Baru dari MQTT: `mqtt_v1_message`. Raw history menambah batch_key nullable.
- Chart memakai aturan cardinality/device/batch identik per timestamp sebelum bucket averaging. Stale adalah konsep latest terhadap waktu sekarang; historical aggregate yang valid saat timestamp-nya tetap tersedia untuk chart/CSV. Format CSV tetap sama, tanpa kolom delta.
- Indoor humidity tetap `legacy_single_channel`, `five_sensor_verified=false`. Tidak dibuat lima kanal atau hum_avg fiktif.

### Delta dan identitas

`temperature_comparison` memuat delta_temp, unit celsius, available, unavailable_reason, pairing_policy exact_timestamp, indoor_recorded_at dan outdoor_recorded_at. Nilai dihitung sebagai indoor average minus outdoor, setelah freshness/validity/batch terpenuhi. Toleransi lintas node belum disahkan; bahkan selisih satu mikrodetik menghasilkan unavailable. Tidak memasangkan rata-rata bucket chart sebagai delta.

Fixture mempertahankan ID development legacy `ESP32_DEVICE_A/B` untuk kompatibilitas; type/nama default baru menyatakan board target. `firstOrCreate` tidak mengoreksi existing registration secara diam-diam. Allowlist `mqtt.device_sensors` masih opt-in untuk instalasi legacy; binding sensor ke board fisik yang benar tetap perlu verifikasi. Nama/ID device bukan bukti board riil.

### MQTT dan kontrol

Rencana rinci: [POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md](POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md). Subscriber, topic, envelope, retained rejection, dedup, scope dan mock transport v1 tetap dipertahankan. Outbox baru hanya draft internal. Tidak ada drain blocked queue, live retry, ACK receiver, retained config publication, actuator command atau display publication.

`ControlDemandPreview` hanya menghitung demand dari input temperature/batas valid dan gas safety boolean yang **harus** diverifikasi caller kelak. Input missing/nonfinite/unconfigured atau gas unknown menghasilkan unavailable, bukan OFF. `ventilation_demand = temperature_ventilation_demand OR gas_safety_demand`. Heater demand dan ventilation demand dapat sama-sama true sebelum interlock diputuskan; hasil ini tidak boleh dieksekusi sebagai relay command. Dua fan merupakan target ventilasi bersama setelah acceptance. Lampu penerangan tetap terpisah dan null. automation_enabled selalu false, actuator_commands selalu null.

## TESTED

Rincian setiap run dan kegagalan antara ada di [checkpoint](POULTRIQ_PHASE7_BACKEND_CHECKPOINT.md). Semua pengujian menggunakan PHP 8.3.30 / PHPUnit 12.5.43. Tidak ada hasil mock yang dianggap bukti koneksi hardware.

| Pemeriksaan final | Hasil aktual |
| --- | --- |
| Full PHPUnit SQLite `:memory:` | **113 tests, 1006 assertions, PASS**; 42.787 detik |
| Full PHPUnit MySQL disposable 8.4.3 | **113 tests, 1006 assertions, PASS**; 57.177 detik |
| Laravel Pint pada seluruh PHP modified/new | **PASS** |
| `git diff --check` | **PASS** |
| AGENTS SHA256 dan Flutter HEAD/status | Identik baseline; Flutter bersih |
| Git index | Kosong; tidak ada stage/commit |

Cakupan suite: auth/RBAC, inactive/outsider, existing monitoring, minimum indoor/strict boundary/retired outdoor/history preservation, migration up/down guard, version conflict/no-op/rollback, preferensi/audit/notifikasi, same-time/device/message batch, partial/asynchronous/invalid/stale/out-of-order, scope/dedup MQTT v1, mock transport, outbox transaction/idempotency, delta pairing, chart/history/CSV, fixture dan lighting disabled.

MySQL hanya instance `--no-defaults` di `127.0.0.1:59134`, database `smart_kandang_test`, datadir baru `.runtime/phase7-mysql-c34290c5d3da471e9e8d71f0f89cd5be/data`. Identitas process/child, port dan @@datadir diperiksa sebelum tes. Server sudah menerima shutdown normal setelah tes; tidak ada server test yang sengaja dibiarkan aktif. File data/log disposable tetap di `.runtime/` yang ignored; tidak masuk perubahan Git. Log final: `.runtime/phase7-sqlite-final.txt` dan `.runtime/phase7-mysql-final.txt`.

Run pengembangan sempat gagal pada ekspektasi outdoor alert yang memang retired, asumsi urutan row pada tes outbox, dan autentikasi disposable karena env kosong PowerShell. Penyebab/perbaikan tercatat di checkpoint. **Tidak ada kegagalan tersisa pada suite final.** Tidak dilakukan live MQTT/physical acceptance, dan pengujian versi dua draft bukan stress test multi-process.

## PENDING VERIFICATION

- ID board fisik, binding coop/sensor, payload lima humidity, timing/freshness hardware; legacy exact-time bukan bukti message batch historis.
- MQ135 kalibrasi/R0/cross-sensitivity/kompensasi, filter window, unit dan threshold safety; LDR A0 range/polarity serta kalibrasi lux.
- Satu otoritas otomatis, gas hysteresis/recovery, anti-short-cycle/minimum runtime, heater/fan interlock, stale/missing/fault fallback, boot/power/relay polarity dan wiring.
- Topic/payload final, ACL/TLS/QoS, time/boot/session identity, device ACK/apply/reported/physical feedback, NVS/offline/reconnect, config expiry dan command replay policy.
- LCD ukuran/alamat/layout, push provider/token/delivery. Stored notification tidak membuktikan push mobile.
- Staging dan hardware acceptance, MySQL concurrent worker stress/chaos tests. Tes dua draft stale dan conditional SQL sudah ada; bukan klaim load test paralel produksi.

## NOT IMPLEMENTED

- Perubahan Flutter/firmware, registrasi ID fisik atau backfill minimum dari nilai outdoor.
- Lima humidity aggregation, konversi ppm/lux, smoothing window atau safe thresholds yang belum disahkan.
- Live MQTT v2 subscriber/publisher, retry/lease worker, applied ACK/reported pipeline, NVS, relay automation, command transport, LCD publisher, push provider.
- Commit, staging Git, push, merge, PR, deployment, production migration atau perubahan VM/network.

## Kebutuhan integrasi Flutter fase berikutnya

1. Model settings membaca minimum indoor nullable, contract_version, config_version dan configuration_status. Jangan mengisi minimum indoor dari field legacy atau angka ilustratif; tampilkan unconfigured.
2. Simpan versi pada saat draft dimulai. PUT mengirim expected_config_version dari basis draft; **jangan otomatis mengganti dengan versi GET terbaru sebelum save**, karena itu menutupi konflik. Handle 409 dengan compare/reload/explicit reconcile; 428 berarti adapter belum mengirim precondition. Pertahankan dirty draft, discard, sticky Save, guard session/coop dan RBAC.
3. Label minimum/maksimum sebagai indoor, validasi min < max; dukung null untuk menonaktifkan minimum. Outdoor referensi; gas tetap ADC. Hilangkan penggunaan threshold outdoor untuk alarm baru, tetapi riwayat low_outdoor_temperature tetap dapat ditampilkan dengan makna historis.
4. Render official average hanya jika complete true, termasuk bila valid_count=5 tetapi inconsistent_batch. Partial adalah diagnostik. Tampilkan humidity sebagai kanal legacy dan delta hanya saat temperature_comparison.available=true.
5. Saved/stored tidak boleh menjadi Applied/relay ON. Status delivery disabled/outbox blocked/applied null harus terlihat jujur; lighting commands tetap disabled dan endpoint masih 409.
6. Selector device/status tidak boleh hanya memfilter nama `ESP32`; gunakan registered identifier + metadata tanpa mengarang pemetaan board. Metadata production existing belum diubah oleh Phase 7.
7. Tambahkan tes stale drafts, 409/428, nullable min, threshold order, independent high/low events, mixed batch, stale/unpaired delta, stored versus applied, role revoke dan pergantian sesi/coop. Pertahankan Phase 6 localization/UI/history/CSV.

## Inventaris file dan Git

Total perubahan implementasi/dokumen: **16 file tracked dimodifikasi, 17 file baru**. `AGENTS.md` adalah file pengguna yang sudah untracked saat mulai; tidak diedit. SHA256 tetap `6414B063736C7CD26BD9562CEA7D61325B1E117DF9929E1B5D8736D0CF7C5EAE`.

Status final berikut sekaligus inventaris seluruh file:

```text
 M app/Http/Controllers/Api/SettingsController.php
 M app/Http/Requests/HistoryRequest.php
 M app/Http/Requests/SettingsRequest.php
 M app/Http/Resources/CoopSettingResource.php
 M app/Http/Resources/SensorReadingResource.php
 M app/Models/CoopSetting.php
 M app/Services/AlertEvaluationService.php
 M app/Services/EventRecorder.php
 M app/Services/Mqtt/TelemetryIngestor.php
 M app/Services/SensorAggregationService.php
 M app/Services/SensorHistoryService.php
 M database/seeders/DatabaseSeeder.php
 M tests/ApiTestCase.php
 M tests/Feature/MonitoringApiTest.php
 M tests/Feature/MqttIngestionTest.php
 M tests/Feature/SeederTest.php
?? AGENTS.md
?? POULTRIQ_PHASE7_BACKEND_CHECKPOINT.md
?? POULTRIQ_PHASE7_BACKEND_REPORT.md
?? POULTRIQ_PHASE7_MQTT_COMPATIBILITY.md
?? app/Services/ControlDemandPreview.php
?? app/Services/OperationalConfigurationOutbox.php
?? app/Services/TemperatureComparison.php
?? database/migrations/2026_11_24_000000_add_indoor_minimum_to_coop_settings.php
?? database/migrations/2026_11_24_000001_add_sensor_batch_identity.php
?? database/migrations/2026_11_24_000002_add_operational_config_version.php
?? database/migrations/2026_11_24_000003_create_operational_configuration_outbox.php
?? tests/Feature/IndoorThresholdContractTest.php
?? tests/Feature/OperationalConfigurationOutboxTest.php
?? tests/Feature/Phase7MigrationTest.php
?? tests/Feature/SensorBatchContractTest.php
?? tests/Feature/SettingsVersionContractTest.php
?? tests/Feature/TemperatureComparisonTest.php
?? tests/Unit/ControlDemandPreviewTest.php
```

Branch tetap `feat/phase7-backend-contract`; HEAD tetap baseline, index kosong. Flutter branch `feat/phase6-ui-language-experiment` dan HEAD `117861f325c4d47b9e9325f241fc375c3beb7d52` tetap bersih. Tidak ada source Flutter/firmware, AGENTS, operational database, VM atau broker yang diubah.

Pekerjaan backend lokal selesai sesuai batas software. Langkah berikutnya adalah integrasi adapter Flutter berdasarkan kontrak di atas, lalu acceptance firmware/hardware sebelum perancangan aktivasi transport terpisah. Tidak diperlukan langkah publication untuk menyelesaikan Phase 7 lokal ini.

## BH1750 Sensor Contract Revision — 24 November 2026

### Kontrak resmi dan pemulihan

Phase 7 dipulihkan pada branch/HEAD yang sama dengan seluruh perubahan lokal dipertahankan. Instance MySQL disposable terdahulu sudah berhenti: port 59134 tidak listening, tidak ada proses dengan dedicated datadir, log mencatat shutdown complete. Service MySQL operasional tidak disentuh.

Target sebelumnya adalah LDR analog A0 (kini **LEGACY/DEPRECATED**). Target baru: **BH1750 digital I2C, lux**, pada Outdoor **NodeMCU ESP8266** bersama AHT20 dan LCD di D2 SDA/D1 SCL jika elektrik kompatibel. Alamat 0x23/0x5C menurut ADDR; firmware, actual measurements, timing/mode, identity, address aktual, voltage/pull-up/shared-bus belum diverifikasi. Indoor ESP32, lima DHT22, MQ135 ADC1, heater, dua fan, lighting lamp dan rencana NVS tidak berubah.

Kedua pedoman resmi diperbarui di `D:/FlutterProjects/smart-kandang-backend/AGENTS.md` dan `D:/FlutterProjects/project_kandangayam/AGENTS.md`. Audit Flutter mendapat addendum bertanggal tanpa menghapus sejarah. Source Flutter Dart tidak diubah.

Rentang kontrak awal `outdoor_light_lux` adalah **0–65535 lux**, finite/nonnegative. Dasar domain: [ROHM BH1750FVI Technical Note Rev.D](https://www.mouser.com/datasheet/2/348/bh1750fvi-e-186247.pdf), halaman 1–2: advertised normal range dan dark response. Batas extended sensitivity sampai 100000 lux tidak digunakan tanpa kontrak measurement mode baru. Nol merepresentasikan gelap; angka batas domain bukan threshold operasi aman. Tidak ada ADC-to-lux conversion.

### Implementasi final dan kompatibilitas

Revisi software lokal selesai. Pekerjaan Phase 7 sebelumnya tetap dipertahankan: minimum indoor nullable, legacy storage/events, agregasi lima DHT22 satu waktu/device/message, settings version/CAS, board identity, delta tervalidasi, operational outbox blocked, RBAC/monitoring dan pure thermal/gas demand preview.

| Area / file backend | Hasil revisi |
| --- | --- |
| `SensorCatalog`, `Mqtt/PayloadValidator`, `Mqtt/TelemetryIngestor` | Identifier `outdoor_light_lux`, type `outdoor_light`, unit `lux`, measurement version `bh1750_lux_v1`. Nilai finite 0–65535; malformed, nonfinite, negatif, wrong-unit, wrong mapping ditolak atomik. |
| `LightingContract`, `config/lighting.php` | Gate BH1750 terpisah, default false; explicit device allowlist, registered `nodemcu_esp8266`, exact identity dan optional global MQTT sensor mapping. Legacy LIGHTING gate tidak memberi izin BH1750. |
| `Bh1750Telemetry`, `SensorAggregationService` | Latest memvalidasi source/unit/version/freshness; missing/stale/invalid/unsupported tidak menjadi data fresh valid. |
| `SensorHistoryService`, `HistoryRequest`, `SensorReadingResource` | Seri lux/history/chart terpisah dari ADC; raw ADC history tetap tersedia dan ditandai legacy. CSV existing tetap kompatibel; tidak ada konversi ADC. |
| `LightingController`, `LuxLightingSettings` | Explicit schema 2, Admin-only, domain versi lux terpisah, CAS/no-op, conflict current-state, transactional audit + blocked outbox. |
| `LightingDemandPreview` | Fungsi hysteresis murni; below min ON, above max OFF, rentang dan boundary retain previous. Missing/stale/invalid/manual atau previous unknown dalam band menghasilkan unavailable; tidak mengirim command. |
| `2026_11_24_000004_create_bh1750_lighting_foundation.php` | Migration additive dua tabel lux settings/outbox; tanpa backfill ADC, tanpa edit migration lama, rollback menolak menghapus draft/intent yang sudah terisi. |
| `DatabaseSeeder` | Tidak membuat telemetry BH1750 fiktif; fixture board Phase 7 dipertahankan. |
| Tes BH1750 telemetry/settings/migration dan unit hysteresis | 17 tes baru; `Phase7MigrationTest` tetap menguji empat migration Phase 7 awal secara eksplisit. |

`DeviceResolver` diaudit dan dipertahankan: validasi existing registered device/coop tetap dipakai sebelum gate BH1750. MQTT topic/subscriber dan envelope v1 `message_id/sent_at/readings` tidak diganti. Reading baru boleh mengirim `unit: "lux"` opsional; tanpa unit backend memakai unit canonical. Wrong unit/field tambahan ditolak. Dedup, receipt protection, timestamp validation, retained rejection dan scope existing tetap berlaku. Tidak ada koneksi broker, publisher retained config atau actuator command.

API lighting memakai route existing di bawah `/api/v1/coops/{coop}`:

- `GET /lighting` tetap schema 1 **LEGACY/DEPRECATED**. Klien baru wajib meminta `GET /lighting?schema_version=2`.
- `PUT /lighting/settings` schema 2 mewajibkan `schema_version: 2`, `lighting_mode: auto|manual`, `min_lux`, `max_lux`, `threshold_unit: lux`, `measurement_version: bh1750_lux_v1`, dan `expected_config_version`. Nilai threshold maksimal dua desimal, keduanya null atau `0 <= min_lux < max_lux <= 65535`. Field asing/kontrak tidak valid ditolak 422.
- Lux `config_version` mulai 0 saat belum ada draft; terpisah dari schema 1 dan operational settings. Save berubah menaikkan versi; no-op tidak membuat versi/outbox baru. Versi stale mendapat 409 `lighting_version_conflict` beserta `current`; expected version yang tidak dikirim mendapat validasi 422 pada domain lighting ini (428 tetap berlaku pada operational settings).
- Belum ada threshold berarti `policy_status=unconfigured`, tanpa default aman. Draft tersimpan bukan applied: `configuration=false`, `configuration_storage=true`, `commands=false`, `automation_enabled=false`, `device_applied=false`, `applied_config_version=null`. Outbox tetap `blocked`; binding actuator null karena belum diverifikasi. `POST /lighting/commands` tetap 409.
- Tabel `coop_lighting_lux_settings` dan `lighting_lux_configuration_outbox` sepenuhnya terpisah dari legacy ADC settings/outbox/readings. Versi maupun nilai ADC lama tidak direinterpretasi. Semua migrasi hanya diuji pada database disposable.

### Hasil verifikasi baru dan cleanup

| Pemeriksaan pada revisi BH1750 final | Hasil |
| --- | --- |
| Focused telemetry + MQTT/lighting legacy/chart/CSV/seeder | **60 tests / 320 assertions PASS** |
| Focused lighting/versioning/migration/hysteresis setelah tambahan tes | **26 tests / 345 assertions PASS** |
| Full PHPUnit SQLite in-memory | **130 tests / 1267 assertions PASS**, 11.461 detik |
| Full PHPUnit MySQL 8.4.3 disposable | **130 tests / 1267 assertions PASS**, 36.214 detik |
| Laravel Pint seluruh PHP changed/new | **PASS** |
| `git diff --check` kedua repository | **PASS** |
| Hash 253 file Flutter di luar dua dokumen yang diizinkan | **Identik baseline, 0 mismatch** |

Full suite memakai PHP 8.3.30 dan PHPUnit 12.5.43. Log baru: `.runtime/phase7-bh1750-sqlite-final.txt` dan `.runtime/phase7-bh1750-mysql-final.txt`. Ini hasil baru, bukan pengulangan laporan 113/1006 historis. Tes mencakup nonfinite/wrong unit/device, stale/missing/revoked mapping, legacy separation, version conflict/no-op, rollback atomik, migration preservation dan command disabled selain regresi Phase 7 lama. Tidak diklaim sebagai uji hardware atau stress test concurrent workers.

Instance disposable lama port 59134 sudah diverifikasi berhenti sebelum pekerjaan. Instance baru port **52752** dengan dedicated datadir `.runtime/phase7-bh1750-mysql-7fa7552ab2ac4fd5b53f8a7458edca59/data` juga sudah menerima shutdown normal setelah tes. PID parent 14520/child 18132 dan command-line/datadir diverifikasi sebelum shutdown; tidak ada listener/proses tersisa untuk instance ini, log `Shutdown complete` pada 2026-11-24 15:57:46 UTC. Data/log disposable dipertahankan di `.runtime` ignored sebagai bukti. Service MySQL operasional tidak disentuh.

### Pending firmware dan integrasi Flutter

Belum terverifikasi: firmware BH1750, actual lux/mode/timing, binding ID fisik NodeMCU, alamat 0x23/0x5C, voltage/pull-up/shared I2C, operational thresholds, stale/fault/offline/reboot policy, NVS, otoritas kontrol tunggal, relay wiring/polarity/interlock, ACK/reported dan physical feedback. Gate telemetry tetap default disabled. Command lighting tetap disabled sampai firmware, feedback dan safety acceptance disahkan; kelulusan hysteresis murni tidak mengotorisasi aktuator.

Flutter fase berikutnya perlu model/adapter schema 2, label BH1750/lux, `auto/manual`, nullable min/max, versi draft independen dan explicit 409 reconcile. Tampilkan missing/stale/unsupported serta stored versus applied; jangan menampilkan ADC lama sebagai lux atau draft sebagai relay ON. Pertahankan integrasi minimum indoor, aggregation completeness/delta, RBAC, dirty draft dan UI/lokalisasi Phase 6. **Tidak ada Flutter Dart atau firmware yang diubah pada fase ini.**

### Git akhir revisi

Backend tetap `feat/phase7-backend-contract`, HEAD `f58e5a995a298a1664e16372d4d57ea5b6f4370f`; **21 tracked modified dan 26 untracked files**, seluruh implementasi Phase 7 dan BH1750 masih perubahan lokal unstaged. Backend AGENTS.md yang semula untracked sengaja diperbarui sesuai instruksi revisi pemilik; klaim hash tidak berubah pada bagian historis tidak berlaku untuk revisi ini.

Flutter tetap `feat/phase6-ui-language-experiment`, HEAD `117861f325c4d47b9e9325f241fc375c3beb7d52`; hanya `AGENTS.md` dan `POULTRIQ_ARCHITECTURE_GAP_AUDIT.md` berubah. Kedua index kosong. Tidak ada stage, commit, push, merge, reset, clean, PR, deploy, production migration, edit firmware atau aktivasi relay. Inventaris aktual akhir juga dicatat di checkpoint revisi.

## Verifikasi kelanjutan — 25 November 2026

Permintaan resume diperiksa terhadap working tree aktual. Kedua AGENTS sudah memuat BH1750 I2C/lux pada NodeMCU Outdoor, tanpa LDR A0 sebagai target. Implementasi telemetry, explicit lighting schema 2, independent version/CAS, additive migration tanpa ADC backfill, blocked outbox dan pure hysteresis sudah tersedia beserta tesnya. Pekerjaan minimum indoor, batch lima DHT22, delta, RBAC dan MQTT v1 sebelumnya tetap ada. **Tidak ditemukan implementasi backend tertunda; sesi ini memverifikasi dan memperbarui bukti, tanpa mengulang atau mengubah source/migration/tes yang sudah selesai.** `deploy/smart-kandang-mqtt.service` juga tidak diubah.

| Bukti yang diperiksa pada sesi kelanjutan | Hasil |
| --- | --- |
| Full PHPUnit SQLite in-memory, run baru | **130 tests / 1267 assertions PASS**, 01:21.628, exit code 0 |
| Pint seluruh PHP changed/new | **PASS** |
| Whitespace kedua repository | **PASS** |
| Integritas 253 file Flutter di luar dua dokumen revisi | **0 mismatch** terhadap baseline |
| Kedua MySQL disposable sebelumnya, port 59134 dan 52752 | Masing-masing **0 listener / 0 matching process**; log `Shutdown complete` |
| Log full MySQL revisi tanggal 24 November | Dibaca ulang: **130 tests / 1267 assertions PASS**; **bukan run baru sesi ini** |

Log SQLite baru: `.runtime/phase7-bh1750-resume-2026-11-25-sqlite.txt`, PHP 8.3.30 / PHPUnit 12.5.43. Database dipaksa `sqlite/:memory:` dengan DB_URL null dan tanpa cached configuration. Tidak membuka database MySQL pada sesi ini; tidak perlu menjalankan kembali instance disposable yang telah ditutup karena tidak ada perubahan kode atau migration. Hasil MySQL sebelumnya tetap dibedakan dari verifikasi SQLite baru.

Status akhir: backend `feat/phase7-backend-contract`, HEAD `f58e5a995a298a1664e16372d4d57ea5b6f4370f`, **21 tracked modified + 26 untracked**; Flutter `feat/phase6-ui-language-experiment`, HEAD `117861f325c4d47b9e9325f241fc375c3beb7d52`, hanya dua dokumen modified. Kedua index kosong. Perubahan sesi kelanjutan hanya checkpoint dan laporan backend; seluruh perubahan implementasi sesi sebelumnya dipertahankan.

Sisa integrasi tetap di luar backend lokal yang selesai: Flutter perlu GET lighting `?schema_version=2`, adapter `auto/manual`, nullable min_lux/max_lux, independent draft version dan 409 reconcile, status freshness serta stored-versus-applied. Firmware/pengukuran BH1750, timing/device identity, I2C/address/voltage/pull-up, threshold operasional, ACK/feedback, fail-safe dan safety acceptance masih pending. **Lighting/actuator commands tetap disabled/409 dan outbox blocked.** Tidak ada Flutter Dart/firmware, operational DB, live MQTT, relay, stage, commit, push atau deploy yang disentuh.
