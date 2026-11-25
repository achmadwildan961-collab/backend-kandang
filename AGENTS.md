# POULTRIQ — AGENTS.md (Backend Laravel)

> **Status:** Spesifikasi arsitektur TARGET dari pemilik proyek, diperbarui 25 November 2026 (Phase 8.1 humidity). Pedoman ini menggantikan **asumsi lama yang bertentangan**, tetapi tidak berarti hardware/firmware sudah tervalidasi atau command MQTT sudah aktif.
>
> **Workspace:** `D:\FlutterProjects\smart-kandang-backend` — repository `achmadwildan961-collab/backend-kandang`.
>
> **Ruang lingkup:** Audit dan perubahan PHP/Laravel: routes, Controller, Request, Policy, Model, migration, Service, MQTT subscriber/publisher, scheduler, queue worker, notifikasi, database tests. **Jangan mengedit Flutter atau firmware dari workspace ini**.

## 1. Aturan operasional untuk Codex

1. **Baca file ini sebelum bekerja.** Anggap ketentuan hardware/node dan semantik bisnis di sini sebagai **target resmi terbaru**. Bandingkan dengan source backend sebenarnya sebelum menyentuh kode.
2. **Mulai dengan AUDIT** `CURRENT / TARGET / GAP / RISK / DEPENDENCY / PROPOSED MIGRATION`. Bila permintaan hanya audit atau penyusunan pedoman, jangan mengedit implementation. Untuk perubahan besar skema/API/MQTT/aktuator, ajukan rencana dan minta persetujuan eksplisit.
3. **Jangan membongkar fitur yang sudah stabil.** Endpoint settings, auth/Sanctum, RBAC, five-DHT22 aggregation, history, CSV, alert transitions, device/coop isolation, MQTT v1 dedup, dan notifikasi perlu tetap bekerja selama migrasi.
4. **Jangan menyalakan hardware secara prematur.** Program publish/config retained, command, ACK/reported-state, offline fail-safe dan ACL harus disetujui, diimplementasikan, diuji di staging, dan diterima tim hardware sebelum live. Existing `POST /lighting/commands` saat ini sengaja mengembalikan 409.
5. **BH1750 menghasilkan lux; MQ135 tetap raw ADC, bukan ppm.** Data LDR/ADC lama adalah **LEGACY/DEPRECATED**, tidak boleh ditafsirkan atau dikonversi sembarang menjadi lux. Jangan mengarang angka ambang aman.
6. **Jangan commit, stage, push, merge, PR, deploy, mengubah VM/DB/broker produksi, atau menjalankan migrasi produksi tanpa izin eksplisit terbaru.** Hindari reset/clean/destructive rollback. Utamakan tes terisolasi.
7. Semua perubahan operasional tetap otorisasi **Admin-only**, User assigned read-only, kecuali keputusan RBAC terbaru disahkan. Audit auth, device ownership, coop scope, dan idempotency sebelum mengizinkan konfigurasi/command.
8. Tulis progres dan hasil audit/tes secara faktual dalam bahasa Indonesia. Jangan mengklaim konektivitas IoT live berdasarkan mock/unit tests.

## 2. Topologi hardware — keputusan TERBARU

| Node | Board | Sensor/IO | Tugas |
| --- | --- | --- | --- |
| **Outdoor** | **NodeMCU ESP8266** | AHT20 dan **BH1750 (lux)** via I2C (D2 SDA, D1 SCL), LCD I2C 16×2 **atau** 20×4 | Suhu/kelembapan luar, cahaya lux, tampilan suhu indoor/outdoor, gas dan status aktuator |
| **Indoor** | **ESP32 DevKit** | 5× DHT22, MQ135 analog **ADC1** (contoh GPIO34), relay `heating_lamp`, `ventilation_fan_1`, `ventilation_fan_2`, `lighting_lamp` | Sensor/aktuator utama; simpan salinan konfigurasi valid terakhir di NVS `Preferences.h` |

Jangan membalik board. GPIO output aktual, active polarity, relay interlock, LCD ukuran/alamat, dan ID perangkat harus disahkan oleh tim firmware. Jangan ubah ID perangkat terdaftar berdasarkan nama board.

