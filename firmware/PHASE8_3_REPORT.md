# Laporan implementasi firmware Phase 8.3

26 November 2026, Asia/Jakarta. Workspace `D:\FlutterProjects\smart-kandang-backend`, branch `feat/phase8-3-firmware`, baseline/HEAD `65a7ef565d88bc11c112789594323802bdd8ae97`. Implementasi berada seluruhnya di `firmware/`. Source Laravel, migrations, backend tests, konfigurasi, Flutter, dan locked contracts tidak berubah. Instruksi eksplisit Phase 8.3 mengotorisasi firmware meskipun AGENTS sebelumnya membatasi pekerjaan pada backend.

## Hasil implementasi

| Area | Implementasi aktual | Batas verifikasi/aktivasi |
| --- | --- | --- |
| Indoor ESP32 | Sketch Arduino; lima DHT22 independen untuk suhu dan RH dalam satu batch; missing/invalid channels omitted; MQ135 ADC1 12-bit tanpa ppm | Pin access OFF; MQ135 OFF sampai tegangan/warmup dikonfirmasi. Tidak mengirim legacy humidity/average; Laravel tetap menghitung complete-batch aggregates |
| Outdoor NodeMCU | AHT20 CRC/busy/deadline/re-init, BH1750 digital lux one-time conversion; tidak ada LDR; missing bukan nol | Driver decode/recovery diuji dengan mock bus, belum sensor fisik |
| LCD | Opsional16x2/20x4, local pages; parser proposal enam metric + reported states, target/expiry/freshness/units/null checks | LCD OFF; backend publisher belum ada. Unavailable `--`, demand tidak ditampilkan sebagai reported ON |
| MQTT v1 | Scope coop/device, envelope exact, QoS1 non-retained, UUID boot+sequence, SNTP UTC, heartbeat/graceful offline | Network OFF; tidak menghubungi broker. PUBACK tidak dianggap DB ingestion |
| Reconnection | WiFi timeout/backoff, MQTT timeout, satu pending telemetry slot, immutable retry bytes/ID, tiga attempts, TTL30s, millis rollover | State machine/mock diuji; RF/TLS/watchdog/latency fisik belum diuji |
| Config draft | Operational/lighting_lux terpisah, strict scope/types/hash/64-bit version/expiry/replay; PHP raw scalar canonical compatibility | Development-only. Tidak membuka publisher/ingress Laravel |
| Persistence | ESP32 Preferences raw blob, exact readback/re-validation; duplicate tidak menulis ulang, corrupt state gagal tertutup, expired watermark tetap disimpan | Adapter compile; mock storage faults diuji. NVS power-cut silicon belum diuji. Outdoor hanya RAM reference |
| ACK/report | Draft ACK `acknowledged` hanya Serial setelah accept/store; serializer report semua state unknown | Tidak ada network ACK/report/session, tidak ada fake applied atau physical feedback |
| Decision/simulation | Pure indoor temperature/two-fan gas-priority dan lux hysteresis, unknown untuk missing/stale/expired/manual tanpa authorization | Simulator host native C++ development; live gas policy belum verified. Tidak ada LED pin assignment, relay GPIO driver, command subscriber atau physical enable switch |

Sampling Indoor menjaga paired temperature/RH dari transaksi DHT yang sama, lima instance sensor, interval minimal2.5s dan batch deadline1s. Outdoor menunggu conversion sensor dalam batch deadline400ms. Network blocking calls tidak dijalankan selama collection. Tidak ada nilai lama yang sengaja dikemas ulang sebagai sample baru.

## Verifikasi aktual

| Pemeriksaan | Hasil |
| --- | --- |
| Native C++17 compile, Zig0.14.1, ArduinoJson7.4.3 | PASS exit0, `-O1 -Wall -Wextra` |
| `python firmware/tests/test_contract.py --runner .runtime/phase8-3-tools/native_runner.exe` | **45 tests PASS**, final run1.452s, exit0 (setelah perbaikan stack parser dan runtime) |
| `php artisan firmware:contract-check` | **9 fixtures, 8 schemas verified**, hardware approval=false |
| `php vendor/bin/pint --test firmware/tests/backend_validate.php` | PASS |
| `php -l firmware/tests/backend_validate.php` | PASS |
| Arduino ESP32 default (sebelum optimisasi parser draft) | PASS exit0: flash576472/1310720, RAM35964/327680 byte |
| Arduino final ESP32 development + draft/NVS | PASS exit0: flash1072828/1310720, RAM58800/327680 byte |
| Arduino ESP8266 default (sebelum optimisasi parser draft) | PASS exit0: flash354432/1048576, RAM33288/80192, IRAM60115/65536 byte |
| Arduino final ESP8266 development + draft/display | PASS exit0: flash403680/1048576, RAM47760/80192, IRAM61171/65536 byte |
| ESP8266 binary parser stack frame | `objdump`: frame `ConfigCanonical::value` turun912 menjadi240 byte; actual total runtime stack masih memerlukan acceptance hardware |
| Whitespace/format/source inventory | `git diff --check` PASS; clang-format20.1.8 `--dry-run --Werror` PASS;27 files checked UTF-8/no trailing whitespace/local links PASS |
| Broker integration | **NOT RUN**. Tidak ada isolated Mosquitto/Docker ditemukan di PATH atau proses Mosquitto; tidak menghubungi broker operasional |
| Physical board/sensor/NVS/LCD/relay | **NOT RUN**, tidak ada upload |
| Full Laravel SQLite/MySQL regression | Tidak dijalankan ulang: backend/locked contracts tidak berubah. Bridge menjalankan pure validators/schema/preview tanpa DB; bukan klaim full backend regression |

