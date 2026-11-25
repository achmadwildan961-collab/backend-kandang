# MQTT Phase 7 — kompatibilitas dan batas aktivasi

> **Arsip Phase7, superseded untuk firmware handoff oleh [kontrak Phase8.2](docs/firmware/POULTRIQ_FIRMWARE_CONTRACT_V1.md).** Usulan namespace `poultriq/v2`, LDR dan humidity belum pasti di bawah bukan kontrak yang diimplementasikan. Current telemetry tetap smart-kandang v1, draft integration memakai integration/v1, target light BH1750 lux dan humidity lima DHT22 sudah diimplementasikan Phase8.1. Riwayat dokumen dipertahankan; gunakan changelog/lock terbaru untuk sinkronisasi Arduino.

Status 24 November 2026: **fondasi software lokal; bukan kontrak firmware yang disahkan**.

> Revisi resmi BH1750 24 November 2026 mengganti target LDR. Rujukan LDR/ADC cahaya pada rencana awal di bawah adalah **LEGACY/DEPRECATED**. Addendum BH1750 di akhir dokumen mengatur kontrak terbaru; riwayat ADC tetap dipertahankan tanpa konversi.

## IMPLEMENTED

- Subscriber v1 tetap memakai `{prefix}/coops/{coop}/devices/{device}/telemetry|status`, default prefix `smart-kandang`. Envelope tepat `message_id/sent_at/readings` atau `message_id/sent_at/status` tetap berlaku. Retained telemetry/status ditolak; dedup dan pemetaan device/coop dipertahankan.
- Ingest baru memberi `batch_key=sha256(message_id)` pada reading. Identitas efektif adalah coop + device + timestamp + batch_key. Pesan partial terpisah tidak dapat saling melengkapi meski waktunya sama. Batch invalid ditolak atomik, partial valid disimpan sebagai diagnosis.
- Settings v2 adalah perluasan REST `/api/v1/.../settings`; **bukan MQTT schema v2 aktif**. Save versioned menyimpan draft outbox operasional secara transaksional per device terdaftar pada coop itu. Key idempotensi `sha256(operational:{coop_id}:{device_id}:{config_version})`, unique target/version, payload immutable. Retrying version stale mendapat 409; client GET untuk mengetahui apakah save sebelumnya sudah tersimpan.
- Outbox operasional selalu `blocked/firmware_contract_unverified`; tidak ada topic, transport, worker, atau flag konfigurasi yang dapat mengaktifkan publish. Draft hanya berisi minimum indoor nullable, maksimum indoor, gas ADC, identitas dan versi. Tidak membawa minimum outdoor sebagai nilai indoor, lux atau ppm fiktif, maupun instruksi relay.
- Status API membedakan `persistence=stored`, `delivery=disabled`, outbox `blocked/not_queued`, target_count, `applied_config_version=null`, `device_applied=false`. ACK MQTT/online heartbeat tidak pernah mengubah applied.

## Rencana adapter v2 — PENDING VERIFICATION

Semua nama di bawah adalah **usulan namespace**, bukan subscription/publication baru. Prefix versi baru terpisah, contoh `poultriq/v2/coops/{coopId}/devices/{deviceIdentifier}/...`. Jangan gunakan topik flat target lintas kandang. Identity payload harus cocok dengan topic, registered binding, device role dan ACL.

| Target pemilik | Suffix usulan scoped | Kontrak yang perlu disahkan |
| --- | --- | --- |
| `coop/outdoor/telemetry` | `outdoor/telemetry` | NodeMCU: AHT20 celsius/percent, LDR raw ADC + measurement/calibration version; tidak otomatis lux |
| `coop/indoor/telemetry` | `indoor/telemetry` | ESP32: batch 5 temperature sensor ID + value + validity; bentuk 5 humidity belum pasti; MQ135 raw ADC, bukan gas_ppm |
| `coop/config/threshold` | `config/threshold` | schema/config version, message ID, target device/coop, timestamps/validity, units/calibration, min nullable/disabled; retained hanya setelah acceptance |
| `coop/indoor/actuator/set` | `indoor/actuator/set` | NON-retained, command ID, boot/session, expiry, monotonic sequence, actor/mode, anti-replay, satu otoritas otomatis |
| `coop/indoor/actuator/status` | `indoor/actuator/status` | ACK ID/config version/command ID, applied/rejected reason, reported timestamp/sequence, heater/fan1/fan2/lighting terpisah; feedback_kind eksplisit |
| `coop/outdoor/display` | `outdoor/display` | data/unit, generated_at/expires_at, unavailable/stale, reported state hanya jika valid; LCD ukuran/alamat belum pasti |

Adapter v2 kelak harus menormalisasi ke pipeline validasi/aggregasi yang sama, bukan mempercayai `temp_avg/hum_avg` firmware. Jalankan contract tests dual-read, dedup antar-protokol dengan canonical identity yang disahkan, dan per-device opt-in sebelum migrasi. Jangan berlangganan dua protokol untuk satu sumber tanpa strategi cross-protocol dedup. Monitoring v1 tetap bekerja hingga cutover disetujui; status v1 hanya online/offline, bukan relay ACK.

## Rencana delivery/retry/ACK/offline — NOT IMPLEMENTED

