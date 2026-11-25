# Phase 8.1 Backend Checkpoint

25 November 2026. Baseline terverifikasi `37781e151beb4c0c0974cf053936b65301f649e6`, working tree awal bersih. Branch baru `feat/phase8-1-humidity-contract`. Instruksi lampiran Phase 8.1 mengotorisasi implementasi additive; tidak perlu persetujuan ulang terhadap rencana yang sudah diminta.

**STATUS FINAL: implementasi dan verifikasi lokal selesai. Tidak ada task software-ready Phase 8.1 wajib tersisa. Full SQLite dan MySQL masing-masing 180 tests / 1680 assertions PASS. MySQL disposable sudah shut down, 0 matching process / 0 listener. Bagian pemulihan di bawah adalah kronologi; jangan mengulang pekerjaan selesai.**

## Audit dan rencana kompatibilitas

| CURRENT | TARGET | GAP / RISK | IMPLEMENTED / PENDING |
| --- | --- | --- | --- |
| Indoor humidity kanal tunggal legacy | Lima DHT22 humidity terpisah, aggregate satu batch | Jangan reinterpretasi legacy atau menggabung pesan | Implemented/tested: lima identifier, explicit mapping, measurement provenance; firmware sampling pending |
| Outdoor humidity percent tanpa model terverifikasi | AHT20 pada registered Outdoor NodeMCU | Board label saja bukan bukti model | Implemented/tested: explicit sensor allowlist + version stamped saat ingest; physical registration pending |
| Snapshot/chart humidity legacy; CSV lima kolom | Enam metrik independen | Mengubah field default memutus konsumen lama | Implemented/tested: additive metadata/aggregate dan schema_version=2 eksplisit; Flutter adapter pending |
| Phase 7/8 160 tests | Semua tetap utuh | Jangan melemahkan tes lama | Full SQLite/MySQL: 180 tests / 1680 assertions PASS; 160 existing unchanged |

Desain: gunakan storage sensor_readings generik dan kolom batch_key/measurement_version yang sudah ada; tidak backfill atau rename data. MQTT v1 envelope/receipt/scope dipertahankan. Lima kanal baru wajib explicit mqtt.device_sensors allowlist dan ESP32; outdoor legacy tetap kompatibel tetapi hanya mapping NodeMCU + provenance baru yang boleh disebut AHT20. Snapshot schema 1 dan CSV schema 1 mempertahankan arti lama; schema 2 menyediakan indoor_humidity resmi dan outdoor terverifikasi. Semua integrasi eksternal tetap disabled.

## Pemulihan

- AGENTS backend, laporan Phase 7/8 dan source/tests terkait dibaca. Tidak ada AGENTS nested ditemukan.
- Tidak ada proses mysqld.exe saat audit awal. Disposable Phase 8 sebelumnya telah ditutup; database operasional tidak diakses.
- Flutter tidak disentuh; existing modified AGENTS.md dan POULTRIQ_ARCHITECTURE_GAP_AUDIT.md dipertahankan.
- Implementasi awal selesai: catalog, parser unit percent/%, explicit ESP32 mapping, server-stamped DHT22/AHT20 provenance, strict humidity aggregate, additive snapshot/schema 2, chart/CSV schema 2, LCD, raw history legacy label. Tidak perlu migration baru karena schema generic dan kolom provenance sudah tersedia.
- Focused pertama: 17 tests / 164 assertions, satu kegagalan fixture inactive memakai field is_active yang tidak ada. Diperbaiki menjadi status=inactive pada tes baru; tes lama tidak disentuh.
- Focused kedua SQLite: **17 tests / 164 assertions PASS**, 2.540 detik, `.runtime/phase8-1-focused-2.txt`. Pint source baru/berubah sudah diformat.
- Resume memverifikasi branch/HEAD/perubahan masih sama, report belum tersedia, tidak ada mysqld. Dokumentasi pending pada matriks awal adalah rencana historis, bukan status source terkini.
- Review tambahan: cegah humidity/AHT20 future sample menjadi historical official sebelum waktu sampelnya; LCD memakai schema 2 agar salah source tidak tampil sehat. Tiga tes tambahan ditulis (total baru 20); hasil 17 tests di atas belum mencakup perubahan review ini.
- Langkah selanjutnya: focused 20 tests, full SQLite, buat MySQL disposable baru dan verifikasi PID/port/datadir sebelum focused/full MySQL; shutdown normal; laporan dan checkpoint final.

