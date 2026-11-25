# Phase 8.1 — Final Backend Contract Alignment

25 November 2026. Baseline Phase 8 terverifikasi `37781e151beb4c0c0974cf053936b65301f649e6`; branch lokal `feat/phase8-1-humidity-contract`. Implementasi dan verifikasi backend lokal Phase 8.1 selesai: **180 tests / 1680 assertions PASS pada SQLite dan MySQL disposable**. Tidak ada perubahan Flutter/firmware atau aktivasi layanan eksternal.

## CURRENT / TARGET / IMPLEMENTED / PENDING

| CURRENT pada baseline Phase 8 | TARGET resmi | IMPLEMENTED Phase 8.1 | PENDING |
| --- | --- | --- | --- |
| Indoor humidity single channel legacy | Average lima DHT22 humidity terpisah | Lima identifier berpasangan dengan lima temperatur; finite 0–100; same time/device/message; freshness; source allowlist; metadata diagnostik | Firmware mengirim lima nilai nyata, pin mapping dan timing |
| Outdoor humidity tanpa model/source guarantee | AHT20 pada NodeMCU | Registered board + explicit sensor mapping + measurement_version saat ingest; temperature/humidity independen | Pemetaan ID fisik dan acceptance sensor |
| Snapshot/chart field humidity legacy | Enam kartu independen | Schema 2 eksplisit, aggregate additive pada schema 1, provenance dan null semantics | Adapter Flutter |
| CSV lima kolom legacy | Enam metrik dan dua sample counts | CSV schema 2 sepuluh kolom; format default lama tetap | Pilihan export schema 2 pada Flutter |
| LCD empat sensor | Enam sensor dengan unavailable | Dua humidity ditambahkan, sumber LCD menggunakan snapshot schema 2 | Ukuran/alamat/layout hardware; publication disabled |
| MQTT v1 + Phase 7/8 integration foundation | Kompatibel dan aman | Envelope/scope/dedup/out-of-order/retained rejection tetap; outbox/ACK/control/push foundation dipakai ulang | Authenticated firmware/live transport dan safety acceptance |

## Implementasi dan penyimpanan

Lima identifier baru: `indoor_dht22_1_humidity`, `indoor_dht22_2_humidity`, `indoor_dht22_3_humidity`, `indoor_dht22_4_humidity`, `indoor_dht22_5_humidity`. Pasangan temperatur adalah `indoor_dht22_1` sampai `indoor_dht22_5`, ditampilkan pada setiap diagnostic channel sebagai `paired_temperature_identifier`. Temperatur dan humidity tidak diwajibkan saling melengkapi; masing-masing punya completeness sendiri.

Tidak diperlukan migration baru. `sensor_readings` sudah memiliki sensor_type indoor_humidity/outdoor_humidity, identifier varchar, nilai decimal, composite device/coop FK, unique sample, `measurement_version`, dan `batch_key`. Migration Phase 7/8 tidak diubah; tidak ada data rename, backfill, konversi atau penghapusan legacy. Precision storage existing dua desimal tetap; average response dibulatkan empat desimal. Semua database migration hanya dijalankan oleh suite terisolasi. Jangan rollback migration lama pada populated data; gunakan forward migration yang direview bila kelak perlu perubahan storage.

`HumidityContract` menentukan identifier, version dan source policy; `HumidityAggregation` memvalidasi newest channel dan complete batch. `MonitoringContract` mengadaptasi snapshot schema 2 tanpa mengganti kontrak default. SensorHistoryService existing tetap menghitung exact-time batches sebelum bucket averaging, bukan service history baru.

Unit RH canonical wire/storage/JSON `percent`; `unit_symbol="%"`. MQTT juga menerima tag `%` secara eksplisit tanpa mengubah nilai. Nol benar-benar 0% dan 100 benar-benar 100%; missing bukan nol. Values null/string/bool/nonfinite/di luar 0–100 serta duplicate identifier ditolak atomik.

## MQTT v1 dan registered source

Topic tetap `smart-kandang/coops/{coop_id}/devices/{device_identifier}/telemetry`. Tidak ada topic, subscriber, broker connection atau publisher baru. Envelope tetap tepat `message_id`, `sent_at` UTC, `readings`. Contoh ilustratif, bukan nilai lapangan:

