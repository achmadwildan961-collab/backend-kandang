# Poultriq Firmware Contract V1 — STAGING PROPOSAL

Package `1.0.0-staging-proposal.1`, 25 November 2026, baseline `50e3f84`. **Belum disahkan tim hardware. Tidak ada firmware Arduino yang diubah.** Versi package dokumentasi bukan penggantian versi wire: telemetry tetap MQTT v1 tanpa schema_version tambahan; integration tetap schema_version=1, contract=phase8_draft. Contract lock, schemas dan fixtures berada di `contracts/firmware/`.

## PERUBAHAN BACKEND YANG WAJIB DISAMAKAN DI CODING ARDUINO

Lihat [changelog](POULTRIQ_FIRMWARE_CHANGELOG.md). Phase 8.2 tidak mengganti parser/topik/field existing secara breaking. Firmware yang masih mengikuti dokumen 10 November tetap wajib disinkronkan dengan perubahan Phase 7/8/8.1: 5 humidity DHT22, BH1750 lux, gas indoor ADC, minimum indoor, config domains/version/hash, ACK/report. Session registration dan LCD wire merupakan proposal baru yang **memerlukan keputusan tim firmware sebelum implementasi live**. Tes virtual bukan bukti firmware sudah sesuai.

## Identitas, transport dan scope

Prefix `P = smart-kandang/coops/{coop_id}/devices/{device_identifier}`. coop_id integer positif, device_identifier case-sensitive `[A-Za-z0-9_-]{1,100}` terdaftar pada coop. Jangan mengganti identifier existing berdasarkan nama board. Registered type target tepat `esp32` / `nodemcu_esp8266`, bukan `nodemcu`. Broker account terpisah per device dengan exact-topic ACL; wildcard subscriber backend tidak boleh memberi device hak lintas coop. V1 topic resolver memeriksa registry, tetapi registry bukan autentikasi MQTT sender; ACL/TLS dan credential binding tetap mandatory.

| Board | Topic suffix setelah P | Direction | QoS | Retained | Status |
| --- | --- | --- | --- | --- | --- |
| Keduanya | `/telemetry` | device → backend | 1 | false, retained ditolak | Existing v1 |
| Keduanya | `/status` | device → backend | 1 | false, retained ditolak | Existing v1 |
| Keduanya | `/integration/v1/config/operational` | backend → device | 1 proposal | true hanya explicit authorization | Existing Phase 8 draft, produksi blocked |
| Keduanya | `/integration/v1/config/lighting_lux` | backend → device | 1 proposal | true hanya explicit authorization | Existing draft, domain version independen |
| Keduanya | `/integration/v1/ack` | device → authenticated backend adapter | 1 proposal | false | Existing internal validator; subscriber live belum ada |
| ESP32 | `/integration/v1/report` | device → authenticated backend adapter | 1 proposal | false | GPIO/report bukan bukti physical feedback |
| Keduanya | `/integration/v1/session` | device → backend | 1 proposal | false | **DECISION REQUIRED**, schema fixture saja; belum ada receiver |
| NodeMCU | `/integration/v1/display` | backend → device | 1 proposal | false, expiry wajib | **DECISION REQUIRED**, DTO dan virtual receiver; belum ada publisher |
| ESP32 | Actuator set topic | tidak diterbitkan | n/a | tidak boleh retained | Tidak difinalisasi/diaktifkan; endpoint HTTP tetap 409 |

Config operational dikirim ke kedua board oleh foundation existing. ESP32 menyimpan konfigurasi kontrol tervalidasi, Outdoor hanya reference/display; jangan menganggap Outdoor mendapat otoritas relay. Domain lighting_lux terpisah; min/max lux tidak boleh memetakan heating_lamp. Arsitektur otoritas otomatis tunggal masih perlu sign-off.

## Telemetry dan sensor

Envelope required tepat `message_id:string`, `sent_at:string UTC ISO`, `readings:array`. Unknown top-level/reading fields ditolak. V1 tidak membawa schema_version, boot_id, sensor_type atau measurement_version dari firmware. Backend menyimpannya secara internal setelah validasi. Message ID `[A-Za-z0-9_.:-]{1,128}` unik per device sepanjang receipt lifetime. Retry **byte-identical** (urutan key/whitespace sama), topic/ID sama; reuse ID beda bytes ditolak. Payload <=16384 bytes, finite numeric JSON; null/string/NaN/Infinity/bool tidak sah. Partial batch boleh menghilangkan kanal rusak, jangan mengirim nol palsu.

