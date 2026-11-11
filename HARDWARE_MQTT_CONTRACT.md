# Hardware and MQTT handover

Updated 10 November 2026. Flutter communicates only with Laravel REST. The existing Laravel subscriber validates and commits telemetry to MySQL automatically; no per-message manual SQL is required.

## Existing records and safe registration

Read-only local inventory: coop **1** contains device ID **1**, identifier `ESP32_DEVICE_A`, type `esp32`, and ID **2**, identifier `ESP32_DEVICE_B`, type `esp32`. Names and identifiers were preserved. No physical board association is confirmed. Do not claim that the second existing record has already been verified as NodeMCU.

The existing authenticated Admin APIs support both types:

- `POST /api/v1/coops/{coop}/devices`: `{"device_identifier":"CONFIRMED_IDENTIFIER","name":"NodeMCU outdoor","type":"nodemcu"}`.
- `PUT /api/v1/devices/{id}` with the same fields updates an existing record. If the team confirms ID 2 represents NodeMCU, update only its name/type while **sending its existing identifier unchanged**. This preserves IDs, history and topic compatibility. Register a new record only if it is genuinely another board.
- `GET /api/v1/coops/{coop}/devices` returns name, type, identifier, status and last seen. Dashboard and Config display backend metadata. Device status requires the scheduler below.

## Topic and payload contract

`smart-kandang/coops/{coopId}/devices/{deviceIdentifier}/telemetry`

`smart-kandang/coops/{coopId}/devices/{deviceIdentifier}/status`

QoS 1, **retain=false**. Authenticate each publisher; ACL limits it to its two exact topics. Use UTC synchronized time, unique message IDs per device, and resend the exact same bytes/ID for retries. Reusing an ID with different content is rejected. Retained messages, unknown devices, invalid values and conflicting sensor/timestamp records are rejected. Backdated samples retain measurement timestamps and do not create a fresh heartbeat. Broker ACK follows the database commit.

Example ESP32 telemetry (replace illustrative timestamp/ID before publishing):

```json
{"message_id":"esp32-boot17-000123","sent_at":"2026-11-10T12:00:00.000000Z","readings":[{"sensor_identifier":"indoor_dht22_1","value":28.1},{"sensor_identifier":"indoor_dht22_2","value":28.4},{"sensor_identifier":"indoor_dht22_3","value":28.2},{"sensor_identifier":"indoor_dht22_4","value":28.3},{"sensor_identifier":"indoor_dht22_5","value":28.5},{"sensor_identifier":"indoor_humidity","value":65}]}
```

Example NodeMCU telemetry (only include sensors actually attached and confirmed):

```json
{"message_id":"node-boot8-000123","sent_at":"2026-11-10T12:00:00.000000Z","readings":[{"sensor_identifier":"outdoor_temperature","value":26.2},{"sensor_identifier":"outdoor_humidity","value":70}]}
```

Status: `{"message_id":"node-boot8-status124","sent_at":"2026-11-10T12:00:15Z","status":"online"}`. Offline status uses the same schema with `offline`; timeout checks cover silent disconnects. Do not use retained status as a fresh heartbeat. No actuator commands are implemented.

SensorCatalog accepts the five `indoor_dht22_1` through `_5`, `outdoor_temperature` (-40 to 80 Celsius), `indoor_humidity`, `outdoor_humidity` (0–100 percent), and `gas_adc` (0–4095 ADC). Numeric JSON values only; do not send units or sensor_type. The backend supplies those fields.

Historical indoor averages require all five valid readings at the **same exact timestamp**. Send all five in one message where possible. Incomplete batches never fabricate an average. Outdoor/humidity/gas remain independent. Live readings must also be fresh; minimum temperature checks outdoor, maximum checks indoor average, gas checks ADC.

## Optional strict device/sensor mapping

Legacy operation accepts any catalog sensor from a registered device within its coop. Configure `MQTT_DEVICE_SENSORS` after confirming physical ownership to restrict sensors per board. When this map is nonempty, all publishing devices must be listed. Example only, assuming the existing two identifiers are confirmed:

```dotenv
MQTT_DEVICE_SENSORS='{"ESP32_DEVICE_A":["indoor_dht22_1","indoor_dht22_2","indoor_dht22_3","indoor_dht22_4","indoor_dht22_5","indoor_humidity"],"ESP32_DEVICE_B":["outdoor_temperature","outdoor_humidity","gas_adc"]}'
```

Malformed mapping fails closed. Restart the subscriber after configuration changes. No GPIO, board variant, wiring, sensor placement or ADC voltage scaling is assumed. The default simulator emits a full catalog batch; with a strict map enabled, publish the appropriate JSON subset instead.

**ADC compatibility:** 0–4095 is an application acceptance range, not a calibration claim. An ESP8266-based NodeMCU may report a different raw range from ESP32; confirm board, ADC resolution and scaling. A threshold such as 1500 is unusable if the actual source can never exceed 1023. Agree on one documented raw/normalized scale, adjust thresholds accordingly, and verify real readings before relying on gas alarms. No automatic rescaling was introduced.

## Local demonstration and proof boundary

Without a broker, `php artisan mqtt:simulate 1 ESP32_DEVICE_A --transport=local --repeat=2` exercises the production ingestor against the development database. It deliberately inserts simulated readings and may create alert/recovery events; label these as simulated. First receipt is accepted, second is duplicate. `--transport=preview` performs no insertion. With an authenticated configured broker use `--transport=mqtt`; run `php artisan mqtt:listen` separately and verify MySQL/API results, not merely the publisher exit status.

MQTT tests use mocked transport/direct ingestion and a dedicated test database. No remote broker or physical sensor delivery has been verified in this session.