45 metode tes mencakup subcases:11/3 channels,6 batch vectors, partial humidity terpisah suhu, NaN/null/boolean/range, missing/zero lux, cap payload,8 schema/9 fixture checksums, UTC/calendar/fractions,2,000 synthetic IDs, SHA256 vectors, PHP float/scientific/64-bit canonical encoding, reordered/pretty JSON dan nested duplicate keys, configuration scope/tamper/replay/expiry/domain, NVS mock write/readback/recovery failures, ACK/report schemas, LCD stale/unit/target/expiry,12 PHP/C++ control scenarios, gas priority dengan suhu missing, AHT20/BH1750 decode/CRC/busy/absence/recovery, retry/backoff/rollover dan source tanpa GPIO control. Tidak mengklaim assertion count PHPUnit untuk suite Python ini.

Kegagalan yang ditemukan lalu diperbaiki: ESP8266 tidak memiliki API ESP32 `setHandshakeTimeout`; timeout TLS/socket dipisahkan per core. Harness native awal kehilangan field control karena ArduinoJson proxy dikonversi terlalu awal; target serializer dibuat template. Harness proses diberi output unbuffered dan timeout30s untuk mencegah pengujian menggantung. Tidak mengurangi assertion atau mengubah tes backend.

Review binary kemudian menemukan recursive parser frame912 byte yang berisiko melebihi continuation stack4096 byte milik ESP8266 ketika digabung caller frames. Member key buffers diganti offset16-bit pada input bounded4095 byte; frame final240 byte tanpa heap allocation tambahan. Runtime juga melatch expiry sinkronisasi sehingga clock tanpa SNTP baru tidak kembali dipercaya saat millis wrap setelah outage sangat panjang. Native suite diperluas dan build development kedua board diulang. Profil default yang sudah lulus tidak menjalankan parser draft/network; angka default di atas adalah sebelum perbaikan terakhir ini, bukan hasil upload. Full application bootstrap pada bridge pernah timeout30s; bridge kini hanya membuat container/config in-memory yang diperlukan pure services (tidak memuat `.env`/providers/DB/cache operasional) dan stderr di-drain bersamaan. Validasi menggunakan class Laravel asli tanpa pelemahan.

Log lokal ignored berada pada `.runtime/phase8-3-tools/`: `native-build.log`, `contract-tests.txt`, `final-indoor.log`, `final-indoor-draft.log`, `final-outdoor.log`, `final-outdoor-draft.log`, `final-outdoor-stack-fix.log`, `final-indoor-stack-fix.log`, `stack-parser-final.txt`. Log stack-fix adalah verifikasi source final development; build sebelum perubahan parser dibedakan di atas.

ESP8266 development memakai93% IRAM (termasuk32KiB instruction cache); RAM global59% belum memperhitungkan runtime TLS heap/stack. Compile tidak membuktikan kestabilan memory saat reconnect/maximum payload. Soak test heap fragmentation/watchdog dan pengukuran stack aktual merupakan syarat acceptance, bukan hasil yang sudah diperoleh.

## Toolchain dan reproduksi

Arduino CLI1.6.0; ESP32 core3.3.11 `esp32:esp32:esp32`; ESP8266 core3.1.2 `esp8266:esp8266:nodemcuv2`; ArduinoJson7.4.3; MQTT256dpi2.5.2; DHT1.4.6/AdafruitUnifiedSensor1.1.15; LiquidCrystal_PCF8574 2.2.0. Shared library PoultriqProtocol0.1.0 disediakan dalam repo. AHT20/BH1750 menggunakan bounded drivers di library bersama, hanya perlu Wire bawaan core.

