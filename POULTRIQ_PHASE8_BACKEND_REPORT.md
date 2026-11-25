# Phase 8 Backend Integration Report

Tanggal 25 November 2026. Baseline `90451c8`, branch lokal `feat/phase8-backend-integration`.

Implementasi dan verifikasi lokal selesai. Hasil tes baru Phase 8 dicatat di bawah; MQTT v1 dan seluruh data legacy dipertahankan. Semua integrasi fisik tetap disabled. Detail pemulihan/run pengembangan ada di checkpoint.

## Arsitektur dan kompatibilitas

Migration additive `2026_11_25_000000_create_integration_foundations` menambah tujuh tabel: integration_sessions, integration_deliveries, integration_receipts, actuator_reports, actuator_command_drafts, push_tokens, push_deliveries. Tidak mengubah migration/data Phase 7. Down menolak menghapus tabel berisi data; gunakan forward migration yang direview untuk rollback populated data.

`OperationalConfigurationOutbox` dan `LuxLightingSettings` tetap menyimpan outbox Phase 7 blocked. Dalam transaksi save yang sama, `ConfigurationDelivery` membuat snapshot immutable per coop/device/domain/version. Versi operational dan lighting_lux tetap independen. Tidak melakukan backfill atau mengaktifkan/drain outbox lama. No-op dan konflik versi tidak menghasilkan delivery baru. Device baru menerima snapshot pada save berubah berikutnya; tidak ada automatic production bootstrap/backfill.

`ConfigurationDispatcher` mengklaim row dengan lock + lease, memeriksa current version/binding/expiry/session/online, lalu melakukan publish di luar transaksi claim dan menyimpan hasil dengan lease token. Retry memakai identitas/payload yang sama, default budget 3, exponential backoff 5/10 detik (cap 3600), ACK timeout 60 detik, lease 30 detik. Crash/retry dapat menyebabkan at-least-once delivery; firmware tetap wajib dedup/version guard. Tidak ada klaim exactly-once atau stress test multi-process. ACK yang datang sewaktu publish tidak ditimpa menjadi published. Snapshot expired tidak dibuat ulang otomatis dengan versi sama; perlu rekonsiliasi versi baru yang disahkan.

State: settings **stored** pada DB; delivery baru **blocked** secara default, atau **pending** pada simulation test; send sukses **published**, device receipt **acknowledged**, explicit matching device apply **applied**, rejection/expiry/superseded/budget exhausted **failed**. Offline menunda retry tanpa menghabiskan attempts. Timestamp publish bukan bukti applied. Status historical tetap tersedia; `applied_current` juga memeriksa current version, session, expiry dan online.

`DeviceFeedback` adalah boundary internal dengan authenticated Device context, bukan endpoint publik. Semua penggunaan diblokir di luar test simulation. Session boot UUID harus established dari authenticated future adapter. Boot yang sudah retired tidak dapat kembali. ACK/report memakai sequence monotonik, message UUID/hash receipt dedup, scoped coop/device/topic, finite freshness window, no future timestamps, no retained. New boot menghilangkan current reported-state dan meminta re-ACK snapshot unexpired; old boot/late ACK tidak dapat menandai current applied. MQTT v1 online/offline/telemetry tetap tidak berubah dan tidak otomatis dianggap autentikasi boot v2.

## Kontrak MQTT draft untuk acceptance firmware

Prefix baru tetap namespace `smart-kandang/coops/{coop_id}/devices/{device_identifier}/integration/v1/` sehingga tidak bertabrakan dengan telemetry/status v1 existing. Belum disubscribe atau dipublish ke broker nyata.

| Suffix | Arah | Aturan |
| --- | --- | --- |
| `config/{operational|lighting_lux}` | backend → device | Retained **hanya dalam fake simulation**; immutable message_id, schema_version 1, contract phase8_draft, coop/device, domain, config_version, settings, generated_at/expires_at, payload_hash |
| `ack` | authenticated device → backend | NON-retained; kind ack, message UUID, boot UUID, sequence, sent_at UTC, coop_id/device_identifier, configuration_message_id, domain/config_version/payload_hash, status acknowledged/applied/rejected |
| `report` | authenticated Indoor ESP32 → backend | NON-retained; common identity/session/time/sequence fields, kind report, empat state bool/null, feedback_kind gpio/physical |

