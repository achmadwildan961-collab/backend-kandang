# Phase 8.2 Backend Checkpoint

25 November 2026. Baseline `50e3f84fba214553fd7ee3ea9b7168b32343f357`, working tree awal bersih; branch baru `feat/phase8-2-hardware-readiness`. AGENTS/laporan Phase 7/8/8.1, kontrak lama, services/config/deploy/tests dibaca. Instruksi pengguna mengotorisasi implementasi aman dan branch lokal; tidak ada commit/stage/push/merge/reset/clean/deploy.

| CURRENT | TARGET | GAP / RISK | DEPENDENCY / tindakan |
| --- | --- | --- | --- |
| Telemetry v1 + humidity schema 2 teruji | Kontrak firmware terkunci | Dokumen lama salah minimum outdoor, single humidity/gas outdoor/board type | Lock/schema/fixtures dan changelog eksplisit, tanpa perubahan parser v1 |
| Phase 8 config/ACK hanya fake guarded tests | Staging proposal dan virtual lifecycle | Session auth/NVS belum disahkan; jangan membuka gate produksi | Virtual device/harness memakai services existing; session wire hanya proposal |
| Disabled integration transport | Adapter real terisolasi siap diuji | Broker/credentials/ACL belum tersedia | Adapter terpisah unbound, opt-in localhost virtual-only, TLS/env credentials, config only, no commands |
| Belum ada fixture/schema validator | Deteksi wire drift otomatis | Field names/hash berubah diam-diam | JSON schemas, strict subset validator, fixture hashes + tests actual generator |
| 180 baseline tests | Regresi tetap utuh | Simulator menyentuh database operasional | PHPUnit isolated SQLite/MySQL, CLI preview tanpa DB/network |

Audit broker: `mosquitto`/`docker` tidak ditemukan di PATH; `C:/Program Files/mosquitto/mosquitto.exe` tidak ada; tidak ada proses mosquitto/mysqld. Tidak menghubungi broker operasional. Rencanakan automated opt-in broker smoke dan reproducible isolated setup, laporkan real broker NOT RUN bila tetap tidak tersedia.

Implementasi direncanakan: schemas/lock, virtual devices + CLI preview + fake end-to-end tests; real staging config-only transport unbound tanpa mengubah dispatcher/feedback safety gates; executable contract validation; handoff/security/NVS/control checklist; focused/full SQLite/MySQL/Pint/whitespace; shutdown test DB.

Status final 25 November 2026: pekerjaan backend lokal yang aman selesai dan regresi terverifikasi. Bagian audit/rencana di atas adalah catatan awal; gunakan bukti final di bawah untuk resume. Broker nyata, TLS/ACL jaringan dan hardware belum diuji, sehingga bukan persetujuan aktivasi produksi.

## Progres implementasi dan focused verification

- Implemented: 8 JSON schemas +9 fixtures + versioned lock, strict subset schema validator/offline contract-check, virtual ESP32/NodeMCU + read-only CLI preview, config/NVS-memory/ACK/report/reboot/display harness.
- Real adapter disiapkan `StagingMqttTransport` + factory/client. Default disabled/unbound, local/testing/staging only, literal127.0.0.1, virtual-only opt-in + explicit VIRTUAL_* coop scopes + env credentials, optional verified TLS. Config topics saja; commands/wildcards/foreignscope/bad hash/expiry/unauthorized retained ditolak sebelum connect. Bounded attempts2 cap3, wait PUBACK queue completion, sanitized logs/errors, never device-applied DB mutation.
- Tidak mengubah dispatcher/feedback test-only gates, API/routes, schema migrations, bindings produksi, MQTT v1 parser atau tests existing. Penambahan ContractTime menolak invalid calendar/offset/fraction precision pada simulator/staging config.
- Focused awal 15 tests /123 assertions PASS; setelah lighting-domain/topic lock test 16/133 PASS; focused final dengan timestamp unit tests **18 tests /141 assertions PASS**, 00:03.502, `.runtime/phase8-2-focused-final.txt`. Pint changed/new PHP PASS.
- `firmware:contract-check`: 9 fixtures /8 schemas PASS, termasuk pemeriksaan final setelah normalisasi LF sesuai .gitattributes. Checksum diperbarui eksplisit untuk portabilitas.
- Dokumen contract/changelog/checklist/local testing dan root handoff dibuat. Legacy hardware/MQTT docs diberi superseded notice tanpa menghapus sejarah. Semua FIRMWARE_SYNC_REQUIRED memberi before/after, board/topic/fields, Arduino responsibility, test/backward compatibility/approval. Session/display wire hanya proposal, tidak ada live receiver/publisher.
- Broker nyata **NOT RUN**: tidak ada executable mosquitto/docker/process terdeteksi; setup loopback + opt-in `firmware:broker-smoke` tersedia. Mocked client tests bukan bukti broker/TLS/ACL live.
- Rencana verifikasi tersebut sudah dituntaskan sebagaimana bukti berikut. Normal shutdown MySQL tidak dapat dibuktikan; instance sudah tidak berjalan saat resume, jangan menyalakan ulang hanya untuk menghasilkan shutdown log.

