# Firmware contract changelog

25 November 2026, package 1.0.0-staging-proposal.1. **Tidak ada firmware yang dimodifikasi atau diapprove.** Phase8.2 mengunci existing telemetry v1 + Phase8 draft; tidak mengaktifkan broker produksi. Item SYNC berikut merekonsiliasi firmware lama dengan baseline Phase7/8/8.1, bukan klaim perubahan wire breaking baru pada Phase8.2.

## PERUBAHAN BACKEND YANG WAJIB DISAMAKAN DI CODING ARDUINO

| ID / classification | Sebelumnya → kontrak target | Board / topic-field tepat | Arduino action / compatibility test | Backward-safe / approval |
| --- | --- | --- | --- | --- |
| SYNC-01 FIRMWARE_SYNC_REQUIRED | single indoor_humidity → lima paired humidity dalam satu message | ESP32, P/telemetry readings[].sensor_identifier/value | Baca lima humidity DHT22 nyata, kirim indoor_dht22_1_humidity…5_humidity bersama batch; test complete/partial/split/stale pada harness | Legacy masih diterima tetapi tidak official average; hardware team approval required |
| SYNC-02 FIRMWARE_SYNC_REQUIRED | contoh gas outdoor/ppm/LDR → MQ135 indoor raw ADC, BH1750 Outdoor lux | ESP32 gas_adc; NodeMCU outdoor_light_lux, outdoor_temperature/outdoor_humidity di P/telemetry | Hapus gas_ppm fiktif/LDR-to-lux; gunakan raw ADC dan BH1750 I2C; verifikasi unit/mapping/range; HumidityContractTest/Bh1750TelemetryTest | ADC history tetap legacy; new lux harus explicit capability; approval required |
| SYNC-03 FIRMWARE_SYNC_REQUIRED | flat illustrative min_temp/max_temp/max_gas → scoped domain versioned config | Kedua board P/integration/v1/config/{operational,lighting_lux}; settings minimum_indoor_temperature/maximum_indoor_temperature/gas_threshold/min_lux/max_lux | Parser exact schema/units, separate domain version/hash, expiry, idempotency; NVS persist/verify sebelum applied; test fixtures dan VirtualDevice | Tidak mengganti telemetry v1; firmware lama tidak boleh menganggap tersimpan=applied; approval required |
| SYNC-04 FIRMWARE_SYNC_REQUIRED | broker PUBACK atau unscoped bool states → device ACK dan reported-state terpisah | Both P/integration/v1/ack; ESP32 P/integration/v1/report; boot_id/sequence/message_id/configuration_message_id/payload_hash/status/states | Common monotonic sequence per boot, UUID, UTC seconds, duplicate receipt, applied only after successful configuration; report bool/null GPIO, not desired state; test rollover/expired/replayed ACK | Additive draft namespace, old v1 tetap; approval/authenticated session decision required |
| DECISION-01 FIRMWARE_DECISION_REQUIRED | Internal authenticated openSession → proposed P/integration/v1/session | Kedua board; proposed schema fields in session-proposal.json | Putuskan authenticated boot enrollment/ACL proof/time source/persistence; jangan kirim untuk mengaktifkan receiver yang belum ada | Proposal saja, belum live; signed acceptance required |
| DECISION-02 FIRMWARE_DECISION_REQUIRED | DTO internal → proposed P/integration/v1/display | NodeMCU; six fields/target_device_ids/expires_at | Putuskan numeric registry provisioning, I2C geometry/address/layout; parser null/stale, TTL; virtual receiver test | Belum publisher; approval required |
| NO-01 NO_FIRMWARE_CHANGE | Existing parser/state gates dipertahankan | v1 telemetry/status dan Phase8 config/ACK/report | Tidak menambah field wire baru wajib di fase ini | No silent drift; 180 baseline tests dipertahankan |
| NO-02 NO_FIRMWARE_CHANGE | Lock/fixture tests, CLI preview dan isolated unbound adapter | Backend tooling, VIRTUAL_* only | Tidak mengaktifkan device production | Tidak ada perubahan firmware |

Alasan SYNC-01: humidity resmi memerlukan bukti lima readings, tidak boleh relabel kanal legacy. Alasan SYNC-02: topologi/unit resmi berbeda dari contoh lama; kalibrasi tidak boleh difabrikasi. Alasan SYNC-03: concurrency, independent domain/version, NVS validity dan scope. Alasan SYNC-04: broker receipt tidak membuktikan device applied atau relay physical state.

### Before/after payloads (bukan data lapangan)

SYNC-01 before: `{"message_id":"old-1","sent_at":"2026-11-25T08:00:00Z","readings":[{"sensor_identifier":"indoor_humidity","value":65}]}`. After: full `fixtures/telemetry-indoor.json` (disalin lengkap pada kontrak utama) berisi 5 temperatures +5 humidity +gas_adc dalam envelope sama. Legacy schema1 masih menampilkan old channel; schema2 average harus complete.

SYNC-02 before illustrative obsolete: `{"temp_out":28,"hum_out":70,"ldr_adc":500,"gas_ppm":12}`; bukan envelope v1 yang boleh dipakai. After full `fixtures/telemetry-outdoor.json`: outdoor_temperature/outdoor_humidity/outdoor_light_lux; gas_adc pindah ke ESP32 fixture. Tidak ada conversion antar-unit. Test invalid unit/unknown device/mapping dan stale.

SYNC-03 before illustrative obsolete: `{"min_temp":25,"max_temp":30,"max_gas":1500,"min_lux":10,"max_lux":100}`. After full `fixtures/config-operational.json` dan `config-lighting-lux.json`: scoped/versioned/hash/expiry envelope, exact fields menurut schema. Validate order/nullable, accepted version monotonic, retry identical, expired/replay/collision rejected. Nilai contoh bukan rekomendasi batas operasi.

SYNC-04 before illustrative obsolete: `{"heater":true,"fan1":false,"fan2":false,"light":true}`; tidak cukup identitas/freshness dan tidak boleh dianggap ACK. After full `fixtures/ack.json` dan `report.json`, dengan UUID/boot/sequence/scope/hash atau empat bool|null state. GPIO simulated state tidak pernah physical_verified=true.

### Change control berikutnya

Setiap PR/task perubahan contract harus mencatat old/new package version, diff field/topic/retained/QoS/unit/timestamp, classification per board, before/after payload lengkap atau fixture path versioned, Arduino parser/publisher changes, regression cases, backward compatibility dan acceptance owner. Ubah checksum hanya setelah diff direview. Jangan mengklaim persetujuan hardware dari test PASS.