| Identifier | Board / model | Unit canonical | Range validasi |
| --- | --- | --- | --- |
| indoor_dht22_1 … indoor_dht22_5 | ESP32 / 5 DHT22 | celsius | -40…80 |
| indoor_dht22_1_humidity … indoor_dht22_5_humidity | ESP32 / pasangan DHT22 | percent (%) | 0…100 |
| gas_adc | ESP32 / MQ135 | adc | 0…4095 |
| outdoor_temperature | NodeMCU / AHT20 | celsius | -40…80 |
| outdoor_humidity | NodeMCU / AHT20 | percent (%) | 0…100 |
| outdoor_light_lux | NodeMCU / BH1750 I2C | lux | 0…65535 |
| indoor_humidity | legacy only | percent | 0…100, **bukan average lima DHT22** |
| outdoor_light_adc | legacy/deprecated gate only | adc | bergantung konfigurasi lama, **bukan lux** |

Range di atas domain validasi, bukan nilai aman peternakan atau kalibrasi fisik. Unit field opsional hanya humidity (`percent` atau `%`) dan BH1750 (`lux`). Kanal lainnya menginfer unit canonical dan menolak explicit unit field. Legacy ADC gated tidak dimasukkan ke target fixture schema; production parser kompatibilitasnya tetap utuh.

Kelima temperatur dan kelima humidity sebaiknya dikirim dalam **satu** message ESP32 dengan sample timestamp yang sama; masing-masing metric completeness independen. Average resmi memerlukan tepat lima valid/fresh, satu timestamp, device dan message provenance; beberapa pesan parsial tidak boleh melengkapi satu sama lain. Humidity baru wajib explicit `MQTT_DEVICE_SENSORS` mapping + registered ESP32; versi tersimpan `dht22_humidity_v1`. AHT20 verified memerlukan mapping eksplisit per channel + NodeMCU + versi ingest `aht20_v1`. BH1750 memakai gate telemetry_verified/device_identifiers + versi `bh1750_lux_v1`. Jangan backfill label sensor pada data lama.

V1 UTC `YYYY-MM-DDTHH:mm:ss[.ffffff]Z`, timestamp 1970-01-01T00:00:01Z … 2038-01-19T03:14:07Z, future tolerance default30s. Timestamp future masih unavailable pada latest. Fresh default300s; offline default120s. Old out-of-order valid masuk history tanpa mengganti latest/heartbeat baru. Duplicate tidak memperbarui heartbeat. Single sensor/type/coop/timestamp collision tidak boleh overwrite. Time synchronization source, drift budget dan sampling cadence hardware belum disahkan. Jangan menerbitkan timestamp uptime sebagai UTC. Delta suhu hanya jika exact timestamp indoor/outdoor paired; tidak ada toleransi lintas-node yang dibuat-buat.

Status envelope required tepat message_id/sent_at/status (`online`/`offline`), nonretained. Disconnect silent diselesaikan existing offline checker, bukan retained online palsu. Konfigurasi Last Will dengan fixed timestamp akan menjadi stale; strategi LWT/session time perlu acceptance, jangan menonaktifkan freshness validator.

## Konfigurasi, hash, ACK, boot dan NVS

Config required: schema_version integer1, contract phase8_draft, message_id SHA256 hex64, coop_id integer, device_identifier string, domain operational/lighting_lux, config_version positive integer, settings object, generated_at/expires_at UTC ISO (umumnya microseconds), payload_hash hex64. Operational settings: minimum_indoor_temperature number|null, maximum_indoor_temperature number, gas_threshold number (ADC), temperature_unit=celsius, gas_unit=adc. Lighting: lighting_mode auto/manual, min_lux/max_lux number|null, threshold_unit=lux, measurement_version=bh1750_lux_v1. Null berarti unconfigured, bukan 0. Threshold order min<max. Field names min_temp/max_temp/max_gas pada dokumen lama **bukan** wire config existing.