## Bukti final dan titik resume

| Pemeriksaan | Hasil aktual / log lokal ignored |
| --- | --- |
| Focused SQLite | 18 tests /141 assertions PASS, 00:03.502; `.runtime/phase8-2-focused-final.txt` |
| Focused MySQL disposable | 18 tests /141 assertions PASS, 00:10.799; `.runtime/phase8-2-mysql-focused.txt` |
| Full SQLite | 198 tests /1821 assertions PASS, 00:29.916; `.runtime/phase8-2-sqlite-final.txt` |
| Full MySQL disposable | 198 tests /1821 assertions PASS, 02:18.806; `.runtime/phase8-2-mysql-final.txt` |
| Pure unit suite | 6 tests /193 assertions PASS, 00:00.099; `.runtime/phase8-2-unit-final.txt` (tercakup full suite) |
| Contract CLI | 9 fixtures /8 schemas verified, hardware approval=false |
| Preview CLI | Indoor 11 channels, Outdoor 3 channels; simulated=true, physical_feedback=false; `.runtime/phase8-2-preview-{indoor,outdoor}.json` |
| Pint / whitespace | PASS untuk seluruh PHP baru; `git diff --check` PASS |
| Baseline | 180 tes existing tidak diubah; full suite =180 existing +18 baru. Tracked source/routes/migrations/tests/config integrasi tetap sama dengan baseline |

MySQL 8.4.3 disposable menggunakan `127.0.0.1:58663`, database `smart_kandang_test`, root `.runtime/phase8-2-mysql-6f3c9b755bbc409a88c13dda45acc54a`, parent PID14944/server PID10468. Identitas proses, port dan `@@datadir` diperiksa sebelum tes. Pada resume final **25 November 2026 23:31:23 WIB (16:31:23 UTC)**, proses yang cocok=0 dan listener port=0; `.runtime/phase8-2-mysql-final-state.json` menyimpan hasil pemeriksaan. Server log tidak memuat `Shutdown complete`, sehingga penghentian normal tidak diklaim dan penyebab berhentinya tidak disimpulkan. Tidak menjalankan ulang instance, menghentikan service lain, atau menghapus datadir/log.

Git final: branch `feat/phase8-2-hardware-readiness`, HEAD `50e3f84fba214553fd7ee3ea9b7168b32343f357`, **4 tracked modified +39 untracked files =43 files**, index kosong. Tidak commit/stage/push/merge/reset/clean/deploy. Tidak mengubah Flutter/Arduino/production infrastructure. Semua physical commands, production MQTT/push bindings dan session/feedback gates tetap disabled.

Tidak ada implementasi aman yang tertunda dalam scope adapter/simulator/contract ini. Resume berikutnya memerlukan lingkungan broker disposable untuk smoke QoS1/observer dan TLS/ACL jaringan; kemudian keputusan authenticated session, NVS/firmware dan safety acceptance pada checklist. Jangan mengulang implementasi atau membuka gate untuk membuat demonstrasi live. Lihat report, firmware handoff dan changelog untuk klasifikasi NO_FIRMWARE_CHANGE / FIRMWARE_SYNC_REQUIRED / FIRMWARE_DECISION_REQUIRED serta exact Arduino updates.