ACK `sent_at` format `YYYY-MM-DDTHH:mm:ssZ`, paling lama 60 detik dan tidak di masa depan; timestamp/window ini parameter simulation, bukan acceptance hardware. `payload_hash` adalah SHA256 encoding `CanonicalPayload` atas snapshot sebelum menambah field payload_hash: object keys diurutkan rekursif menurut string, list order dipertahankan, JSON compact dengan slash tidak di-escape. Ini draft encoding software, bukan klaim standar canonical JSON firmware yang sudah disahkan. Device mengembalikan hash snapshot yang diterima. Hash stabil terhadap pengurutan key JSON column MySQL; receipt/command idempotency juga memakai encoder ini. Fields asing ditolak. Duplicate payload identik secara semantik tidak membuat state baru; collision/replay ditolak atomik. ACK harus cocok current snapshot dan ada bukti publish/active claim. ADC legacy tidak menjadi lux; gas tetap ADC.

Actuator command hanya draft internal `CommandDraft`, tidak ada transport command. Contract memuat request UUID, actor via server, coop/device Indoor ESP32, actuator identifier, desired boolean, expected config version, boot bila tersedia, expiry maksimal 30 detik, retained=false. Active Admin wajib; lighting juga mensyaratkan mode manual. Reuse request ID dengan isi berbeda ditolak. Draft selalu blocked, tidak membuat reported ON; public commands tetap 409. Heater/fan manual operating policy belum disahkan dan draft bukan izin fisik.

## Control dan LCD

`ControlEngine` memakai `SensorAggregationService`, `ControlDemandPreview`, `Bh1750Telemetry` melalui snapshot dan `LightingDemandPreview` Phase 7. Thermal input memerlukan lima sensor complete/current dengan registered Indoor ESP32 source. Gas comparison memakai valid fresh ADC dari Indoor dan threshold ADC. `gasPolicyVerified=false` pada public preview: threshold existing tidak dianggap safe. Dalam pure test dengan kebijakan gas eksplisit, gas override mengaktifkan demand kedua fan walaupun suhu normal; bila suhu missing heater unknown, confirmed gas demand tetap true. Heater/fan interlock tetap pending, tanpa command output.

Lighting lux menggunakan strict below/above dan retain previous pada kedua boundary/rentang. Previous berasal dari tepat satu fresh reported Indoor state; missing/stale/multiple ambiguous previous menghasilkan unavailable di dalam band. Mode manual tidak menjalankan otomatis. Reported GPIO dibedakan dari deklarasi physical feedback, tetapi `physical_verified` tetap false: label dari device bukan bukti hardware acceptance. Unknown/offline/reboot tidak diklaim OFF.

`LcdPayload` menghasilkan DTO schema 1 untuk NodeMCU Outdoor: generated_at/expires_at (30 detik), target registered IDs, suhu indoor/outdoor, MQ135 ADC, BH1750 lux, status/freshness dan nilai null bila unavailable; actuator report terpisah dari demand. Layout 16x2/20x4 dan alamat belum ditentukan. Tidak ada LCD publication.

## Push notification foundation

Token disimpan terenkripsi memakai application key, hash SHA256 untuk uniqueness, owner/installation UUID/platform/active/last_seen. Registration idempotent per installation, rotation mengganti token, revoke hanya owner. Token yang sudah terikat installation/account lain ditolak 409; klien perlu regenerasi provider token saat berpindah akun. Raw token tidak muncul di response/list/status.

`EventRecorder` membungkus event + in-app notifications + push intents dalam transaksi. `PushQueue` membuat satu intent per notification/token; tidak backfill notifikasi lama saat token baru didaftarkan. Worker memeriksa ulang user active, coop assignment/role, kategori preference, token active/owner/hash dan expiry satu hari sebelum send. Rotasi/revoke/preference changes membatalkan intent lama. Invalid-token response menonaktifkan token. Retry/lease/budget persisten; exceptions tidak menyimpan token/secret. Provider acceptance diberi status `provider_accepted`, bukan handset delivered/read. Real provider belum diimplementasikan.

`DrainIntegrations` adalah queued job; `php artisan integration:queue` mengantre sweep bounded maksimal 100 config + 100 push rows. Retry budget ada pada delivery ledger, job tries=1; sweep berikutnya memproses due rows dan expired leases. Tidak memasang scheduler live baru. Production container mengikat DisabledMqttTransport/DisabledPushProvider; mengganti flag saja tidak cukup: dispatcher mengharuskan test environment, simulation true dan fake concrete provider. Tidak ada network client baru.

## API untuk Flutter

Semua endpoint di `/api/v1`, Sanctum + active-user middleware. Existing settings/lighting/monitoring v1 tetap tersedia, termasuk selector `lighting?schema_version=2` dan expected_config_version Phase 7.