**BH1750 Sensor Contract Revision — 24 November 2026:** keputusan pemilik mengganti target LDR analog A0 dengan BH1750 digital I2C. AHT20, BH1750 dan LCD berbagi bus bila kompatibel secara elektrik. Alamat BH1750 `0x23` atau `0x5C` menurut ADDR; alamat aktual, tegangan, pull-up dan kompatibilitas bus masih harus diverifikasi. LDR A0 bukan lagi hardware target. Firmware BH1750, pengukuran aktual, timing/mode dan identitas perangkat belum diverifikasi. Semua physical relay commands tetap disabled.

## 3. Semantik operasional TARGET — prioritas tertinggi

### 3.1 Temperatur indoor, bukan outdoor

- **`min_temp` dan `max_temp` keduanya batas suhu INDOOR**, berdasarkan rata-rata valid DHT22 indoor.
- **Outdoor AHT20 (`temp_out`) hanya parameter referensi/grafik ΔT**, bukan batas alarm/kontrol pemanas/kipas.
- Target `delta_temp = avg_temp_indoor - temp_outdoor`, hanya dengan sample valid, fresh, sebanding waktu; gunakan null/partial metadata bila syarat tidak terpenuhi.
- **HISTORIS sebelum Phase 7 (backend `dev` lama):** `SettingsController`, `CoopSetting`, validation/resource/alert memakai `minimum_outdoor_temperature` dan `maximum_indoor_temperature`. Phase 7 sudah menambahkan minimum indoor nullable, version/CAS dan memensiunkan rule minimum outdoor; lihat laporan Phase 7. Jangan mengulang migrasi atau menghidupkan rule outdoor historis.
- **Wajib rencana migrasi terkoordinasi**: definisi kolom/field baru, versioning kontrak, aturan backfill tanpa mengartikan ulang nilai minimum outdoor sebagai indoor, kompatibilitas klien, migration/rollback-safe, validasi `min_temp < max_temp`, tes. Jangan rename field/kolom lama tanpa migrasi dan keputusan pemetaan data lama.

### 3.2 Lima DHT22 dan agregasi

- **Kontrak resmi Phase 8.1, 25 November 2026:** ESP32 Indoor memiliki lima DHT22, masing-masing mengukur suhu dan kelembapan relatif. Temperatur indoor resmi adalah rata-rata lima temperatur valid; humidity indoor resmi adalah rata-rata lima humidity valid dalam unit `%` (wire/storage canonical `percent`). Outdoor AHT20 memiliki temperatur dan humidity tersendiri; jangan pernah merata-ratakan humidity indoor dan outdoor bersama.
- Identifier suhu existing `indoor_dht22_1` sampai `indoor_dht22_5`; pasangan humidity `indoor_dht22_1_humidity` sampai `indoor_dht22_5_humidity`. Lima nilai humidity harus finite 0–100, fresh, exact timestamp, single registered ESP32, single MQTT message batch. Batch suhu lengkap tidak membuktikan humidity lengkap. Partial hanya diagnostik; jangan gabung antar-batch, jangan substitusi kanal `indoor_humidity` legacy sebagai average lima sensor.
- Kanal humidity baru wajib explicit `mqtt.device_sensors` allowlist. Outdoor AHT20 verified memerlukan explicit allowlist sensor + NodeMCU dan measurement provenance saat ingest; label board saja bukan bukti AHT20. Empty legacy mapping tidak boleh memberi provenance baru secara retroaktif. Physical mapping tetap perlu acceptance hardware.
- Tolak/saring `null`, `NaN`, infinite, sensor invalid, timestamp salah, duplicate, stale, missing, dan out-of-order sesuai kontrak ingest yang berlaku.
- **Existing behavior:** rata-rata indoor yang terverifikasi memerlukan lima nilai fresh valid dalam batch bertimestamp sama. Pertahankan fail-safe ini untuk kontrol sampai ada keputusan tertulis soal partial readings; data partial boleh ditandai untuk tampilan, bukan dipakai diam-diam untuk heater/fan.
- Penyediaan `temp_avg`/`hum_avg` dari firmware tidak boleh otomatis menggantikan agregasi yang diaudit server; validasi consistency dan sensor cardinality.
- Logika TARGET jika input sah & perangkat diizinkan:
  - `avg_temp < min_temp` → demand heater aktif, demand kipas suhu nonaktif.
  - `avg_temp > max_temp` → demand 2 kipas aktif, heater nonaktif.
  - `min_temp <= avg_temp <= max_temp` → heater dan demand kipas suhu nonaktif.
- Pisahkan **demand** dari status relay yang telah dilaporkan; jangan mengklaim ON hanya dari keputusan server.
- Tetapkan fail-safe, anti-short-cycle, minimum runtime dan interlock sebelum aktivasi fisik.

