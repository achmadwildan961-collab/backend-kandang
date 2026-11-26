# MQTT compatibility

**NO_BACKEND_CHANGE** pada telemetry v1. Prefix exact `smart-kandang/coops/{coop_id}/devices/{device_identifier}`. Registered board types `esp32` dan `nodemcu_esp8266`; identifier case-sensitive. Backend must provision devices/sensors/allowlists; firmware tidak membuat registry rows atau mengubah environment Laravel.

| Suffix | Firmware behavior | Runtime backend |
| --- | --- | --- |
| `/telemetry` | QoS1, non-retained, exactly message_id/sent_at/readings | Existing v1 validator/ingestor |
| `/status` | QoS1 online heartbeat/graceful offline, non-retained | Existing heartbeat/offline checker |
| `/integration/v1/config/operational` | Development-only exact subscriber/parser + ESP32 NVS | Production delivery blocked |
| `/integration/v1/config/lighting_lux` | Independent version/domain, development-only | Production delivery blocked |
| `/integration/v1/ack` | Serializer + Serial draft only; no network sender | Authenticated live ingress absent |
| `/integration/v1/report` | Unknown-only serializer testable, no network sender | Internal validator only |
| `/integration/v1/session` | No sender; no fabricated authentication | Proposal, no receiver |
| `/integration/v1/display` | Optional development-only parser/target/TTL check | No live publisher |
| Any actuator command | No subscriber/driver | Physical commands disabled |

Telemetry has11 Indoor or3 Outdoor channels maximum, valid finite numeric values only, no undocumented boot_id/schema_version/averages/types. Single humidity legacy is not emitted. Five humidity values belong to same envelope/timestamp. Missing channels omitted; all-missing batch suppressed. UTC whole seconds from SNTP, no uptime timestamps; clock holdover1h, batch timestamp strictly increasing before queue. Backend v1 accepts epoch1..2147483647, firmware requires a plausibly synchronized date>=2024. Cross-node exact timestamp pairing is not guaranteed by independent SNTP schedules; no invented delta-temperature tolerance.

Entropy source ESP32 esp_fill_random /ESP8266 os_get_random -> UUIDv4 boot identity plus monotonic message sequence. Probability-based uniqueness, not hardware attestation; entropy quality needs device acceptance. Retry preserves exact serialized bytes/message ID. One pending telemetry slot, at most3 attempts, retry interval2s, TTL30s; no unbounded offline backlog. New batches continue collecting while a slot is occupied and may be dropped. Sequence counter exhaustion suppresses new IDs instead of reuse. No unsupported diagnostics added to wire.

[MQTT library2.5.2](https://github.com/256dpi/arduino-mqtt) bundles lwmqtt. Actual `publish(topic,payload,false,1)` waits for QoS1 PUBACK or timeout; inspected source, not PubSubClient QoS0. RX buffer4328 bytes, TX header256, payload streamed separately by library. Global parser ceiling4095 bytes, topic223; telemetry queue and stored configuration each cap1535 bytes (plus terminating NUL). Display-enabled inbound slot permits4095 bytes; config-only slot1535; default inbound disabled. Normal11-channel output fits1535. Oversized inbound records are dropped, no partial parser acceptance. Callback queues one bounded message and returns; persistence/processing outside callback avoids reentrant publish deadlock. Saturated inbound slot may drop config; backend application ACK retry is still required.

WiFi attempts bounded10s plus backoff1..30s, no infinite reconnect loop. MQTT/TCP timeout2s, ESP32 TLS handshake3s, ESP8266 BearSSL uses socket timeout. Calls can block for these bounded intervals; firmware is cooperative, not a hard real-time task. Sensor collection runs in its own bounded window without MQTT connect waits. TLS peer trust is required by default; no insecure certificate bypass. Numeric broker IP avoids DNS lookup on connect; certificate must authorize that IP. No real broker was contacted by builds/offline tests.

No fixed-timestamp Last Will: backend would consider it stale after a long session, and accepting it as a fresh disconnect could regress status. Graceful disconnect emits current UTC offline; unexpected loss relies on backend offline checker. PUBACK != Laravel ingestion; ACK != applied; GPIO report != physical feedback.

## Draft configuration

Schema1 /contract phase8_draft, exact existing fields. Parse rejects extras/wrong types/units/scope/version0 or >PHP_INT64_MAX, invalid calendar/expiry/order, corrupted hash, lower version, same-version different hash. SHA256 covers all fields except payload_hash with recursively sorted ASCII object keys. Canonical reader preserves the **PHP-generated wire numeric lexemes**, including fractional/scientific values, instead of reserializing Arduino floats. Pretty whitespace/key reorder supported. Alternative equivalent scalar spellings/escaped strings are intentionally rejected if hash differs; backend CanonicalPayload output is the interoperability source. Hash is integrity, not publisher authentication.

Independent ESP32 Preferences blobs (`poultriq-draft` namespace keys `operational`/`lux`) preserve raw payload/hash/version/expiry. One NVS blob write, exact readback comparison and re-validation before in-memory activation. Corrupt recovery or failed/uncertain write blocks subsequent acceptance until reviewed/reboot/recovery; never silently reset replay watermark. Expired recovered config preserves watermark but grants no authority. Identical duplicate doesn't rewrite flash. Actual power-loss wear/durability tests pending. Outdoor is RAM-only reference config, no NVS/persistence claim.

Runtime ACK is only Serial `acknowledged` after successful validation/storage; no applied ACK, report or session network publish exists. Standalone ACK serializer supports contract status shapes for offline tests but is not an authorization boundary. Manual lighting stays unknown because authorized command ingress is absent. Pure preview heater/two-fan/gas/lux logic never drives GPIO.

MQTT2.5.2 advanced callback does not expose RETAIN metadata. Draft configuration may be delivered retained only after explicit staging approval at broker publisher/ACL; firmware cannot independently inspect that bit with this callback. Display expiry and generated_at replay checks exist, but retained-message policy enforcement needs a metadata-capable adapter before live activation. See backend gap report; do not treat draft flags as that fix.