```json
{
  "message_id": "humidity-example-1",
  "sent_at": "2026-11-25T08:00:00Z",
  "readings": [
    {"sensor_identifier":"indoor_dht22_1_humidity","value":60,"unit":"%"},
    {"sensor_identifier":"indoor_dht22_2_humidity","value":61},
    {"sensor_identifier":"indoor_dht22_3_humidity","value":62},
    {"sensor_identifier":"indoor_dht22_4_humidity","value":63},
    {"sensor_identifier":"indoor_dht22_5_humidity","value":64}
  ]
}
```

Kanal baru wajib registered device pada coop topic, type tepat case-sensitive `esp32`, dan allowlist eksplisit `mqtt.device_sensors[device_identifier]`. Ketika konfigurasi allowlist digunakan, isinya harus mencakup **semua** kanal sah setiap device, termasuk temperatur/gas yang sudah ada; jangan hanya menambahkan humidity lalu tanpa sengaja mencabut kanal lama. Tidak ada konfigurasi deployment/ID fisik yang diisi otomatis pada fase ini.

Backend menulis `measurement_version=dht22_humidity_v1` dan `batch_key=SHA256(message_id)` saat ingest diterima. Client tidak boleh menyuplai provenance ini. Identitas batch efektif juga mencakup coop, device dan timestamp. Kelima humidity harus fresh, masing-masing valid, satu timestamp persis, satu device, satu batch_key nonnull dan versi valid. Tidak ada fallback exact-time-only untuk humidity baru. Source mapping diperiksa kembali saat read; mapping dicabut atau device diganti board menghasilkan unverified, bukan nilai sehat.

Outdoor `outdoor_temperature`/`outdoor_humidity` diberi `measurement_version=aht20_v1` hanya ketika registered type tepat `nodemcu_esp8266` dan masing-masing identifier eksplisit di-allowlist. Board label saja tidak cukup. Read schema 2 memerlukan versi yang disimpan saat ingest dan mapping yang masih sah; menambahkan allowlist kemudian tidak melabel ulang data lama. `source_verified` berarti registrasi software, **bukan** verifikasi elektrik/fisik; `physical_verified=false`.

Untuk kompatibilitas, kanal outdoor lama tetap dapat masuk jalur MQTT v1 legacy sesuai policy sebelumnya, tetapi sumber yang tidak memenuhi kontrak AHT20 tidak memperoleh versi AHT20 dan tidak tampil sebagai official schema 2. Global device allowlist existing tidak dilonggarkan. BH1750 masih memakai gate dan versi Phase 7 sendiri; gas tetap ADC, tidak ada ppm atau konversi ADC/lux.

Receipt duplicate persis mengembalikan duplicate; reuse message_id berbeda isi, same-sensor/timestamp collision, wrong scope, retained message dan invalid payload ditolak. Out-of-order valid masih disimpan sebagai riwayat, tidak mengganti latest yang lebih baru atau menyegarkan heartbeat. Future tolerance ingest v1 dipertahankan, tetapi future humidity/AHT20 belum official sampai waktu sampelnya tiba. Latest stale window mengikuti `monitoring.stale_after_seconds` (default 300); historical samples tidak menjadi hilang hanya karena kini sudah tua.

## Handoff enam kartu Flutter

Semua endpoint `/api/v1`, Sanctum dan active account; Admin atau User assigned boleh membaca coop. Gunakan **GET `coops/{coop}/sensors/latest?schema_version=2`**; fields berada di `data`.