### 3.3 MQ135, risiko amonia, prioritas ventilasi

- MQ135 Indoor pada ESP32 ADC1, gunakan moving average atau filter yang tervalidasi dengan jendela/waktu sampel yang disepakati.
- **Gas mentah ADC bukan amonia ppm** tanpa kalibrasi MQ135, R0, faktor gas silang, kompensasi, versi/unit/rentang, dan bukti pengujian. `gas_ppm` dalam contoh payload pemilik adalah TARGET setelah kalibrasi, **bukan nilai yang boleh difabrikasi**.
- `max_gas` dan `gas_value` harus dalam unit sama; jangan mengaktifkan bahaya/override memakai perbandingan unit yang berbeda.
- Jika gas melampaui batas terverifikasi: demand ventilasi aktif dan notifikasi peringatan ditargetkan; `FanDemand = TemperatureDemand OR GasSafetyDemand`. Permintaan normal suhu tidak boleh mematikan fan selama gas override aktif.
- Lakukan recovery dengan debounce/hysteresis yang disepakati. Prioritas interlock heater+fan dan fallback sensor rusak harus diverifikasi; jangan mengasumsikan kontrol saat fault.
- Bedakan event server, penyimpanan notifikasi, dan **push delivery** (FCM/provider sebenarnya). Jangan mengklaim push mobile berfungsi jika provider/device token belum diverifikasi.

### 3.4 Cahaya BH1750 dan lampu penerangan

- BH1750 pada NodeMCU Outdoor memakai **I2C, bukan A0 analog**, dengan unit **lux**. Identifier canonical `outdoor_light_lux`, sensor type `outdoor_light`, sensor model `BH1750`. Validasi finite/nonnegative/range dan binding device; jangan menerima sumber ESP32 Indoor sebagai BH1750 Outdoor.
- Target `lighting_mode`: `auto`/`manual`, `min_lux` dan `max_lux` dalam lux, **min_lux < max_lux**. Threshold baru tidak dikonfigurasi sampai disimpan eksplisit; jangan backfill dari ADC atau memakai contoh sebagai default aman.
- Target hysteresis otomatis: `lux < min_lux` → demand nyala; `lux > max_lux` → demand mati; di antara dan tepat pada batas pertahankan state sebelumnya. Wajib aturan stale/missing/fault/reboot sebelum aktivasi fisik.
- Manual hanya dari Admin aktif yang berhak atas kandang, melalui command pipeline terversi dan ACK/reported status yang terverifikasi.
- **JANGAN campur** `lighting_lamp` (penerangan) dengan `heating_lamp` (pemanas).
- **LEGACY/DEPRECATED:** schema 1 `GET /lighting` dan storage lama memakai `outdoor_light_adc`, `dark_threshold/bright_threshold`, unit ADC dan mode `automatic/manual`. Pertahankan riwayat tersebut dengan label legacy; jangan reinterpretasi menjadi BH1750/lux. Kontrak lighting schema 2 memakai field lux baru dan draft/outbox terpisah. `configuration=false`, `commands=false`; `POST /lighting/commands` tetap 409 sampai firmware, ACK/feedback dan safety acceptance disahkan.

## 4. Alur TARGET konfigurasi (tidak boleh dianggap sudah aktif)

`Flutter Admin -> Laravel REST -> validasi/RBAC/version check -> DB + transactional outbox -> MQTT publisher -> device apply/ACK -> reported-state -> REST/Flutter`.

Target pengaturan:

| Nama target | Makna | Unit |
| --- | --- | --- |
| `min_temp` | Minimum INDOOR | °C |
| `max_temp` | Maximum INDOOR | °C |
| `max_gas` | Gas maksimum | ADC sementara; ppm setelah kalibrasi |
| `min_lux`, `max_lux` | Hysteresis penerangan BH1750, minimum < maksimum | lux; pengukuran fisik/timing dan threshold operasional masih perlu acceptance |
| `lighting_mode` | `auto`/`manual` | versi/skema perlu disepakati |