Hash adalah SHA256 dari JSON CanonicalPayload **tanpa payload_hash**: sort object keys rekursif menurut string, list order tetap, compact, slash unescaped, encoding PHP JSON existing. Ini bukan klaim RFC canonical JSON. Firmware sebaiknya memverifikasi encoding dengan fixture vectors; floating-number formatting berbeda antar-runtime adalah blocker acceptance. Kembalikan hash yang diterima pada ACK; jangan reformat hash atas subset. Message_id immutable per coop/device/domain/version; domain versions terpisah. PHP integer range tidak otomatis aman untuk Arduino float; gunakan representasi integer yang tidak kehilangan presisi, tolak overflow dan tentukan ceiling versi bersama tim. Jangan mengubah tipe menjadi string tanpa versi wire baru.

Flow: transaction save → blocked (produksi) / pending (fake test) → claimed lease → published → acknowledged → applied, atau failed. Broker PUBACK hanya broker receipt. `acknowledged` adalah device receipt/validation acceptance; `applied` hanya setelah device benar-benar menerapkan dan memverifikasi konfigurasi yang tepat pada domain terkait. `applied` bukan relay ON. Rejected terminal untuk delivery itu; recovery memakai konfigurasi versi baru. Persist last accepted version/hash/config/expiry atomik pada NVS yang sesuai hardware. Crash saat NVS write tidak boleh ACK applied. Firmware harus dedup same version+hash, reject lower version atau same version+different hash. Re-ACK duplicate identical snapshot agar lost ACK dapat pulih.

ACK common required: schema_version1, kind=ack, message_id UUID, coop_id, device_identifier, boot_id UUID, sequence integer1…2147483647, sent_at UTC **whole seconds**. Tambahan required: configuration_message_id hex64, domain, config_version, payload_hash hex64, status acknowledged/applied/rejected. Backend menolak stale>60s, future, retained, wrong scope/hash/version/boot, unknown fields, unsent/expired/superseded deliveries; applied tidak boleh regress. Semantic duplicate ID/hash diterima tanpa state baru; sequence harus meningkat lintas ACK dan report dalam satu boot. Duplicate receipt tidak memperbarui last_seen.

Report required common seperti ACK dengan kind=report; feedback_kind=gpio/physical, states object tepat heating_lamp/ventilation_fan_1/ventilation_fan_2/lighting_lamp, setiap nilai bool|null. Boolean bukan 0/1/string. Unknown tetap null. Label physical dari firmware **tidak** membuat physical_verified backend true; bukti feedback perlu acceptance terpisah. Virtual simulator selalu feedback_kind=gpio, tidak menjalankan relay.

Session currently hanya `DeviceFeedback::openSession(authenticatedDevice, bootUUID)` internal, test-only. Proposal session wire fields ada pada schema/fixture tetapi **bukan receiver yang sudah aktif**. Boot UUID baru setiap reboot, sequence reset hanya pada boot baru; authenticated registration harus mengikat credential ke immutable coop/device context sebelum memanggil boundary. Backend tidak dapat menganggap payload identity sebagai autentikasi. Broker subscriber biasa tidak memperoleh username publisher dari callback; desain per-device authentication/ACL/bridge proof harus disahkan. Jangan memasang blind wildcard router yang membuka session hanya karena boot_id dikirim. Retired boot tidak boleh kembali.

New boot membatalkan current reported-state dan me-requeue config unexpired yang pernah published/ack/applied; perlu re-ACK. Old expired config tidak dipublish ulang dengan expiry palsu. Last-known config di NVS boleh dipertahankan sebagai data, tetapi **izin kontrol offline/reboot/expired belum ditentukan**. Unknown lebih benar daripada mengklaim OFF/ON. `applied_current` mensyaratkan current config, fresh online device, same current session dan unexpired snapshot.

Default software test windows: config validity3600s, ACK wait60s, max attempts3, retry5/10s cap3600, lease30s, report60s, display30s. Offline menunda attempts; lease crash dipulihkan dengan same payload/message. Angka itu parameter software staging, bukan jaminan keselamatan hardware. Phase8 dispatcher/fake/feedback gates tetap test-only. Adapter real Phase8.2 **tidak** di-bind ke dispatcher dan tidak mendrain blocked rows.

## LCD dan monitoring

