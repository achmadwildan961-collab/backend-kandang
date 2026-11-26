# Arduino IDE setup

Kedua sketch folder/name sudah mengikuti Arduino IDE. Buka `.ino` dari folder aslinya, jangan salin `.ino` saja tanpa header. Placeholder compile tidak membutuhkan WiFi, broker atau board fisik.

## Dependency profiles

| Dependency | Versi untuk verifikasi |
| --- | --- |
| Arduino CLI | 1.6.0 |
| Espressif ESP32 core / `esp32:esp32:esp32` | 3.3.11, ESP32 Dev Module (classic DevKit V1) |
| ESP8266 core / `esp8266:esp8266:nodemcuv2` | 3.1.2, NodeMCU1.0 ESP-12E |
| ArduinoJson | 7.4.3 |
| MQTT by Joel Gaehwiler /256dpi | 2.5.2 |
| DHT sensor library by Adafruit | 1.4.6 |
| Adafruit Unified Sensor | 1.1.15 (DHT dependency) |
| LiquidCrystal_PCF8574 by Matthias Hertel | 2.2.0 |
| PoultriqProtocol | 0.1.0, supplied in `firmware/shared/` |

Install board cores using Boards Manager (ESP8266 URL `https://arduino.esp8266.com/stable/package_esp8266com_index.json`). Install the pinned libraries via Library Manager. Copy the **entire** `firmware/shared/PoultriqProtocol` folder into your sketchbook `libraries/` directory, or link the development folder. Restart IDE if the library is not found. Maintain one active copy to avoid stale headers. AHT20/BH1750 drivers are supplied here and need only core Wire; no AHT10 library or LDR dependency.

Untuk CLI, `--library` langsung menunjuk source repo:

```powershell
arduino-cli compile --fqbn esp32:esp32:esp32 --library firmware/shared/PoultriqProtocol firmware/indoor_esp32
arduino-cli compile --fqbn esp8266:esp8266:nodemcuv2 --library firmware/shared/PoultriqProtocol firmware/outdoor_nodemcu
```

Gunakan **Verify/Compile**, bukan Upload, sampai hardware checklist selesai. Hasil build aktual, log dan batas verifikasi berada dalam laporan, bukan disimpulkan dari commands di atas.

## Private configuration

`config.local.h` dibaca sebelum example/defaults. Definisikan hanya override yang diperlukan; semua header lokal/credentials/key/build artifacts diabaikan oleh `firmware/.gitignore`.

```cpp
#pragma once
// Empty values intentionally compile; fill privately after identity approval.
#define FW_WIFI_SSID ""
#define FW_WIFI_PASSWORD ""
#define FW_MQTT_HOST "0.0.0.0" // Numeric IP only; DNS lookup is not on reconnect path.
#define FW_MQTT_PORT 8883
#define FW_MQTT_USERNAME ""
#define FW_MQTT_PASSWORD ""
#define FW_CA_CERT "" // PEM trust anchor; never use setInsecure().
#define FW_COOP_ID 0
#define FW_DEVICE_IDENTIFIER "UNCONFIGURED"
#define FW_NETWORK_ENABLED 0
#define FW_SENSOR_PINS_CONFIRMED 0
```

Settings lengkap: shared `PoultriqDefaults.h` (WiFi, MQTT TLS, NTP, sample/heartbeat/network/holdover intervals, development flags) dan board `config.example.h` (DHT/ADC atau I2C/LCD). `FW_SAMPLE_MS >=2500`; default10000ms. Timeout socket/MQTT default2000ms; WiFi attempt10000ms; exponential retry1..30s. TLS default ON. Numeric IP mesti cocok dengan sertifikat IP SAN; hostname brokers belum didukung runtime ini. Plain MQTT hanya compile bila `FW_DEVELOPMENT=1` dan hanya untuk lingkungan isolated yang diotorisasi.

Draft compile coverage: `--build-property 'compiler.cpp.extra_flags=-DFW_DEVELOPMENT=1 -DFW_DRAFT_INTEGRATION=1 -DFW_DRAFT_DISPLAY=1'`. Flag ini tidak menyalakan network karena `FW_NETWORK_ENABLED` tetap0. Jangan menganggap compile flag sebagai authenticated session approval.

Jangan gunakan `config.local.h` nyata saat menjalankan CI/compile verification yang harus memakai contoh. Tidak ada credentials aktual yang dibutuhkan untuk tes native atau kompilasi.

## Offline contract runner

Native runner requires C++17, Python3 and the repository's PHP/Composer dependencies. It invokes unchanged pure Laravel validators without database operations. On this workspace the portable toolchain command is:

```powershell
& .runtime/phase8-3-tools/zig/zig-x86_64-windows-0.14.1/zig.exe c++ -std=c++17 -O1 -Wall -Wextra -Ifirmware/shared/PoultriqProtocol/src -I.runtime/phase8-3-tools/sketchbook/libraries/ArduinoJson/src firmware/tests/native_runner.cpp -o .runtime/phase8-3-tools/native_runner.exe
python firmware/tests/test_contract.py --runner .runtime/phase8-3-tools/native_runner.exe
php artisan firmware:contract-check
```

Adjust only compiler/library/executable paths when running on another workstation. ArduinoJson version must match the tested firmware profile. The runner is a host development tool and must not be uploaded to a board. The two `.ino` sketches are the Arduino deliverables.