- Endpoint save settings **sudah ada**; audit dan perluas secara kompatibel, jangan buat ulang dari nol. Legacy `SettingsController` menyimpan monitoring settings dan menjalankan alert evaluation; lighting draft saat ini endpoint terpisah dan belum dipublish.
- Untuk nanti **retained=true** pada konfigurasi terversi yang disetujui, gunakan outbox transaksional, publisher retry/idempotent, device/co-op scopes, ACK/apply verification, dan jalur pembaruan NVS yang benar. Jangan `publish()` sebelum commit DB atau secara buta mendrain outbox `blocked` lama.
- **Perintah aktuator harus NON-retained, ber-expiry dan anti-replay.** QoS/ACK MQTT broker bukan bukti relay fisik aktif. Status GPIO reported pun belum tentu feedback fisik.
- Rencana fail-safe: kehilangan broker, config stale, power restart, perangkat offline, sensor invalid, dan konflik perintah mode; satu otoritas kontrol otomatis harus disepakati agar Laravel dan ESP32 tidak bersaing.

## 5. MQTT — skema TARGET dari pemilik

Spesifikasi target awal (satu kandang) untuk disepakati bersama firmware dan Flutter; **belum otomatis menggantikan kontrak live**:

| Topik target | Arah | Kebijakan target |
| --- | --- | --- |
| `coop/config/threshold` | backend → devices | retained configuration dengan versi dan otorisasi |
| `coop/outdoor/telemetry` | NodeMCU → backend | telemetri outdoor |
| `coop/indoor/telemetry` | ESP32 → backend | telemetri indoor |
| `coop/indoor/actuator/set` | backend → ESP32 | non-retained, command expiry/idempotency |
| `coop/indoor/actuator/status` | ESP32 → backend | verified reported state/ack |
| `coop/outdoor/display` | backend → NodeMCU LCD | ringkasan terkini/berumur jelas |

Contoh payload **ILUSTRATIF (bukan bukti sensor atau angka aman):**

```json
{"min_temp":25.0,"max_temp":30.0,"max_gas":25.0,"min_lux":50,"max_lux":150,"lighting_mode":"auto"}
```

```json
{"temp_out":28.5,"hum_out":70.0,"lux":120}
```

```json
{"dht":[27.1,27.3,27.0,27.2,27.4],"temp_avg":27.2,"hum_avg":68.5,"gas_ppm":12.4}
```

```json
{"heater":false,"fan1":false,"fan2":false,"light":true}
```

```json
{"temp_in":27.2,"temp_out":28.5,"gas_ppm":12.4,"heater":false,"fan":false,"light":true}
```

**Wajib koreksi sebelum live:**

- Tambahkan identitas coop/device ke namespace topik/payload untuk isolasi multikandang (contoh `coops/{coopId}/...` atau skema final yang disetujui; jangan anggap topik datar di atas aman untuk semua kandang).
- Ada schema_version, config_version/sequence, message_id/idempotency, timestamps, expiry untuk command, measurement_version/calibration_version/unit, device/boot identity, freshness, serta validation/ACL/TLS.
- `lux` adalah unit target BH1750; verifikasi firmware/pengukurannya. `gas_ppm` masih target setelah kalibrasi MQ135, sementara gas saat ini ADC. **LEGACY/DEPRECATED** light ADC harus tetap terpisah dari lux.
- Pertahankan migrasi kompatibel dari **CURRENT MQTT v1** (prefix `smart-kandang`, topic `/coops/+/devices/+/{telemetry|status}`, envelope `message_id/sent_at/readings`, dedup/retained rejection). Jangan mengganti subscriber dengan parser contoh baru secara mendadak.
- Bentuk software kelembapan lima DHT22 telah ditetapkan pada Phase 8.1: gunakan lima identifier humidity di envelope `readings` MQTT v1, bukan nilai `hum_avg` yang dikirim firmware tanpa lima pembacaan. Firmware implementation/timing/pin mapping masih perlu acceptance.

## 6. Baseline historis `dev` sebelum Phase 7 (LEGACY)

- `app/Http/Controllers/Api/SettingsController.php`: `update` mengelola `minimum_outdoor_temperature`, `maximum_indoor_temperature`, `gas_threshold`, `notification_preferences` dalam transaksi dengan event dan alert evaluation. **Belum publish config MQTT seperti target.**
- `app/Models/CoopSetting.php`: field legacy outdoor/indoor/gas.
- `app/Http/Controllers/Api/LightingController.php`: draft lighting (`automatic`/`manual`) dengan unit ADC, `configuration_outbox` berstatus `blocked`; endpoint command selalu 409.
- `app/Services/Mqtt/MqttSubscriber.php`: subscribe topic telemetry/status lama, bukan target baru; tidak mendukung sender publisher actuator target.
- `config/mqtt.php`: prefix default `smart-kandang`; autentikasi/TLS perlu diverifikasi deployment.
- Tes sebelumnya adalah bukti pada baseline tertentu; bukan bukti firmware dan relay live teruji.