| Kartu | Field nilai | Unit JSON / tampilan | Validitas | Source/provenance |
| --- | --- | --- | --- | --- |
| Indoor Temperature | `indoor_temperature.average` | celsius / °C | `complete=true`; `status` normal/warning, unknown bila unavailable | `sensors[].device_id`, timestamp/batch; schema 2 memerlukan ESP32; legacy exact_timestamp tetap diidentifikasi |
| Outdoor Temperature | `outdoor_temperature.value` | celsius / °C | `valid=true`, `status=fresh` | `device_id`, sensor_model AHT20, measurement_version aht20_v1, registered mapping |
| Indoor Humidity | `indoor_humidity.average` | percent / % | `complete=true`, `status=fresh` | `device_id`, batch_key, batch_provenance mqtt_v1_message; lima sensor diagnostics |
| Outdoor Humidity | `outdoor_humidity.value` | percent / % | `valid=true`, `status=fresh` | AHT20, current registered NodeMCU/mapping/version |
| Gas Level | `gas.value` | adc / ADC | `valid=true`, `status=fresh`; condition normal/warning terpisah | registered Indoor ESP32, recorded_at, device_id; bukan ppm |
| Light Intensity | `outdoor_light_lux.value` | lux / lux | `valid=true`, `status=fresh` | BH1750 Phase 7 gate/mapping/measurement_version; tidak fallback ADC |

Response juga memiliki `as_of`, `stale_after_seconds`, `schema_version=2`. Nilai kartu official null bila unavailable, jangan fallback ke diagnostic/legacy atau angka nol. Temperature completeness tidak boleh digunakan untuk humidity. Humidity `valid_count=5` saja tidak cukup: batch bisa berbeda. `partial_average` hanya dihitung bila subset fresh berasal dari satu batch yang konsisten, dan hanya untuk diagnostics; bila subset bercampur, nilainya null. `sensors[]`, missing_sensors/stale_sensors/invalid_sensors/unverified_sensors memuat sebab per kanal.

Contoh fragmen lengkap lima humidity (nilai ilustratif; metadata channel lain disingkat):

```json
{
  "data": {
    "schema_version": 2,
    "indoor_humidity": {
      "average": 62,
      "partial_average": 62,
      "unit": "percent",
      "unit_symbol": "%",
      "aggregation": "five_dht22",
      "five_sensor_verified": true,
      "expected_count": 5,
      "valid_count": 5,
      "complete": true,
      "batch_consistent": true,
      "batch_provenance": "mqtt_v1_message",
      "recorded_at": "2026-11-25T08:00:00.000000Z",
      "status": "fresh",
      "unavailable_reason": null,
      "physical_verified": false
    }
  }
}
```

Untuk tiga humidity valid satu batch: average=null, partial_average berisi rata-rata tiga nilai, valid_count=3, expected_count=5, complete=false, status=unknown, unavailable_reason=missing_stale_invalid_or_unverified. Lima fresh yang berbeda batch: average=null, partial_average=null, valid_count=5, complete=false, unavailable_reason=inconsistent_batch. Outdoor status dapat missing/invalid/unverified_source/stale/fresh; hanya fresh punya official value. Diagnostic_value tidak otomatis valid untuk kartu.

Default schema 1 tetap memiliki `indoor_humidity.value` single legacy, `aggregation=legacy_single_channel`, `five_sensor_verified=false`, ditambah `legacy=true`. Aggregate resmi tersedia secara additive sebagai `indoor_humidity_aggregate`; outdoor verified additive sebagai `outdoor_humidity_verified` dan `outdoor_temperature_verified`. Schema 2 memindahkan bentuk default lama ke `legacy_indoor_humidity`, `legacy_outdoor_humidity`, `legacy_outdoor_temperature`. Field legacy adalah jalur kompatibilitas, bukan fallback official. Schema selain 1/2 ditolak 422. Endpoint `sensors/indoor` lama tetap khusus temperatur; gunakan latest schema 2 untuk keenam kartu.

## History, chart dan CSV