1. Konfirmasi ID/role board, ACL/TLS, units, timing, firmware schema, NVS, config lifetime dan otoritas kontrol. Jangan rename ID karena namanya mengandung ESP32. Allowlist `mqtt.device_sensors` tetap opt-in untuk legacy; binding sensor fisik belum dapat disimpulkan dari type/name.
2. Buat versi konfigurasi baru dari settings terkini setelah validasi hardware; jangan membuka/drain outbox blocked lama atau lighting blocked. Device baru belum otomatis mempunyai draft; jalur rekonsiliasi terotorisasi harus membuat snapshot terbaru.
3. Worker baru kelak claim row dengan lease/lock dalam transaksi, publish setelah commit, per-device version ordering; sebelum setiap retry cek current binding/version/eligibility. Reassignment atau penghapusan device membatalkan target lama. Supersede draft lama dan jangan membiarkan retained config mundur ke versi sebelumnya.
4. Retry transient failure dengan bounded exponential backoff/jitter dan key/payload yang sama. Crash setelah publish sebelum persist dapat menghasilkan at-least-once delivery: firmware wajib dedup dan apply monotonik. Jangan menjanjikan exactly-once. Dead-letter/manual review untuk rejection permanen atau retry exhaustion.
5. Broker PUBACK hanya `published`, belum `applied`. Applied hanya setelah ACK terautentikasi cocok coop/device/message/version, bukan stale/duplicate/replay/rejected. Firmware menyimpan config valid di NVS secara atomik; definisikan reboot/boot identity, reported config hash, serta replay lama setelah reboot. Perangkat yang berbeda mempunyai applied version masing-masing; jangan menyatukan ACK satu device menjadi applied seluruh kandang.
6. Offline tidak berarti applied atau OFF. Pertahankan desired/stored terpisah dari reported; reconnect rekonsiliasi versi terakhir yang sah, bukan mengirim semua backlog. Config expired/rejected membutuhkan aturan fault/fallback yang disahkan. Perintah actuator ber-expiry tidak diulang setelah expiry/reboot tanpa keputusan baru.
7. Mock transport + disposable database integration: crash windows, concurrent worker, duplicate/reordered ACK, wrong scope, offline/reconnect, stale retained config, invalid unit, revoked authorization dan physical acceptance sebelum activation terpisah.

## Control dan keselamatan

MQ135 ADC tetap dibandingkan dalam ADC untuk monitoring legacy. Nilai default monitoring historis bukan ambang keselamatan terverifikasi. Tidak ada smoothing window, ppm conversion, lux conversion atau gas override relay yang dibuat tanpa spesifikasi kalibrasi.

Preview demand suhu hanya fungsi murni; input harus berasal dari batch tervalidasi dan threshold terkonfigurasi. GasSafetyDemand harus lebih dahulu dihitung dengan kontrak unit/kalibrasi/threshold sah oleh caller masa depan. OR gas dan demand suhu tidak dapat dimatikan oleh aturan suhu normal. Ini bukan perintah heater/fan, dan tidak menentukan interlock heater + ventilasi. Lighting tetap jalur terpisah dan commands 409.

Belum disahkan: hysteresis/debounce/recovery gas, minimum runtime/anti-short-cycle, heater/fan interlock, fail-safe invalid/stale/missing, edge vs backend control authority, active polarity, power/boot state, NVS, time sync, relay/physical feedback. Semua automation tetap disabled.

## BH1750 Sensor Contract Revision — 24 November 2026

- Target Outdoor NodeMCU kini AHT20/BH1750/LCD I2C D2/D1. BH1750 lux tidak membutuhkan konversi LDR ADC; tidak ada rumus konversi historis. Range/mode/timing aktual, 0x23/0x5C, voltage/pull-up/bus dan identity memerlukan acceptance hardware.
- MQTT v1 tetap topic scoped dan envelope `message_id/sent_at/readings`. Reading baru `{ "sensor_identifier": "outdoor_light_lux", "value": 120 }`; nilai contoh hanya fixture, bukan ambang aman. Unit inferred `lux`, tag `unit:lux` opsional hanya pada identifier baru; unit salah atau field metadata lain ditolak. Backend menetapkan measurement_version `bh1750_lux_v1`, type outdoor_light dan model BH1750.
- Ingest memerlukan `BH1750_TELEMETRY_VERIFIED=true`, explicit `BH1750_DEVICE_IDENTIFIERS`, registered type `nodemcu_esp8266`, scope coop/device cocok, dan allowlist mqtt.device_sensors bila diaktifkan. Default belum opt-in; tidak ada environment live yang diubah. Gate legacy LIGHTING_* tidak mengotorisasi BH1750.
- Identifier `outdoor_light_adc` dan raw history lama tetap ADC/LEGACY. Canonical lux latest/chart hanya dari source binding sah dan measurement version yang sesuai. Tidak menggabungkan ADC ke seri lux.
- REST lighting schema 2 sudah menampung `auto/manual`, min_lux/max_lux nullable dengan min < max, threshold_unit lux, expected_config_version dan blocked draft outbox terpisah dari ADC schema 1. GET wajib memilih `?schema_version=2`; PUT memakai `schema_version:2` dan `measurement_version:bh1750_lux_v1`. Lux config tersimpan bukan device applied; physical commands tetap false/409.
- Payload lux config masih draft internal, bukan firmware wire contract. Tidak ada live subscriber baru, retained config, relay command, ACK receiver atau LCD publication. Aturan retry/ACK/offline di atas tetap rencana, dan jangan drain outbox legacy maupun lux blocked.
