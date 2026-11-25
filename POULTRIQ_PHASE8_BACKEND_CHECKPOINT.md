# Phase 8 Backend Checkpoint

> Status terbaru: **implementasi lokal dan full regression selesai**. Lihat bagian Penutupan Phase 8 di akhir. Tahap/run sebelumnya dipertahankan sebagai riwayat, bukan pekerjaan yang perlu diulang.

Tanggal 25 November 2026. Baseline verified `90451c8`, working tree bersih saat mulai; branch baru `feat/phase8-backend-integration`. Tidak ada stage/commit/push/deploy atau akses broker/DB operasional. Phase 7 dipertahankan.

## Audit dan keputusan

| CURRENT | TARGET / GAP | RISK / DEPENDENCY | IMPLEMENTASI |
| --- | --- | --- | --- |
| Outbox Phase 7 immutable blocked | Delivery/version/retry/ACK per device | Firmware wire contract dan ACL belum disahkan | Delivery ledger additive, default blocked, fake-only simulation; jangan drain legacy |
| v1 status online/offline | Session/replay/reported state | Auth transport/boot semantics pending | Internal authenticated-device adapter, strict scoped envelopes, replay ledger; v1 unchanged |
| Pure thermal/gas + lighting preview | Sensor-driven preview dan LCD | Interlock/threshold/fault belum accepted | Reuse Phase 7 services, explicit unknown, no commands |
| Stored notifications | Token/provider/queue/retry lifecycle | Real provider belum dipilih | Encrypted tokens, per-recipient dedup queue, disabled provider, fake tests |
| Flutter Phase 7 APIs | Read-only integration/delivery views | Client adapter pending | Additive endpoints, RBAC, no token leakage, honest stored/applied |

## Urutan kerja

1. Migration additive dan transport interfaces/default disabled/fakes.
2. Config staging, bounded dispatcher, authenticated ACK/session/reported replay handling.
3. Control/LCD/command draft dan monitoring API.
4. Push token lifecycle, transactional notification queue, provider/worker.
5. Focused tests; full SQLite dan disposable MySQL; cleanup.
6. Laporan kontrak API/MQTT, blockers, Flutter handoff dan inventaris.

Status: implementasi sedang dimulai. Semua wire contracts Phase 8 adalah draft software; physical endpoints tetap 409. Izin user mencakup implementasi lokal di atas, bukan aktivasi hardware.

## Implementasi pertama dan tes terfokus

Migration baru `2026_11_25_000000` menambah delivery/session/receipt/report/command-draft dan token/push-delivery tables tanpa mengubah migration Phase 7. Config save dan EventRecorder kini menambah intent transaksional baru; legacy outbox tetap blocked. Interface MQTT/push default disabled, fake adapters dibatasi test environment + simulation. Worker lease/backoff/budget, ACK version/hash/boot/sequence, reconnect invalidation, scoped read-only APIs, control preview/LCD dan token rotation/revoke sudah ditulis. Console `integration:queue` menjadwalkan bounded queue job; tidak ada live subscriber/publisher baru.

Run focused pertama berhenti sebelum tes karena helper `readings()` bentrok dengan protected helper ApiTestCase; helper baru diubah menjadi `seedControlReadings`, tidak mengubah tes lama. Run focused kedua berjalan, log `.runtime/phase8-focused-2.txt`. Hasil belum diklaim lulus.

**Disposable MySQL Phase 8 sedang aktif untuk pengujian selanjutnya:** descriptor `.runtime/phase8-mysql-instance.json`; port 55364, parent PID 15084, root `.runtime/phase8-mysql-8971fbf269de4cc38ba0eab0d7566f70`, bind 127.0.0.1, no-defaults, mysqlx/log-bin disabled. Verifikasi command-line/PID/port/@@datadir sebelum CREATE DATABASE smart_kandang_test dan tes. Wajib shutdown instance ini saja setelah tes; jangan menghentikan MySQL operasional. Instance Phase 7 sebelumnya sudah berhenti.