LcdPayload existing schema1/phase8_draft memuat coop_id, generated_at/expires_at, target_device_ids (numeric registry IDs), fields enam metrics, actuators reports, display_geometry=null, publication_enabled=false. Setiap metric value number|null, unit, status, recorded_at ISO|null; humidity juga unit_symbol %. Proposal topic menambah scope identifier dari route, bukan menambah field device_identifier ke DTO secara diam-diam. NodeMCU memerlukan numeric registry ID untuk memeriksa target_device_ids; provisioning cara mendapatkannya adalah DECISION REQUIRED. Simulator menerima ID tersebut eksplisit. Jangan menafsir null sebagai 0 atau stale sebagai fresh. Geometry/alamat/layout belum diputuskan. Belum ada publish LCD nyata.

Flutter tetap REST latest/chart/export schema_version=2 untuk enam metrik, settings/lighting versi terpisah, monitoring delivery/report. Detail field API tetap laporan Phase8.1. Push token/outbox/provider abstraction siap fake tests; real mobile delivery belum tersedia. Consent/token rotation/revoke, provider credentials dan app integration memerlukan fase terpisah.

## Spesifikasi perilaku kontrol untuk Arduino (belum izin relay)

- Complete valid/fresh lima temperatur dan configured min/max wajib. avg<min: heater demand true, thermal fans false; avg>max: kedua thermal fan demands true, heater false; tepat min/max dan di antaranya demands thermal false.
- Gas raw ADC dibanding threshold ADC **hanya setelah policy safety disahkan**. Verified gas demand true mempertahankan kedua fan demand walau suhu normal/missing. Heater/interlock masih unknown bila data/policy tidak lengkap. Jangan mengkonversi ADC ke ppm.
- Auto lux<min_lux: lighting demand true; lux>max_lux: false; dalam band/tepat batas: retain previous yang valid. Previous missing/reboot/stale → unknown dalam band. Manual tidak menjalankan auto preview. Ini lighting_lamp, bukan heating_lamp.
- Semua hasil di atas adalah demand, bukan reported status. Jangan menjalankan combination heater+fan tanpa interlock acceptance. Missing/stale/offline/reboot/fault tidak otomatis menjadi OFF atau safe physical state. Tentukan otoritas otomatis tunggal, anti-short-cycle, minimum runtime, gas recovery hysteresis, relay polarity/boot/wiring secara terpisah.
- Kontrak software sudah diuji deterministik di ControlDemandPreviewTest, LightingDemandPreviewTest, IntegrationControlTest dan lifecycle harness. Itu bukan physical fail-safe guarantee.

## Fixture, schema dan perubahan

Semua contoh JSON penuh ada pada `contracts/firmware/fixtures/*.json` dan lampiran field/examples di bawah. Fixture values/time/IDs sintetis; ganti waktu hanya pada simulator runtime, jangan publish fixture historis mentah. `firmware:contract-check` memverifikasi checksum schemas/fixtures dan strict documented subset JSON Schema tanpa network/dependency baru. Validator subset mendukung type/properties/required/additionalProperties/items/minItems/maxItems/minimum/maximum/pattern/enum/const/anyOf; unknown keyword ditolak. Format kalender, unique sensor, identity mapping, replay/freshness/hashes divalidasi production services/virtual harness, bukan hanya schema.

Setiap perubahan wire harus mengubah lock secara reviewable, menambah changelog classification/before-after/Arduino steps/acceptance, dan lulus generator-versus-schema tests. Jangan regenerate lock otomatis saat test gagal. Untuk breaking wire baru gunakan versi namespace/adapter eksplisit; jangan mengganti phase8_draft in-place setelah firmware acceptance.

## Field reference (machine-readable schema counterpart)

Semua nested field required dalam parent yang hadir kecuali secara eksplisit optional. Semantic constraints di bagian atas tetap berlaku.

### ack.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| kind | literal | required | {"const":"ack"} |
| message_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| coop_id | integer | required | {"minimum":1} |
| device_identifier | string | required | {"pattern":"^[A-Za-z0-9_-]{1,100}$"} |
| boot_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| sequence | integer | required | {"minimum":1,"maximum":2147483647} |
| sent_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"} |
| configuration_message_id | string | required | {"pattern":"^[a-f0-9]{64}$"} |
| domain | literal | required | {"enum":["operational","lighting_lux"]} |
| config_version | integer | required | {"minimum":1} |
| payload_hash | string | required | {"pattern":"^[a-f0-9]{64}$"} |
| status | literal | required | {"enum":["acknowledged","applied","rejected"]} |

