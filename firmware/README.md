# Poultriq Arduino firmware — Phase 8.3

26 November 2026. Baseline backend `65a7ef565d88bc11c112789594323802bdd8ae97`, firmware checkpoint `c3ae546`. Phase8.3B menambah backend staging integration dan shared MQTT retain adapter; contract lock tetap unchanged. Bukan klaim hasil uji broker/board/relay fisik. Lihat hasil terbaru di laporan, bukan angka historis8.3A.

- [Indoor ESP32](indoor_esp32/indoor_esp32.ino): lima DHT22 suhu/RH terpisah, MQ135 ADC1 opsional, batch valid/partial, konfigurasi draft di NVS Preferences.
- [Outdoor NodeMCU](outdoor_nodemcu/outdoor_nodemcu.ino): driver AHT20 dan BH1750 dengan deadline/recovery, LCD local atau parser display proposal.
- [Library bersama](shared/PoultriqProtocol): telemetry v1, topics, timestamp, UUID, QoS1 runtime, config integrity/replay, pure control, storage readback, LCD validation.

**Default:** network OFF, sensor pin access OFF, MQ135 OFF, LCD OFF, integration draft OFF. Tidak ada relay driver, direct ON/OFF command subscriber atau physical-output enable flag. `config.example.h` dapat dikompilasi tanpa credentials atau perangkat terhubung. Pengaktifan telemetry memerlukan identitas/ACL/mapping sensor yang sah; bukan izin otomasi fisik.

Mulai dari [Arduino setup](docs/ARDUINO_SETUP.md), [hardware configuration](docs/HARDWARE_CONFIGURATION.md), [MQTT compatibility](docs/MQTT_COMPATIBILITY.md), [backend gaps](docs/BACKEND_GAP_REPORT.md). Hasil verifikasi dan inventory lengkap ada di [laporan](PHASE8_3_REPORT.md); resume ada di [checkpoint](PHASE8_3_CHECKPOINT.md).

## YANG HARUS DIUBAH TIM IOT SEBELUM UPLOAD

1. Buat ignored `config.local.h` di folder masing-masing sketch, gunakan contoh setup. Konfirmasi `FW_COOP_ID`, `FW_DEVICE_IDENTIFIER`, akun MQTT per-device dan mapping Laravel; jangan menebak ID dari nama board.
2. Konfirmasi pin DHT22, ADC1 MQ135/tegangan/warm-up, SDA/SCL, address AHT20/BH1750/LCD, dan geometry. Set `FW_SENSOR_PINS_CONFIRMED=1` hanya setelah pemeriksaan wiring. Prototype pin map bukan sign-off.
3. Isi SSID/password, numeric broker IP khusus lingkungan yang disetujui, port, CA dengan IP SAN yang sesuai, NTP dan credentials. Set `FW_NETWORK_ENABLED=1` hanya untuk pengujian yang diotorisasi. Tidak ada secrets di source repository.
4. BH1750 belum tiba: biarkan pembacaan yang tidak tersedia dihilangkan. Jangan mengisi lux0 sebagai fallback. MQ135 tetap ADC, default disabled sampai tegangan/warm-up dikonfirmasi.
5. Jangan aktifkan draft flags untuk production. Backend belum memiliki authenticated session/ACK ingress maupun display publisher. Tidak ada fake session atau ACK applied. NVS tersimpan tidak sama dengan izin menggerakkan relay.
6. GPIO relay/polarity/interlock/anti-short-cycle/calibration/fail-safe/single control authority dan physical feedback memerlukan keputusan terpisah. Firmware ini tidak menyediakan jalur untuk mengaktifkan relay tersebut.