PID child 15976/parent 15084, port 55364 dan @@datadir sudah cocok dengan root descriptor; database `smart_kandang_test` dibuat hanya di instance ini. Pemeriksaan pertama menolak representasi path escaped dari mysql batch output sebelum CREATE; diperbaiki dengan `--raw` dan normalisasi absolute path, lalu verifikasi berhasil.

Focused kedua: 26 tests/175 assertions, 1 error + 4 failures pada fixture baru (notification_preferences wajib belum diisi, request settings belum lengkap, measurement_version dibuang mass assignment model legacy, dan auth guard fixture perlu refresh setelah inactive). Fixture diperbaiki tanpa melemahkan tes existing/source Phase 7. Focused ketiga berjalan di SQLite, `.runtime/phase8-focused-3.txt`. ACK juga diperketat: harus ada publish/active claim evidence; report timestamp tidak boleh mundur pada boot yang sama.

Focused ketiga selesai: 26 tests/197 assertions, satu failure pada target_device_ids LCD yang masih mempertahankan key hasil filter. Source diperbaiki dengan values() agar JSON tetap list, bukan object. Ditambah tiga tes multi-coop/authenticated-source, failed-send/rejected ACK, serta independent lux save + queue job. Focused keempat SQLite dan focused MySQL berjalan; log `.runtime/phase8-focused-4.txt` dan `.runtime/phase8-mysql-focused.txt`.

Setelah interupsi turn, tidak ada PHP/MySQL proses tersisa; instance dedicated dihidupkan ulang **tanpa initialize ulang atau menghapus data**. Descriptor sekarang parent PID **5124**, child **18064**, port tetap 55364/datadir sama. PID/port dan @@datadir diverifikasi ulang sebelum tes. Hasil akhir dan shutdown masih harus dicatat.

## Focused selesai, full regression berjalan

Focused SQLite **29 tests / 214 assertions PASS** (01:10.569), focused MySQL disposable **29 tests / 214 assertions PASS** (01:28.352). Review menemukan fairness bounded sweep: urutan sekarang next_attempt_at lalu id agar device offline tidak menahan device berikutnya. Tes regresi tambahan **1 test / 5 assertions PASS** (SQLite). Staging menegakkan coop lock; API physical_verified selalu false sampai hardware acceptance, termasuk saat incoming report mengklaim physical. Pint seluruh PHP changed/new PASS, whitespace PASS.

Full final sedang berjalan pada source tersebut: SQLite session 7092 log `.runtime/phase8-sqlite-final.txt`; MySQL session 4906 log `.runtime/phase8-mysql-final.txt`. Jangan menjalankan tes MySQL lain paralel terhadap database disposable yang sama. Setelah keduanya selesai, periksa output, perbaiki kegagalan bila ada, verifikasi ulang yang relevan, shutdown instance descriptor dengan validasi PID/port/datadir, lalu lengkapi laporan final. Tidak ada source Phase 7/migration/test lama yang dihapus atau dilemahkan.

Full SQLite selesai **PASS 160 tests / 1486 assertions**, 00:33.913. Semua 130 tes Phase 7 dan 30 tes Phase 8 lulus. MySQL final session 4906 masih berjalan. Inventaris aktual: 6 tracked modified + 28 untracked, index kosong, HEAD tetap 90451c8. Report berisi API/MQTT draft, implemented/disabled/blockers/not-implemented/Flutter handoff; hasil full MySQL dan shutdown belum boleh diklaim sampai diverifikasi.

Full MySQL pertama selesai 160 tests/1483 assertions dengan 1 failure di fixture fairness: relation order MySQL membuat device kedua masuk antrean lebih dulu. Jadwal due fixture sekarang diatur eksplisit, tidak mengasumsikan insertion order. Review juga menemukan JSON MySQL dapat mengurutkan object keys berbeda; `CanonicalPayload` kini mengurutkan key object rekursif (list order dipertahankan) untuk hash snapshot/ACK receipt/command draft. Tes memeriksa hash wire setelah DB round-trip dan dedup ACK meski urutan key berbeda. Tidak mengubah tes Phase 7.