## Verifikasi sebelum full regression

- Focused final SQLite: **20 tests / 192 assertions PASS**, 01:07.999; `.runtime/phase8-1-focused-final.txt`.
- Focused MySQL: **20 tests / 192 assertions PASS**, 00:52.390; `.runtime/phase8-1-mysql-focused.txt`.
- MySQL disposable baru: port **57770**, parent PID **4620**, child/listener PID **5616**, datadir `.runtime/phase8-1-mysql-fd04def71a0545d1ac3fc08b8d8b70b4/data`. Inisialisasi `--no-defaults --initialize-insecure`, loopback only, mysqlx off; PID/parent/commandline/port dan `SELECT @@datadir` cocok sebelum CREATE database smart_kandang_test.
- Descriptor resume: `.runtime/phase8-1-mysql-instance.json`. Jangan menebak instance atau menghentikan proses MySQL generik. Verifikasi ulang PID/listener/root sebelum shutdown normal memakai mysqladmin.
- Full SQLite dan full MySQL berjalan terpisah; log `.runtime/phase8-1-sqlite-final.txt` dan `.runtime/phase8-1-mysql-final.txt`. Jangan menjalankan dua suite terhadap MySQL datadir yang sama secara bersamaan.
- Laporan/handoff dibuat: `POULTRIQ_PHASE8_1_BACKEND_REPORT.md`. Masih perlu mencatat full regression aktual, review lint/Git dan shutdown disposable.

## Penutupan final — 25 November 2026

- Full SQLite **180 tests / 1680 assertions PASS**, 00:51.447, exit 0; full MySQL **180 tests / 1680 assertions PASS**, 02:26.984, exit 0. Log final sesuai nama di atas.
- PHP 8.3.30 / PHPUnit 12.5.43. Pint changed/new PHP dan git diff --check PASS. Semua tracked tests, migrations, laporan Phase 7/8 identik baseline (git diff exit 0). Tidak ada test lama yang diubah atau dihapus.
- Shutdown dedicated MySQL memakai mysqladmin setelah PID/port/datadir diperiksa lagi. Log Shutdown complete **09:53:42 UTC / 16:53:42 WIB**. Verifikasi **0 matching process, 0 listener port 57770**. Jangan restart instance ini kecuali ada perubahan source baru yang memerlukan tes MySQL; seluruh verifikasi yang diminta selesai.
- Branch `feat/phase8-1-humidity-contract`, HEAD `37781e151beb4c0c0974cf053936b65301f649e6`, **11 tracked modified + 6 untracked**, index kosong. Tidak stage/commit/push/merge/reset/clean/deploy. Tidak ada operational DB/service/broker/real push/relay yang disentuh.
- Flutter/firmware tidak diedit. Status Flutter existing tetap dua dokumen modified. Semua physical integrations disabled; config simulation=false dan DisabledMqttTransport/DisabledPushProvider bindings dipertahankan.
- Dokumen report memuat matriks compatibility, exact API fields, contoh JSON, MQTT mapping/envelope, history/chart/CSV schema1/2, endpoint settings/lighting/integration/push dan blockers fisik.
- Kelanjutan berikutnya **bukan implementasi ulang backend**: integrasi Flutter atas schema 2 dan version conflicts/token lifecycle, lalu acceptance firmware/hardware terpisah. Tidak ada kewajiban commit/publish untuk menyelesaikan task lokal ini.

```text
 M AGENTS.md
 M app/Http/Controllers/Api/SensorController.php
 M app/Http/Requests/ChartHistoryRequest.php
 M app/Http/Resources/SensorReadingResource.php
 M app/Services/Integration/LcdPayload.php
 M app/Services/Mqtt/PayloadValidator.php
 M app/Services/Mqtt/TelemetryIngestor.php
 M app/Services/SensorAggregationService.php
 M app/Services/SensorCatalog.php
 M app/Services/SensorHistoryService.php
 M config/mqtt.php
?? POULTRIQ_PHASE8_1_BACKEND_CHECKPOINT.md
?? POULTRIQ_PHASE8_1_BACKEND_REPORT.md
?? app/Services/HumidityAggregation.php
?? app/Services/HumidityContract.php
?? app/Services/MonitoringContract.php
?? tests/Feature/HumidityContractTest.php
```