- GET `coops/{coop}/sensors/history`: raw paginated readings, filter identifier/type/from/to/per_page existing. Lima identifier baru otomatis dapat difilter; `sensor_type=indoor_humidity` mencakup lima kanal **dan** single legacy. Gunakan identifier/legacy flag untuk membedakan. Raw single `indoor_humidity` memiliki `legacy=true`, `aggregation_eligible=false`. Raw measurements tetap raw, bukan aggregate/freshness guarantee. `measurement_version`, batch_key dan device_id menyediakan provenance. Pagination/date bounds Phase 7 tetap.
- GET `coops/{coop}/sensors/chart?schema_version=2`: setiap bucket dan summary memiliki `indoor_average`, `outdoor_temperature`, `indoor_humidity`, `outdoor_humidity`, `gas_adc`, `outdoor_light_lux`. `indoor_sample_count` dan `indoor_humidity_sample_count` menghitung complete exact batches masing-masing. `indoor_humidity_average` juga tersedia sebagai alias additive. `legacy_indoor_humidity` terpisah. Summary dihitung dari seluruh range, tidak hanya current page. Aturan interval/max500 buckets/per_page tetap.
- Chart default schema 1 mempertahankan `indoor_humidity` legacy, ditambah `indoor_humidity_average`, `indoor_humidity_sample_count`, `outdoor_humidity`. Outdoor humidity baru hanya source verified. Schema 2 temperatur/gas juga memfilter registered board yang sesuai. Source mapping dicabut berarti sample tidak masuk **official** chart; raw history tidak dihapus.
- GET `coops/{coop}/sensors/export?schema_version=2`: UTF-8 BOM, CRLF, UTC ISO, same aggregation/date bounds, X-Schema-Version=2. Kolom berurutan: Timestamp (UTC), Indoor Average Temperature (°C), Outdoor Temperature (°C), Indoor Average Humidity (%), Outdoor Humidity (%), Gas (ADC), Light Intensity (lux), Indoor Temperature Complete Samples, Indoor Humidity Complete Samples, Legacy Indoor Humidity (%). Null berupa cell kosong; sample_count=0 adalah count, bukan nilai sensor. Export bounded auto buckets, bukan current chart page.
- Export default/schema1 tetap persis lima kolom/header/makna sebelumnya. Klien harus meminta schema2 secara eksplisit, bukan menafsir ulang kolom Humidity (%) lama.

## Kontrak Phase 7/8 yang dipertahankan

| Area / endpoint relatif `/api/v1` | Kontrak dan verifikasi yang dipertahankan |
| --- | --- |
| GET/PUT `coops/{coop}/settings` | min/max indoor, nullable minimum, gas ADC, independent config_version, PUT expected_config_version, 428 missing / 409 stale / 422 invalid; legacy minimum outdoor tidak menjadi rule |
| GET `coops/{coop}/lighting?schema_version=2`; PUT `coops/{coop}/lighting/settings` body schema_version=2 | auto/manual, min_lux/max_lux nullable dan ordered, independent lighting version, expected_config_version; no ADC-to-lux backfill |
| POST `coops/{coop}/lighting/commands` dan `coops/{coop}/actuator/commands` | Admin tetap 409 unsupported, User 403; jangan mengaktifkan tombol fisik |
| GET `coops/{coop}/integration/deliveries` | stored_versions, delivery status/current/attempt/reason/applied_current; publish success bukan applied |
| GET `coops/{coop}/integration/monitoring` | reported_actuators, control_preview, lcd_preview; demand bukan relay status; unavailable null |
| POST `push/tokens` | owner aktif; installation_id UUID/platform/token; encrypted token, rotation, identity conflict 409; delivery disabled |
| DELETE `push/tokens/{id}`; GET `push/deliveries` | own-token revoke 204, foreign404; own delivery ledger; provider_accepted bukan handset delivered |
| MQTT/integration foundation | transactional outbox, immutable config/version/hash, bounded retries/lease, ACK/session/replay/multicoop; fake transport only |
| Control preview | strict indoor thresholds, gas OR fan priority hanya dengan verified policy input; missing sensor unknown; lux hysteresis retain previous dalam band; manual/commands tetap blocked |

Flutter harus menyimpan versi ketika draft dibuat, menangani 409 melalui reconcile eksplisit dan 428 sebagai adapter belum lengkap. Jangan menaikkan versi draft diam-diam untuk menutupi konflik. Stored/pending/published/acknowledged/applied/failed/blocked berbeda; applied_current tetap bukan bukti relay ON. Push memerlukan consent/token lifecycle dan provider setup pada fase terpisah. Tidak ada Flutter source/UI yang diedit.

## Klasifikasi akhir dan dependensi

