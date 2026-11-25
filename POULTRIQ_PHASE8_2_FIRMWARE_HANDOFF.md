# Phase 8.2 Firmware Handoff

**STAGING PROPOSAL, belum hardware approval. Tidak ada Arduino/Flutter yang diedit. Tidak ada physical command activation.**

## PERUBAHAN BACKEND YANG WAJIB DISAMAKAN DI CODING ARDUINO

Firmware yang mengikuti dokumen lama wajib menyesuaikan lima humidity DHT22, gas indoor ADC, BH1750 Outdoor lux, minimum/maksimum indoor, independent config version/hash/expiry, serta scoped ACK/report/boot/sequence. Ini kontrak baseline Phase7/8/8.1 yang kini dikunci dan didokumentasikan, bukan perubahan parser existing secara diam-diam. Session enrollment dan display topic tetap proposal DECISION_REQUIRED. Rincian before/after, per-board topics/fields, Arduino actions, compatibility test dan approval ada di [changelog](docs/firmware/POULTRIQ_FIRMWARE_CHANGELOG.md).

Paket yang diserahkan:

1. [Kontrak lengkap](docs/firmware/POULTRIQ_FIRMWARE_CONTRACT_V1.md): tabel topic/direction/QoS/retained kedua board, semua sensor identifiers/unit/range, field required/optional/type, full JSON examples, timestamp/idempotency, config/ACK/report/session/LCD, NVS dan control behavior.
2. [Contract lock](contracts/firmware/contract-lock.json): package version, baseline, approval=false, topic manifest, sensor definitions, schemas/fixtures SHA256. Jangan refresh checksum otomatis untuk menyembunyikan wire drift.
3. [Checklist sign-off](docs/firmware/POULTRIQ_HARDWARE_HANDOFF_CHECKLIST.md): exact pending hardware/security/NVS/relay/LCD/push decisions dan mandatory firmware synchronization checklist.
4. [Simulator dan broker testing](docs/firmware/LOCAL_TESTING.md): preview CLI tanpa DB/network, deterministic virtual harness, config-only loopback adapter dan automated optional broker smoke.
5. [Laporan backend](POULTRIQ_PHASE8_2_BACKEND_REPORT.md) dan [checkpoint](POULTRIQ_PHASE8_2_BACKEND_CHECKPOINT.md): hasil aktual, batas verifikasi, status branch/Git dan pemulihan.

Tim Arduino harus membaca JSON numbers tanpa kehilangan versi integer, bool/null tanpa coercion 0/1/string, memvalidasi unknown fields/scope/unit/expiry, menghitung atau menguji hash encoding dengan vectors, serta mengirim semua lima channel yang valid pada message/timestamp sama. NVS apply/readback dan safety authority tetap memerlukan implementasi/acceptance firmware. Pending pin/relay polarity/interlock/filter/threshold/anti-short-cycle/LCD decisions tidak diisi angka contoh.

Backend software lokal siap dijadikan referensi pengembangan dan simulasi, **belum siap mengaktifkan relay atau mengklaim live device delivery**. Production bindings disabled; push credentials/mobile delivery belum ada. PUBACK/ACK/applied/GPIO/physical feedback adalah lima bukti berbeda. Gunakan acceptance checklist untuk menentukan langkah live berikutnya secara terpisah.
