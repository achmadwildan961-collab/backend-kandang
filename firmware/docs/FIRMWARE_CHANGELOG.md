# Firmware changelog

## Phase8.3B, 26 November2026

| Change | Before | After / exact Arduino action | Classification |
| --- | --- | --- | --- |
| Inbound RETAIN metadata | MQTT callback loses retain information | Copy updated PoultriqArduino.h/Defaults.h plus new PoultriqMqttMetadata.h; pin MQTT2.5.2. Unknown/retained display rejected; config retained needs FW_DRAFT_RETAINED_CONFIG=1 only after staging approval | FIRMWARE_SYNC_REQUIRED |
| Backend staging auth/outbox/ACK/session/LCD | Test-only feedback, unbound config adapter, LCD DTO only | Credential-scoped staging capability and strict ingress reuse existing lifecycle. No production subscriber or wire field changes; Arduino sender still absent | NO_FIRMWARE_CHANGE for locked wire; FIRMWARE_DECISION_REQUIRED for live sender/identity bridge |
| LCD reported_at | DB datetime leaked into DTO after report | Backend now emits UTC ISO required by existing schema/parser | NO_FIRMWARE_CHANGE |
| Firmware/Laravel evidence | Pure schema bridge only | Actual C++ generated payloads ingested into SQLite/MySQL and tested against REST/LCD; framing test coverage added | NO_FIRMWARE_CHANGE |

No session/ACK/report live sender or relay driver was activated. Operational/lighting_lux remain separate, ACK acknowledged is not applied, and reported states are never inferred from demand. See current report for counts; the original0.1.0 notes below are historical.

0.1.0,26 November2026. Newly implemented from Phase8.2 baseline; original experimental sketches were not supplied on disk and were not modified. Comparison below uses the user's prototype description, not a line-by-line audit of unavailable source.

| Change | Before (reported prototype) | After / exact action | Classification |
| --- | --- | --- | --- |
| Humidity | Single/legacy humidity or average | ESP32 reads5 individual RH values, identifiers indoor_dht22_1_humidity..5_humidity in same readings batch with5 temperatures; no hum_avg/temp_avg | FIRMWARE_SYNC_REQUIRED / NO_BACKEND_CHANGE |
| Light | LDR A0 analog | NodeMCU BH1750 I2C one-time measurement, outdoor_light_lux numeric0..65535; absent omitted, no ADC-to-lux conversion | FIRMWARE_SYNC_REQUIRED / NO_BACKEND_CHANGE |
| Outdoor environment | Prototype sensor assumptions | AHT20 temp/RH separate, exact identifiers, CRC/busy/timeout recovery | FIRMWARE_SYNC_REQUIRED / NO_BACKEND_CHANGE |
| Gas | Experimental threshold/control | Indoor raw12-bit ADC1 only, explicit warmup/wiring enable, no ppm or operational defaults | FIRMWARE_SYNC_REQUIRED / HARDWARE_DECISION_REQUIRED |
| MQTT | Older dev topics and custom ON/OFF | Existing scoped v1 envelope preserved, QoS1 implementation, UUID/UTC/stable retries, heartbeat, no direct command subscription | FIRMWARE_SYNC_REQUIRED / NO_BACKEND_CHANGE |
| Configuration | Prototype hardcoded thresholds | Strict domain/version/scope/hash/expiry, raw canonical integrity, independent NVS readback/recovery, development-only | FIRMWARE_SYNC_REQUIRED / BACKEND_CHANGE_REQUIRED for live delivery |
| ACK/report | LED/output or publish success | ACK shape generated after validated storage, Serial acknowledged only; report states unknown; no fake sessions/applied/physical claim | FIRMWARE_SYNC_REQUIRED / BACKEND_CHANGE_REQUIRED for authenticated ingress |
| Actuators | LEDs demonstrating independent cooling/exhaust or custom direct ON/OFF | Pure temperature/two-fan gas-priority/lux preview; no GPIO output driver, manual remains unavailable | FIRMWARE_DECISION_REQUIRED / HARDWARE_DECISION_REQUIRED |
| LCD | Experimental fixed16x2/address0x27 | Configurable16x2/20x4, local pages, six-metric draft parser, freshness/null/target checks and reported-state label | FIRMWARE_SYNC_REQUIRED / BACKEND_CHANGE_REQUIRED for feed; hardware geometry decision pending |
| Identity/secrets | Experimental hardcoded values | Ignored config.local.h, empty placeholders, network/pin access OFF; per-device credentials, verified TLS required | FIRMWARE_SYNC_REQUIRED / HARDWARE_DECISION_REQUIRED for provisioning |

Backend/locked wire/package unchanged: **NO_FIRMWARE_CHANGE** to the Phase8.2 schema/fixture contract itself. Firmware synchronization implements that baseline; it does not silently approve draft session/display topics or relays.

## YANG HARUS DIUBAH TIM IOT SEBELUM UPLOAD

- Per board: correct core/profile; registered coop/device identifier; separate MQTT username/password/ACL; numeric approved broker IP, port and CA IP SAN; WiFi credentials; NTP source/holdover; sample/heartbeat/timeouts.
- ESP32: confirm five sensor pins and unique wiring; MQ135 ADC1 pin/voltage/attenuation12-bit, warmup interval and presence method before enabling. Do not carry experimental gas thresholds into firmware defaults.
- NodeMCU: SDA/SCL, AHT20 address/module, BH1750 ADDR/mode/bus voltage/pull-ups. BH1750 absence is expected until arrival; never add a fallback lux number.
- Optional LCD: enable only after backpack address/pin mapping/geometry confirmed; provision numeric NodeMCU/Indoor IDs for draft target/report filtering.
- Confirm sensor-specific backend allowlists/capability mapping separately. Draft flags require development mode; no live session/ACK/display pipeline exists yet.
- Relay pins/polarity/boot states/electrical interlocks/calibration/anti-short-cycle/offline authority/physical feedback require sign-off and another implementation phase. This firmware has no activation switch for physical outputs.
