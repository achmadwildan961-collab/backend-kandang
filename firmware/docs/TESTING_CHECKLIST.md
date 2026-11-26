# Firmware testing checklist

Actual PASS counts/build sizes are in `../PHASE8_3_REPORT.md`. This checklist separates offline evidence from physical acceptance.

## Offline reproducible checks

1. Build `tests/native_runner.cpp` with C++17 using exactly the shipped shared headers and ArduinoJson7.4.3. Native runner does not compile Arduino/WiFi/Preferences wrappers; actual board builds cover those APIs.
2. `python firmware/tests/test_contract.py --runner <native_runner.exe>`. Python talks to the native C++ process and `tests/backend_validate.php`; this bridge loads unchanged Laravel schema/payload/preview services and invokes no database or external transport.
3. `php artisan firmware:contract-check`: original8schemas/9fixtures/checksums unchanged. Tests load them directly rather than rewriting lock values.
4. Compile Arduino development variants with NVS/display code included. Never upload. Run `git diff --check`; compare exact changed files against the Phase8.3B report. Default profile sizes in the archive are historical8.3A.
5. Phase8.3B `FirmwareStagingIntegrationTest`: actual C++ telemetry/config/ACK/report/display serialization through Laravel isolated DB and REST schema2. Staging MQTT IO is mocked, not a live broker. Full SQLite/MySQL regressions preserve all previous backend tests.
6. Retain metadata tests cover byte fragmentation, multi-byte remaining length, non-PUBLISH packets, single consumption, malformed length latch, and retained config authorization versus retained display rejection. Both Arduino builds cover the Client wrapper. Still require broker/board TLS/reconnect/oversize tests before approval.

Coverage: five temperature/five humidity/gas channels; AHT20/BH1750; partial/empty/invalid values, realistic buffer size, topic identity, unsupported fields, UTC/leap/fractions/range,2,000 unique synthetic entropy+sequence IDs, SHA256 vectors, raw PHP float encoding,64-bit config versions, domain isolation, duplicate/no-flash-write, replay/hash/tamper/expired/future, mock NVS failure/readback/corruption/recovery, ACK/report shapes, LCD null/stale/units/target/expiry, PHP/C++ control parity and gas override, strict missing/expired states, mock AHT20 CRC/busy/absence/recovery, mock BH1750 conversion delay, backoff/retry cap/millis rollover, and absence of direct GPIO driver/command subscription.

Final suite also checks reordered/pretty configuration JSON and nested duplicate keys after compact-offset parser stack optimization. PHP bridge uses a minimal Laravel container with in-memory config; it deliberately does not bootstrap `.env`, application providers, operational cache or databases. Actual schema/payload/canonical/control classes are unchanged.

Native/mock tests are not WiFi/RF/TLS/real broker/NVS silicon timing tests. Compile with network enabled in a test build only proves compile/link of code; empty credentials still prevent startup connection and the binaries are never uploaded here.

## Development actuator simulation (no GPIO)

The native runner exposes the same portable decision functions used by firmware. It accepts JSON lines on stdin; results are demand only, always `physical_outputs:false`. This is a host development simulator, not a device reported-state generator. No LED pins are assigned because none have been approved. The constants in this example are synthetic test inputs, never production defaults:

```powershell
'{"mode":"control","average":15,"min":20,"max":30,"gas":1000,"threshold":1500,"verified":true,"lux":9,"min_lux":10,"max_lux":100,"previous":null,"lighting_mode":"auto"}' | .runtime/phase8-3-tools/native_runner.exe
```

Expected heater demand=true, fan demand=false, light demand=true; change gas to2000 to verify gas priority or average to null to verify missing-temperature handling. `verified:true` is a synthetic test input, not calibration approval. Live runtime supplies gas-policy verification=false. Firmware has no network command or GPIO simulation path to accidentally activate relays.

## Pending isolated broker acceptance

- [ ] Explicit disposable broker/CA/device ACL setup; no production forwarding/VM endpoint.
- [ ] Genuine QoS1 packet/PUBACK evidence for firmware library, disconnect during publish, stable retry bytes/id, backend duplicate ingestion, subscriber retained filtering.
- [ ] Correct clock sync/drift/future/stale handling and two-device timestamp alignment.
- [ ] Scoped authentication/session contract accepted before network ACK/report tests; no manual database session fabrication claimed as production authentication.
- [ ] TLS CA/IP SAN validation, incorrect credentials, wrong coop ACL and reconnect timeout under failure.
- [ ] Metadata-capable retained policy for future draft inbound transport; current callback limitation documented.

## Pending physical acceptance

- [ ] Both boards with verified wiring/power; five DHT22 reads/partial disconnect, CRC/library timing; ADC presence/warmup/filter/calibration.
- [ ] Actual AHT20/arriving BH1750 readings versus reference instruments, shared-bus faults and recovery.
- [ ] ESP32 NVS power-cut during write, persistence/readback/expired watermark, identity reprovisioning procedure and wear.
- [ ] ESP8266 free heap/TLS fragmentation/stack/watchdog over sustained reconnect and maximum display/config payloads.
- [ ] LCD backpack mapping/address/geometry/rendering with unavailable/stale values and reported labels.
- [ ] Hardware/firmware safety decisions and command contract approved before any future relay test. No relay activation in current deliverable.