| Endpoint baru | Akses / isi |
| --- | --- |
| GET `coops/{coop}/integration/deliveries` | Admin/assigned User; stored_versions, 100 delivery terbaru per coop, reason/attempt/timestamps/current/device_online/applied_current; external/physical capability false |
| GET `coops/{coop}/integration/monitoring` | Admin/assigned User; reported_actuators, control_preview, lcd_preview; semua demand dibedakan dari relay reported |
| POST `coops/{coop}/actuator/commands` | Admin; selalu 409 actuator_commands_unsupported; User 403. Existing lighting command juga tetap 409 |
| POST `push/tokens` | Active authenticated owner; installation_id UUID, platform android/ios/web, token 16–4096 chars; 201 create/200 rotate; response id/active/delivery_enabled false tanpa token |
| DELETE `push/tokens/{id}` | Owner saja; 204 revoke, foreign token 404 |
| GET `push/deliveries` | Owner; 100 intent terbaru, notification_id/status/reason/attempts/timestamp tanpa raw token |

Flutter selanjutnya perlu: registrasi token hanya setelah consent/provider setup; revoke saat logout, rotasi/refresh dan token baru saat ganti akun; render delivery stored/pending/published/acknowledged/applied/failed/blocked secara berbeda; hanya `applied_current=true` untuk current applied dan tetap bukan relay ON. Gunakan reported status/freshness/feedback_kind untuk aktuator, label preview demand, missing/stale sebagai unavailable. Simpan draft version Phase 7 dan handle 409 secara eksplisit. Jangan membuka tombol command dari hasil preview. Tidak ada perubahan Flutter/firmware pada fase ini.

## Blocker hardware/firmware dan yang tidak diimplementasikan

Belum disahkan: authenticated device transport (mTLS/ACL/credentials), wire contract/boot identity/sequence persistence/time sync, validitas retained snapshots dan NVS apply, device binding fisik, BH1750 mode/timing/I2C/pull-up/voltage/threshold, MQ135 kalibrasi/filter/threshold, lima humidity, stale/offline/reboot fallback, otoritas otomatis tunggal, relay wiring/polarity, heater-fan interlock dan minimum runtime/anti-short-cycle, genuine physical feedback. Tidak mengarang keputusan tersebut.

Tidak diimplementasikan/dijalankan: live MQTT config/command/LCD subscriber/publisher, physical command dispatcher, firmware, real push adapter/provider credentials, production scheduler/worker activation, hardware acceptance, live broker/notifications, deploy atau produksi migration. Interfaces dan simulasi tersedia untuk acceptance lanjut; bukan klaim hardware siap produksi. Tidak ada commit/stage/push/merge/reset/clean.

## Hasil pengujian

| Verifikasi final | Hasil |
| --- | --- |
| Full SQLite in-memory | **160 tests / 1488 assertions PASS**, 01:27.142 |
| Full MySQL 8.4.3 disposable | **160 tests / 1488 assertions PASS**, 01:47.526 |
| Focused awal SQLite / MySQL | Masing-masing **29 tests / 214 assertions PASS** |
| Focused fairness tambahan SQLite | **1 test / 5 assertions PASS** |
| Targeted MySQL sesudah perbaikan ordering/hash | **3 tests / 28 assertions PASS** |
| Laravel Pint seluruh PHP baru/berubah | **PASS** |
| `git diff --check` | **PASS** |

Runtime PHP 8.3.30 / PHPUnit 12.5.43. Semua **130 tes Phase 7 + 30 tes baru Phase 8** lulus pada kedua database; tidak ada kegagalan tersisa. Log final `.runtime/phase8-sqlite-final.txt` dan `.runtime/phase8-mysql-final.txt`. Focused log `.runtime/phase8-focused-4.txt`, `.runtime/phase8-mysql-focused.txt`, `.runtime/phase8-fairness.txt`, `.runtime/phase8-mysql-regression-fix.txt`. Run sebelum final tidak dijadikan bukti untuk perubahan sesudahnya; full suite sudah diulang setelah canonical hash fix.

MySQL hanya instance disposable no-defaults di 127.0.0.1:55364, database `smart_kandang_test`, datadir `.runtime/phase8-mysql-8971fbf269de4cc38ba0eab0d7566f70/data`. PID parent 5124/child 18064, port dan @@datadir diverifikasi sebelum tes; identitas proses/datadir diperiksa lagi sebelum `mysqladmin shutdown`. Verifikasi akhir **0 matching process / 0 listener**; log **Shutdown complete 2026-11-25 04:59:43 UTC (11:59:43 WIB)**. Tidak ada operational database, broker atau push provider yang diakses. File data/log disposable dipertahankan di `.runtime` ignored, tidak dihapus atau masuk Git.