CLI/download/build/native tools dan library berada di ignored `.runtime/phase8-3-tools`. ESP32 core existing dipertahankan; ESP8266 core ditambahkan ke Arduino15. Tidak mengubah konfigurasi IDE global. Build menggunakan `--config-file .runtime/phase8-3-tools/arduino-cli.yaml`, `--jobs 2`, `--library firmware/shared/PoultriqProtocol`, dan build-path terpisah per board.

Default profile memakai seluruh contoh tanpa secrets. Profile development menambah `FW_DEVELOPMENT=1`, `FW_DRAFT_INTEGRATION=1`, `FW_NETWORK_ENABLED=1`, `FW_SENSOR_PINS_CONFIRMED=1`, `FW_COOP_ID=1`; Outdoor juga `FW_DRAFT_DISPLAY=1`, `FW_LCD_ENABLED=1`. Ini hanya kompilasi jalur conditional, credentials tetap kosong dan tidak ada binary yang di-upload. Flag default di source tetap0. Build CLI memverifikasi core/library yang dipakai Arduino IDE; tidak ada klaim GUI IDE atau upload berhasil.

Untuk IDE: install versi core/library di [ARDUINO_SETUP.md](docs/ARDUINO_SETUP.md), copy folder `shared/PoultriqProtocol` utuh ke sketchbook `libraries`, buka `.ino` sesuai board dan pilih Verify/Compile. Header privat `config.local.h` ignored; jangan memakai secrets nyata untuk compile verification. Native runner dapat dibangun dengan C++17 dan include shared `src` serta ArduinoJson `src`; simulator development JSON stdin dicontohkan di [TESTING_CHECKLIST.md](docs/TESTING_CHECKLIST.md).

## Kontrak backend dan handoff integrasi

Telemetry/status tetap `smart-kandang/coops/{coop_id}/devices/{device_identifier}/{telemetry|status}`. Envelope telemetry hanya `message_id`, `sent_at`, `readings[{sensor_identifier,value}]`. Indoor `indoor_dht22_1..5`, `indoor_dht22_1_humidity..5_humidity`, `gas_adc`; Outdoor `outdoor_temperature`, `outdoor_humidity`, `outdoor_light_lux`. Tidak ada migration/API/Flutter change akibat wire v1 ini. Backend harus memprovision registry, explicit sensor allowlists dan verified BH1750 capability setelah acceptance nyata; compile bukan bukti provenance fisik.

Draft config suffix `/integration/v1/config/{operational|lighting_lux}` mengikuti schema/fixture existing; hash firmware mempertahankan scalar lexemes dari output `CanonicalPayload.php`. Tidak mengganti field atau mengubah lock. Status config tersimpan dan Serial acknowledged tidak sama dengan device-applied/relay authorization. Tidak ada custom direct ON/OFF, authenticated boot fabrication atau subscription lintas kandang.

[BACKEND_GAP_REPORT.md](docs/BACKEND_GAP_REPORT.md) merinci B0..B10 dengan exact service, current/expected, safe fix, dampak versi dan tests. Blocker utama:

1. `DeviceFeedback`/`IntegrationPolicy` belum punya authenticated live session/ACK/report boundary. Perlu binding identitas ke credential/ACL/mTLS dan replay/boot isolation, bukan mempercayai payload identity.
2. `ConfigurationDispatcher` production gated fake; adapter staging virtual loopback tidak boleh dipakai untuk hardware. Perlu transport authorized terpisah setelah security/contract approval.
3. `LcdPayload` hanya DTO, belum scoped publisher. Session/display tetap proposal; target numeric IDs/TTL harus disepakati.
4. Callback MQTT2.5.2 tidak menyediakan RETAIN metadata; sebelum inbound live, perlu metadata-capable adapter dan pengujian retain policy.
5. ESP32 belum mempunyai jalur approved untuk fresh Outdoor lux/previous genuine lighting state. Pure hysteresis bukan loop fisik lengkap; putuskan satu otoritas kontrol dan data path.
6. Fixed-timestamp LWT tidak dipakai karena bisa stale. Abrupt offline memerlukan backend checker; kesamaan timestamp lintas node untuk delta-temperature belum dijamin.

Klasifikasi backend: **NO_BACKEND_CHANGE / BACKEND_CHANGE_REQUIRED / HARDWARE_DECISION_REQUIRED**. Changelog juga mempertahankan **NO_FIRMWARE_CHANGE / FIRMWARE_SYNC_REQUIRED / FIRMWARE_DECISION_REQUIRED** sesuai handoff sebelumnya. Perubahan source backend yang diusulkan belum diimplementasikan dalam fase firmware ini.

## YANG HARUS DIUBAH TIM IOT SEBELUM UPLOAD

