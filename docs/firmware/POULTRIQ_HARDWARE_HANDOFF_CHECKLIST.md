# Hardware handoff checklist — belum sign-off

Semua kotak di bawah adalah penerimaan tim hardware yang **belum dilakukan**, bukan checklist otomatis ditandai dari PHPUnit.

- [ ] Konfirmasi registered coop/device IDs/type dan physical board; exact credentials/ACL TLS per device, no cross-coop access.
- [ ] ESP32: lima DHT22 temperature+humidity nyata, pin map, sample batch/timestamp/partial/fault, MQ135 ADC1 voltage/range/filter/calibration; gas tetap ADC sampai kontrak ppm terverifikasi.
- [ ] NodeMCU ESP8266: AHT20 dan BH1750 actual readings, I2C D2/D1 compatibility, voltage/pull-up/addresses, BH1750 mode/timing/lux range. LCD 16x2 atau20x4, address/geometry/layout dan registry-ID provisioning.
- [ ] Sinkronkan SYNC-01…04 pada changelog, termasuk exact identifiers/units, full batch, independent config domains, hash formatting, timestamp whole seconds feedback versus fractional telemetry/config.
- [ ] Uji fixtures dengan Arduino parser yang dipilih; ukuran buffer 16KiB envelope cap bukan rekomendasi RAM; streaming/ArduinoJson capacity dan nested depth harus diukur. Tolak overflow, NaN, wrong type, missing/extra fields, scope mismatch; jangan round versi integer melalui float.
- [ ] Putuskan session authentication/boot UUID/monotonic sequence persistence dan expiry windows. Retired boot/old ACK tidak boleh current. Tidak ada production session receiver pada fase ini.
- [ ] ESP32 NVS Preferences: atomic validated config write, version/hash per domain, power-loss recovery, readback verification sebelum applied. Outdoor config reference persistence policy ditentukan sendiri bersama tim.
- [ ] Putuskan last-known/expired config authority, single controller, behavior saat broker/sensor/offline/reboot faults; backend tests tidak menetapkan physical fail-safe.
- [ ] Disahkan minimum/maximum indoor, gas safety threshold/calibration/recovery debounce, lux thresholds dan hysteresis; equality retains previous lighting state yang sah.
- [ ] Disahkan relay GPIO/polarity/wiring, heating vs lighting lamp, heater-fan interlock, anti-short-cycle/minimum runtime, power/boot states dan physical feedback instrumentation.
- [ ] Test own-device only publication/subscription, invalid/duplicate/out-of-order/stale/retained rejection, disconnect/reconnect, expired config, reboot rollover dan ACK replay dengan isolated broker lebih dahulu.
- [ ] Terima perbedaan broker PUBACK / acknowledged / applied / reported GPIO / physical feedback; jangan label ON dari config/demand.
- [ ] Push provider, credentials, mobile consent/token lifecycle dan delivered evidence diverifikasi terpisah; real push belum diuji.
- [ ] Setujui versi package/wire dan setiap DECISION_REQUIRED sebelum merancang adapter authenticated live terpisah.

**Activation gate:** endpoint commands tetap 409, production MqttTransport/PushProvider tetap disabled, staging adapter virtual-only tidak boleh di-bind ke production outbox. Hardware sign-off bukan bagian yang telah selesai pada phase ini. Tidak ada angka GPIO, polarity atau fail-safe yang diasumsikan.