### config-lighting-lux.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| contract | literal | required | {"const":"phase8_draft"} |
| message_id | string | required | {"pattern":"^[a-f0-9]{64}$"} |
| coop_id | integer | required | {"minimum":1} |
| device_identifier | string | required | {"pattern":"^[A-Za-z0-9_-]{1,100}$"} |
| domain | literal | required | {"const":"lighting_lux"} |
| config_version | integer | required | {"minimum":1} |
| settings | object | required | {"additionalProperties":false} |
| settings.lighting_mode | literal | required | {"enum":["auto","manual"]} |
| settings.min_lux | number or null | required | {"minimum":0,"maximum":65535} |
| settings.max_lux | number or null | required | {"minimum":0,"maximum":65535} |
| settings.threshold_unit | literal | required | {"const":"lux"} |
| settings.measurement_version | literal | required | {"const":"bh1750_lux_v1"} |
| generated_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| expires_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| payload_hash | string | required | {"pattern":"^[a-f0-9]{64}$"} |

### config-operational.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| contract | literal | required | {"const":"phase8_draft"} |
| message_id | string | required | {"pattern":"^[a-f0-9]{64}$"} |
| coop_id | integer | required | {"minimum":1} |
| device_identifier | string | required | {"pattern":"^[A-Za-z0-9_-]{1,100}$"} |
| domain | literal | required | {"const":"operational"} |
| config_version | integer | required | {"minimum":1} |
| settings | object | required | {"additionalProperties":false} |
| settings.minimum_indoor_temperature | number or null | required | {"minimum":-40,"maximum":80} |
| settings.maximum_indoor_temperature | number | required | {"minimum":-40,"maximum":80} |
| settings.gas_threshold | number | required | {"minimum":0,"maximum":4095} |
| settings.temperature_unit | literal | required | {"const":"celsius"} |
| settings.gas_unit | literal | required | {"const":"adc"} |
| generated_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| expires_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| payload_hash | string | required | {"pattern":"^[a-f0-9]{64}$"} |

### display.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| contract | literal | required | {"const":"phase8_draft"} |
| coop_id | integer | required | {"minimum":1} |
| generated_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| expires_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| target_device_ids | array | required | {} |
| target_device_ids[] | integer | required | {"minimum":1} |
| fields | object | required | {"additionalProperties":false} |
| fields.indoor_temperature | object | required | {"additionalProperties":false} |
| fields.indoor_temperature.value | number or null | required | {} |
| fields.indoor_temperature.unit | literal | required | {"const":"celsius"} |
| fields.indoor_temperature.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.indoor_temperature.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| fields.outdoor_temperature | object | required | {"additionalProperties":false} |
| fields.outdoor_temperature.value | number or null | required | {} |
| fields.outdoor_temperature.unit | literal | required | {"const":"celsius"} |
| fields.outdoor_temperature.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.outdoor_temperature.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| fields.indoor_humidity | object | required | {"additionalProperties":false} |
| fields.indoor_humidity.value | number or null | required | {} |
| fields.indoor_humidity.unit | literal | required | {"const":"percent"} |
| fields.indoor_humidity.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.indoor_humidity.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| fields.indoor_humidity.unit_symbol | literal | required | {"const":"%"} |
| fields.outdoor_humidity | object | required | {"additionalProperties":false} |
| fields.outdoor_humidity.value | number or null | required | {} |
| fields.outdoor_humidity.unit | literal | required | {"const":"percent"} |
| fields.outdoor_humidity.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.outdoor_humidity.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| fields.outdoor_humidity.unit_symbol | literal | required | {"const":"%"} |
| fields.gas | object | required | {"additionalProperties":false} |
| fields.gas.value | number or null | required | {} |
| fields.gas.unit | literal | required | {"const":"adc"} |
| fields.gas.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.gas.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| fields.outdoor_light_lux | object | required | {"additionalProperties":false} |
| fields.outdoor_light_lux.value | number or null | required | {} |
| fields.outdoor_light_lux.unit | literal | required | {"const":"lux"} |
| fields.outdoor_light_lux.status | literal | required | {"enum":["fresh","stale","missing","invalid","unsupported","unknown","unavailable","unverified_source"]} |
| fields.outdoor_light_lux.recorded_at | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |
| actuators | array | required | {} |
| actuators[] | object | required | {"additionalProperties":false} |
| actuators[].device_id | integer | required | {"minimum":1} |
| actuators[].online | boolean | required | {} |
| actuators[].status | literal | required | {"enum":["missing","fresh","unavailable"]} |
| actuators[].states | object | required | {"additionalProperties":false} |
| actuators[].states.heating_lamp | boolean or null | required | {} |
| actuators[].states.ventilation_fan_1 | boolean or null | required | {} |
| actuators[].states.ventilation_fan_2 | boolean or null | required | {} |
| actuators[].states.lighting_lamp | boolean or null | required | {} |
| actuators[].feedback_kind | literal | required | {"enum":["gpio","physical",null]} |
| actuators[].reported_at | string or null | required | {} |
| actuators[].physical_verified | literal | required | {"const":false} |
| display_geometry | null | required | {} |
| publication_enabled | literal | required | {"const":false} |