Diimplementasikan: software humidity contract, mapping/provenance, snapshot/chart/CSV/raw history, LCD preview dan dokumentasi. Tetap disabled: external MQTT publishing/feedback adapter, push provider, physical commands, LCD publication. Tidak menambah humidity threshold/alarm karena policy itu belum diminta/disahkan.

Hardware/firmware pending: lima reading nyata per batch/timing/pin map; ID fisik dan allowlists; AHT20/BH1750 bus/address/voltage/timing; MQ135 calibration/filter dan safe thresholds; ACL/TLS/time sync/NVS/boot/ACK agreement; control authority, relay polarity/wiring/interlock/anti-short-cycle/fail-safe; genuine physical feedback; LCD geometry; real push provider/mobile acceptance. Tidak ada live acceptance atau stress multi-process yang diklaim.

## Bukti tes dan Git

| Pemeriksaan aktual final | Hasil |
| --- | --- |
| Focused SQLite in-memory | **20 tests / 192 assertions PASS**, 01:07.999 |
| Focused MySQL 8.4.3 disposable | **20 tests / 192 assertions PASS**, 00:52.390 |
| Full SQLite in-memory | **180 tests / 1680 assertions PASS**, 00:51.447, exit 0 |
| Full MySQL 8.4.3 disposable | **180 tests / 1680 assertions PASS**, 02:26.984, exit 0 |
| Pint seluruh PHP changed/new | PASS |
| git diff --check | PASS |
| Tracked tests/migrations dan laporan Phase 7/8 versus baseline | Identik; git diff exit 0 |
| Git index | Kosong; exit 0 |

Runtime PHP 8.3.30 / PHPUnit 12.5.43. Log `.runtime/phase8-1-focused-final.txt`, `.runtime/phase8-1-mysql-focused.txt`, `.runtime/phase8-1-sqlite-final.txt`, `.runtime/phase8-1-mysql-final.txt`. Seluruh **160 tes baseline + 20 tes baru** lulus; tidak ada kegagalan tersisa. Run pengembangan pertama 17 tes memiliki satu kegagalan fixture memakai is_active, diperbaiki ke field status=inactive pada tes baru; log dan kronologi disimpan di checkpoint. Tes lama tidak dimodifikasi/dilemahkan. Tidak ada migration baru.

MySQL hanya instance dedicated `--no-defaults`, loopback **127.0.0.1:57770**, parent PID **4620**/child **5616**, datadir `.runtime/phase8-1-mysql-fd04def71a0545d1ac3fc08b8d8b70b4/data`, database `smart_kandang_test`. PID/parent/command line/port dan @@datadir diverifikasi sebelum tes dan shutdown. **Shutdown normal selesai 25 November 2026 09:53:42 UTC / 16:53:42 WIB**, server log mencatat Shutdown complete, verifikasi **0 matching process / 0 listener**. Descriptor `.runtime/phase8-1-mysql-instance.json`; data/log tetap ignored, tidak dihapus. Tidak ada operational database/service yang diakses atau dihentikan.

Review software-ready scope tidak menemukan pekerjaan wajib Phase 8.1 tersisa setelah hasil ini. Batas bukti: regression lokal/fake transports, bukan authenticated firmware/live acceptance atau stress concurrency produksi. Source physical commands, configuration/push bindings tetap disabled, tidak ada scheduler/network activation.

Inventaris source: SensorController, ChartHistoryRequest, SensorReadingResource, SensorCatalog, SensorAggregationService, SensorHistoryService, PayloadValidator, TelemetryIngestor, LcdPayload, config/mqtt.php; tiga services baru HumidityContract/HumidityAggregation/MonitoringContract; satu test file baru HumidityContractTest; AGENTS dan dua dokumen Phase8.1. **11 tracked modified + 6 untracked = 17 file**. Index tetap kosong, HEAD tetap `37781e151beb4c0c0974cf053936b65301f649e6`; tidak ada commit/stage/push/merge/reset/clean/deploy. Flutter tetap memiliki dua dokumen modified yang sudah ada sebelumnya (AGENTS.md dan POULTRIQ_ARCHITECTURE_GAP_AUDIT.md); tidak diedit pada fase ini.