Tidak ada penghapusan/pelemahan tes Phase 7. Run pengembangan yang gagal tercatat di checkpoint: benturan nama helper inherited, fixture field wajib/guard/mass-assignment, key nonsequential daftar LCD yang diperbaiki di source, serta asumsi relation order MySQL pada fixture fairness yang diganti dengan jadwal due eksplisit. Review JSON MySQL juga menghasilkan canonical hash fix dengan tes wire round-trip/reordered ACK. Log full sebelum final disimpan sebagai `.runtime/phase8-{sqlite,mysql}-full-1.txt`. Ini bukan kegagalan yang disembunyikan dengan mengubah tes existing.

## Klasifikasi hasil

| Kategori | Hasil |
| --- | --- |
| Diimplementasikan dan diuji secara lokal | Transactional per-device configuration ledger, bounded retries/lease/fair sweep, version/hash/session ACK, replay isolation, offline/reconnect, status APIs, command draft validation, thermal/gas/lux preview, LCD DTO, encrypted token lifecycle, notification outbox/provider retry, migration preservation; **full SQLite dan MySQL PASS** |
| Diimplementasikan tetapi disabled untuk eksternal | MQTT/push transport interfaces dengan disabled bindings, fake adapters, queue job/console sweep, feedback adapter; state transitions dapat diuji dalam simulation saja. Command drafts selalu blocked dan physical endpoints selalu 409 |
| Diblokir oleh hardware/firmware | Authenticated live adapter/ACL, approved wire payload dan boot/NVS/time contract, verified thresholds/calibration/interlock/fallback, electrical/relay acceptance, genuine feedback, LCD geometry |
| Tidak diimplementasikan | Real network MQTT/push providers, physical command dispatcher, LCD publication, firmware, provider credentials, production activation/deployment, stress/chaos multi-process; detail blocker dijelaskan di atas |
| Perlu dikerjakan pada Flutter | Adapter Phase 7 + read-only delivery/monitoring API, state/freshness/reporting UI, consent/token registration/rotation/revoke, explicit version conflict handling; physical buttons tetap disabled |

## Inventaris dan Git

Branch `feat/phase8-backend-integration`, HEAD tetap **90451c8f61baba846688341fa0afd0d21ec8d5c1**. Working tree: **6 tracked modified + 29 untracked files**, index kosong. Tidak ada stage/commit/push/merge/deploy/reset/clean.

Tracked integration hooks: `app/Providers/AppServiceProvider.php`, `app/Services/EventRecorder.php`, `LuxLightingSettings.php`, `OperationalConfigurationOutbox.php`, `routes/api.php`, `routes/console.php`.

File baru: dua dokumen Phase 8; `IntegrationController`/`PushTokenController`; job `DrainIntegrations`; dua belas services/interfaces pada `app/Services/Integration` (CanonicalPayload, CommandDraft, ConfigurationDelivery, ConfigurationDispatcher, ControlEngine, DeviceFeedback, DisabledMqttTransport, FakeMqttTransport, IntegrationMonitor, IntegrationPolicy, LcdPayload, MqttTransport); enam pada `app/Services/Push` (DisabledPushProvider, FakePushProvider, InvalidPushToken, PushDispatcher, PushProvider, PushQueue); `config/integration.php`; satu migration additive; empat feature test files IntegrationControlTest, IntegrationDeliveryTest, IntegrationMigrationTest, PushIntegrationTest.

AGENTS, laporan/checkpoint Phase 7, migration/test Phase 7, deployment service dan firmware tidak diubah. Flutter tidak disentuh; status existing tetap branch `feat/phase6-ui-language-experiment` dengan AGENTS.md dan POULTRIQ_ARCHITECTURE_GAP_AUDIT.md modified dari pekerjaan sebelumnya. Tidak menjalankan Flutter tests karena tidak ada perubahan Flutter pada fase ini.

Timestamp wire/DTO generated_at/expires_at memakai ISO UTC. Timestamp ledger/report hasil database juga UTC (format SQL datetime); Flutter harus mem-parsenya sebagai UTC, bukan local timezone. Gunakan boolean freshness/current dari API untuk validitas, bukan menginferensikan status hanya dari waktu atau publish success.