### report.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| kind | literal | required | {"const":"report"} |
| message_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| coop_id | integer | required | {"minimum":1} |
| device_identifier | string | required | {"pattern":"^[A-Za-z0-9_-]{1,100}$"} |
| boot_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| sequence | integer | required | {"minimum":1,"maximum":2147483647} |
| sent_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"} |
| states | object | required | {"additionalProperties":false} |
| states.heating_lamp | boolean or null | required | {} |
| states.ventilation_fan_1 | boolean or null | required | {} |
| states.ventilation_fan_2 | boolean or null | required | {} |
| states.lighting_lamp | boolean or null | required | {} |
| feedback_kind | literal | required | {"enum":["gpio","physical"]} |

### session-proposal.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| schema_version | literal | required | {"const":1} |
| kind | literal | required | {"const":"session"} |
| message_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| coop_id | integer | required | {"minimum":1} |
| device_identifier | string | required | {"pattern":"^[A-Za-z0-9_-]{1,100}$"} |
| boot_id | string | required | {"pattern":"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"} |
| sent_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"} |

### status.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| message_id | string | required | {"pattern":"^[A-Za-z0-9_.:-]{1,128}$"} |
| sent_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| status | literal | required | {"enum":["online","offline"]} |

### telemetry.json

| Field | JSON type | Presence | Constraints |
| --- | --- | --- | --- |
| $ | object | required | {"additionalProperties":false} |
| message_id | string | required | {"pattern":"^[A-Za-z0-9_.:-]{1,128}$"} |
| sent_at | string | required | {"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]{1,6})?Z$"} |
| readings | array | required | {"minItems":1,"maxItems":16} |
| readings[] | anyOf | required | Lihat variants pada schema JSON; unit optional hanya percent/% atau lux. |

## Full JSON examples (synthetic, not operational settings)

### fixtures/telemetry-indoor.json

```json
{
  "message_id": "indoor-sample-1",
  "sent_at": "2026-11-25T08:00:00Z",
  "readings": [
    {
      "sensor_identifier": "indoor_dht22_1",
      "value": 28
    },
    {
      "sensor_identifier": "indoor_dht22_2",
      "value": 28
    },
    {
      "sensor_identifier": "indoor_dht22_3",
      "value": 28
    },
    {
      "sensor_identifier": "indoor_dht22_4",
      "value": 28
    },
    {
      "sensor_identifier": "indoor_dht22_5",
      "value": 28
    },
    {
      "sensor_identifier": "indoor_dht22_1_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "indoor_dht22_2_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "indoor_dht22_3_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "indoor_dht22_4_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "indoor_dht22_5_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "gas_adc",
      "value": 1000
    }
  ]
}
```

### fixtures/telemetry-outdoor.json

```json
{
  "message_id": "outdoor-sample-1",
  "sent_at": "2026-11-25T08:00:00Z",
  "readings": [
    {
      "sensor_identifier": "outdoor_temperature",
      "value": 28
    },
    {
      "sensor_identifier": "outdoor_humidity",
      "value": 65
    },
    {
      "sensor_identifier": "outdoor_light_lux",
      "value": 120
    }
  ]
}
```

### fixtures/status.json

```json
{
  "message_id": "status-sample-1",
  "sent_at": "2026-11-25T08:00:00Z",
  "status": "online"
}
```

### fixtures/config-operational.json