| Settings/keputusan | Verifikasi yang wajib |
| --- | --- |
| `FW_COOP_ID`, `FW_DEVICE_IDENTIFIER` | ID registry aktual per board dan mapping sensor/allowlist; jangan mengganti ID menurut nama board |
| `FW_WIFI_SSID/PASSWORD`, `FW_MQTT_HOST/PORT/USERNAME/PASSWORD`, `FW_CA_CERT` | Secrets privat, akun per-device, ACL coop/device, numeric approved broker IP, CA/IP SAN; TLS default ON |
| `FW_NTP_SERVER`, `FW_CLOCK_HOLDOVER_MS`, `FW_SAMPLE_MS`, `FW_HEARTBEAT_MS`, network timeouts | Sinkron UTC, drift/reconnect; default sample10s, heartbeat30s, holdover1h, MQTT2s, WiFi attempt10s; approve berdasarkan acceptance lingkungan |
| `FW_DHT_PINS` | Historical16/17/18/21/23; lima sensor, unique pins, electrical/pull-up/placement; belum wiring approval |
| `FW_MQ135_PIN/ENABLED/WARMUP_MS` | HistoricalADC1 GPIO34,12-bit/11dB; batas tegangan, presence detection, warmup>0 yang disepakati, filter/calibration; tidak ada ppm/ambang aman default |
| `FW_I2C_SDA/SCL`, `FW_AHT20_ADDRESS`, `FW_BH1750_ADDRESS` | GPIO4/D2,5/D1, AHT20 0x38, BH1750 0x23/0x5C; supply/pull-up/shared-bus/ADDR/mode; BH1750 fisik belum diuji |
| `FW_LCD_ENABLED/ADDRESS/COLUMNS/ROWS`, registry/display IDs | Historical0x27/16x2;16x2 atau20x4, backpack mapping, target NodeMCU dan sumber ESP32 numeric IDs untuk draft |
| `FW_SENSOR_PINS_CONFIRMED`, `FW_NETWORK_ENABLED` | Default0, hanya diubah setelah wiring dan lingkungan pengujian diotorisasi. Draft flags default0, bukan production enable |
| Relay/control authority | GPIO/polarity/boot/electrical interlock, anti-short-cycle, gas/lux/temp limits, offline/reboot/expired fallback, NVS power-cut, single authority, genuine feedback; belum disahkan dan tidak diimplementasikan sebagai GPIO driver |

Simulasi host tidak memakai GPIO. Manual lighting belum bisa diperintah. Missing sensor tetap unavailable; floating ADC dapat terlihat valid sehingga presence detection membutuhkan keputusan hardware. ESP8266 TLS heap/stack/watchdog dan I2C bus-fault recovery perlu soak test fisik sebelum activation.

## Inventory perubahan

Semua **27 file baru**, tidak ada tracked backend file berubah:

```text
firmware/.clang-format
firmware/.gitignore
firmware/PHASE8_3_CHECKPOINT.md
firmware/PHASE8_3_REPORT.md
firmware/README.md
firmware/docs/ARDUINO_SETUP.md
firmware/docs/BACKEND_GAP_REPORT.md
firmware/docs/FIRMWARE_CHANGELOG.md
firmware/docs/HARDWARE_CONFIGURATION.md
firmware/docs/MQTT_COMPATIBILITY.md
firmware/docs/TESTING_CHECKLIST.md
firmware/indoor_esp32/config.example.h
firmware/indoor_esp32/indoor_esp32.ino
firmware/outdoor_nodemcu/config.example.h
firmware/outdoor_nodemcu/outdoor_nodemcu.ino
firmware/shared/PoultriqProtocol/library.properties
firmware/shared/PoultriqProtocol/src/PoultriqArduino.h
firmware/shared/PoultriqProtocol/src/PoultriqConfig.h
firmware/shared/PoultriqProtocol/src/PoultriqDefaults.h
firmware/shared/PoultriqProtocol/src/PoultriqDisplay.h
firmware/shared/PoultriqProtocol/src/PoultriqIntegrity.h
firmware/shared/PoultriqProtocol/src/PoultriqProtocol.h
firmware/shared/PoultriqProtocol/src/PoultriqSensors.h
firmware/tests/backend_validate.php
firmware/tests/fixtures/sensor_batches.json
firmware/tests/native_runner.cpp
firmware/tests/test_contract.py
```

Git status aktual pada review final: hanya `?? firmware/`, index kosong, HEAD tetap baseline, branch tetap `feat/phase8-3-firmware`. `git diff --quiet <baseline> -- . ':!firmware'` exit0. Tidak commit/stage/push/reset/clean/merge/deploy; tidak switch atau mengubah main/dev. Tidak menyentuh database/VM/services operasional, Flutter, broker atau relay. Toolchain/build/test artifacts ignored bukan deliverable Git.