Log full pertama disimpan `.runtime/phase8-{sqlite,mysql}-full-1.txt`. MySQL regression fix session 35850 menjalankan tiga tes terdampak, log `.runtime/phase8-mysql-regression-fix.txt`; full SQLite final dijalankan ulang sesudah canonical hash fix. Full MySQL ulang menunggu targeted MySQL selesai agar tidak memakai DB yang sama paralel. MySQL descriptor tetap aktif parent 5124/child 18064; cleanup wajib setelah pengujian final. Inventaris menjadi 6 tracked modified + 29 untracked karena CanonicalPayload baru.

Targeted MySQL perbaikan **PASS 3 tests / 28 assertions**, 00:56.734. Full MySQL final ulang sekarang session **99109**, log `.runtime/phase8-mysql-final.txt`; SQLite final ulang session **42818**, log `.runtime/phase8-sqlite-final.txt`. Pint seluruh source/test baru/berubah serta whitespace PASS sesudah perbaikan canonical hash. Tidak ada source edits lain direncanakan kecuali full regression menemukan kegagalan. Tugas tersisa: kedua hasil final, shutdown disposable, finalisasi report/checkpoint dan status Git.

## Penutupan Phase 8 — 25 November 2026

Implementasi software dan regresi final selesai. Full SQLite **160 tests / 1488 assertions PASS** (01:27.142), full MySQL disposable **160 tests / 1488 assertions PASS** (01:47.526), PHP 8.3.30 / PHPUnit 12.5.43, exit code 0. Semua 130 tes Phase 7 tetap utuh; 30 tes baru Phase 8 lulus. Log `.runtime/phase8-sqlite-final.txt` dan `.runtime/phase8-mysql-final.txt`. Pint seluruh changed/new PHP dan whitespace PASS. Tidak ada proses tes yang masih berjalan atau kegagalan tersisa.

MySQL dedicated parent 5124/child 18064, port 55364, root descriptor `.runtime/phase8-mysql-8971fbf269de4cc38ba0eab0d7566f70` sudah shutdown normal setelah validasi PID/parent/port/command-line/datadir. Verifikasi akhir **0 matching process / 0 listener**, log **Shutdown complete 2026-11-25 04:59:43 UTC (11:59:43 WIB)**. Data/log tetap ignored sebagai evidence. Jangan menjalankan ulang server atau suite yang sudah lulus tanpa perubahan/failure baru. Operational MySQL tidak disentuh.

Branch tetap `feat/phase8-backend-integration`, HEAD **90451c8f61baba846688341fa0afd0d21ec8d5c1**, **6 tracked modified + 29 untracked**, index kosong. Perubahan tracked hanya AppServiceProvider, EventRecorder, LuxLightingSettings, OperationalConfigurationOutbox, routes/api.php dan routes/console.php. Inventory new files dan kontrak ada di report. AGENTS/Phase 7 report/checkpoint/migrations/tests/deployment service tidak diubah. Flutter/firmware tidak disentuh; dua dokumentasi Flutter modified adalah status existing. Tidak ada stage/commit/push/merge/reset/clean/deploy/live MQTT/real push/relay atau production migration.

Jika dilanjutkan: baca `POULTRIQ_PHASE8_BACKEND_REPORT.md`, terutama tabel klasifikasi dan API/MQTT handoff. Tidak ada implementasi aman dalam lingkup Phase 8 yang masih berjalan. Sisa adalah Flutter integration dan acceptance firmware/hardware/real-provider yang dijelaskan eksplisit, termasuk transport authentication, boot/time/NVS/ACK contract, electrical/calibration/threshold/interlock/fallback, LCD geometry dan genuine feedback. Physical endpoints selalu 409, all outboxes production default blocked, concrete providers disabled; jangan menafsirkan test PASS sebagai izin activation.