## 7. PENDING VERIFICATION — jangan berasumsi

1. ID resmi kedua board dan binding ke coop; topik final per-coop/per-device, akun MQTT, ACL, TLS, keamanan payload.
2. BH1750 firmware, actual lux, timing/mode, ID NodeMCU, alamat I2C 0x23/0x5C, tegangan/pull-up/shared-bus, dan ambang min_lux/max_lux operasional.
3. Kalibrasi/kompensasi MQ135 dan unit gas; batas alert yang aman.
4. DHT22 pin map, 5 suhu + 5 kelembapan, missing/partial/fail-safe.
5. GPIO/relay active polarity, boot state, heater-fan interlock, wiring dan catu daya.
6. Kontrol otomatis yang **tunggal** (backend atau firmware) dan fallback offline serta NVS ESP32.
7. ACK/report/physical feedback, retained config validity, command expiry/replay control, time sync, session/boot identity.
8. LCD I2C size/address dan payload display khusus status unavailable/stale.
9. Push notification provider, mobile device token, delivery evidence dan escalation.
10. Acceptance integrasi Flutter/firmware atas migration API/DB minimum indoor dan domain versioning yang sudah diimplementasikan Phase 7; nilai legacy lama tidak bisa diartikan ulang otomatis.

### Status software Phase 8.1

Backend sudah memiliki minimum indoor/versioning (Phase 7), BH1750 lux dan lighting schema 2, serta outbox/retry/ACK/control/LCD/push fake foundations (Phase 8). Phase 8.1 menambah lima humidity channels dan monitoring schema 2: GET latest/chart/export dengan `schema_version=2`. Default schema 1 mempertahankan single humidity legacy; jangan memindahkan artinya diam-diam. Chart/CSV memakai complete-batch aggregate sebelum bucket. Tidak ada migration baru untuk humidity; gunakan kolom batch_key/measurement_version existing tanpa backfill. Baca laporan/checkpoint Phase 8.1 untuk field names, hasil tes aktual, dan remaining dependencies. Kontrak MQTT v1 tetap; seluruh physical/push/config publication tetap disabled.

## 8. Urutan audit & migrasi yang dianjurkan

1. **Inventory current implementation**: Request/Policy/Controller, Model/Resource, migrations, alerts, aggregation, history/CSV, notifications, MQTT subscriber, lighting draft/outbox, tests.
2. **Gap report & compatibility design**: board mapping, suhu, humidity, gas ADC vs ppm, BH1750 lux vs LEGACY light ADC, config payload, MQTT scope, role, actuator authority, LCD, push provider.
3. **Implementasikan perubahan aman yang diminta/diapprove secara bertahap** dengan tes (jangan sekaligus mengaktifkan MQTT dan aktuator). Schema additive + adapter/deprecation lebih aman daripada rename buta.
4. **Simulasi dan staging**: SQLite/MySQL disposable, fake MQTT publisher/consumer, outbox transactional, duplicate/replay/stale/multi-coop, missing sensor, command expiry, fail-safe. Jangan memakai DB/broker produksi tanpa izin.
5. **Hardware sign-off**: firmware contract, wiring/calibration, auth/ACL, control authority, device ACK/report, safety acceptance, baru boleh mengaktifkan capability/command live secara terpisah.
6. Laporan final: file berubah, migrasi, dampak Flutter, tes riil, data yang belum dikonfirmasi, tidak ada push/deploy tanpa perintah eksplisit.

## 9. Prioritas sumber saat konflik

1. **Keputusan terbaru pemilik proyek** mengenai board dan semantik suhu (menggantikan dokumentasi lama yang salah).
2. **AGENTS.md ini** untuk TARGET dan daftar detail yang belum terverifikasi.
3. **Source PHP, database skema, pengujian, deployment, firmware** untuk status CURRENT (bukan untuk membatalkan target).
4. Dokumen handoff Phase 4–6 untuk sejarah implementasi.

Jangan menyimpulkan "100% sesuai" sampai kontrak API, migrasi data, MQTT, firmware, unit/kalibrasi, fallback, feedback aktuator, tes integrasi dan tes hardware benar-benar disahkan serta diverifikasi.