```json
{
  "schema_version": 1,
  "contract": "phase8_draft",
  "message_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "coop_id": 1,
  "device_identifier": "VIRTUAL_INDOOR",
  "domain": "operational",
  "config_version": 1,
  "settings": {
    "minimum_indoor_temperature": 20,
    "maximum_indoor_temperature": 30,
    "gas_threshold": 1500,
    "temperature_unit": "celsius",
    "gas_unit": "adc"
  },
  "generated_at": "2026-11-25T08:00:00Z",
  "expires_at": "2026-11-25T09:00:00Z",
  "payload_hash": "0b26e9205c600a9325ca01a89a788078df7b6918c0a2fbeb13d225b45934864c"
}
```

### fixtures/config-lighting-lux.json

```json
{
  "schema_version": 1,
  "contract": "phase8_draft",
  "message_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "coop_id": 1,
  "device_identifier": "VIRTUAL_INDOOR",
  "domain": "lighting_lux",
  "config_version": 1,
  "settings": {
    "lighting_mode": "auto",
    "min_lux": 10,
    "max_lux": 100,
    "threshold_unit": "lux",
    "measurement_version": "bh1750_lux_v1"
  },
  "generated_at": "2026-11-25T08:00:00Z",
  "expires_at": "2026-11-25T09:00:00Z",
  "payload_hash": "1d18e289a345ba8d619726e8fb7d5454958900359ac1f6684ddf8dd04782d2e6"
}
```

### fixtures/ack.json

```json
{
  "schema_version": 1,
  "kind": "ack",
  "message_id": "12345678-1234-4234-8234-123456789abd",
  "coop_id": 1,
  "device_identifier": "VIRTUAL_INDOOR",
  "boot_id": "12345678-1234-4234-8234-123456789abc",
  "sequence": 1,
  "sent_at": "2026-11-25T08:00:00Z",
  "configuration_message_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "domain": "operational",
  "config_version": 1,
  "payload_hash": "0b26e9205c600a9325ca01a89a788078df7b6918c0a2fbeb13d225b45934864c",
  "status": "applied"
}
```

### fixtures/report.json

```json
{
  "schema_version": 1,
  "kind": "report",
  "message_id": "12345678-1234-4234-8234-123456789abd",
  "coop_id": 1,
  "device_identifier": "VIRTUAL_INDOOR",
  "boot_id": "12345678-1234-4234-8234-123456789abc",
  "sequence": 1,
  "sent_at": "2026-11-25T08:00:00Z",
  "states": {
    "heating_lamp": null,
    "ventilation_fan_1": null,
    "ventilation_fan_2": null,
    "lighting_lamp": null
  },
  "feedback_kind": "gpio"
}
```

### fixtures/session-proposal.json

```json
{
  "schema_version": 1,
  "kind": "session",
  "message_id": "12345678-1234-4234-8234-123456789abd",
  "coop_id": 1,
  "device_identifier": "VIRTUAL_INDOOR",
  "boot_id": "12345678-1234-4234-8234-123456789abc",
  "sent_at": "2026-11-25T08:00:00Z"
}
```

### fixtures/display.json

```json
{
  "schema_version": 1,
  "contract": "phase8_draft",
  "coop_id": 1,
  "generated_at": "2026-11-25T08:00:00Z",
  "expires_at": "2026-11-25T08:00:30Z",
  "target_device_ids": [
    2
  ],
  "fields": {
    "indoor_temperature": {
      "value": null,
      "unit": "celsius",
      "status": "missing",
      "recorded_at": null
    },
    "outdoor_temperature": {
      "value": null,
      "unit": "celsius",
      "status": "missing",
      "recorded_at": null
    },
    "indoor_humidity": {
      "value": null,
      "unit": "percent",
      "status": "missing",
      "recorded_at": null,
      "unit_symbol": "%"
    },
    "outdoor_humidity": {
      "value": null,
      "unit": "percent",
      "status": "missing",
      "recorded_at": null,
      "unit_symbol": "%"
    },
    "gas": {
      "value": null,
      "unit": "adc",
      "status": "missing",
      "recorded_at": null
    },
    "outdoor_light_lux": {
      "value": null,
      "unit": "lux",
      "status": "missing",
      "recorded_at": null
    }
  },
  "actuators": [],
  "display_geometry": null,
  "publication_enabled": false
}
```
