# Hardware configuration — NOT production wiring approval

## Indoor ESP32 DevKit V1

Historical DHT22 pins16/17/18/21/23 berada di `FW_DHT_PINS`. Masing-masing mempunyai instance DHT sendiri. Read temperature/RH sekali per sensor per batch, interval>=2500ms, satu sensor transaction per loop, coherent batch deadline1000ms. Kanal gagal dihilangkan secara independen; tidak menggunakan nilai batch sebelumnya. Library DHT mempunyai pulse timeout; scheduling tetap melayani watchdog.

Pin map sensor harus unik. Software menolak flash pins6..11, input-only pins untuk DHT, serta historical relay pins25/26/27/32 sebagai DHT outputs. Ini pemeriksaan konflik dasar, bukan validasi electrical semua GPIO/boot-strapping. `FW_SENSOR_PINS_CONFIRMED=0` mencegah inisialisasi sensor default.

MQ135 `FW_MQ135_PIN=34` historical ADC1, resolution12-bit, attenuation11dB; raw counts0..4095, tanpa ppm/filter/calibrated threshold. `FW_MQ135_ENABLED=0` dan warmup0 menandai belum configured. Tim wajib menentukan tegangan input aman, wiring, interval warmup>0 dan kalibrasi/filter sebelum enable. Analog input tanpa sensor dapat menghasilkan angka plausible: firmware **tidak dapat membuktikan MQ135 terpasang dari ADC saja**; presence/fault detection electrical merupakan blocker hardware. Tidak menciptakan angka warmup atau gas safety default.

GPIO25 heater,26 cooling fan,27 exhaust fan,32 lighting hanya sejarah prototype. Tidak dikonfigurasi/digerakkan oleh sketch. Backend sekarang menggunakan dua fan dengan common temperature OR verified gas demand, bukan cooling/exhaust threshold independen prototype. Interlock heater+fan tetap belum diputuskan.

## Outdoor NodeMCU ESP8266

SDA GPIO4=D2, SCL GPIO5=D1. Wire100kHz, clock-stretch limit1500us. `FW_SENSOR_PINS_CONFIRMED` wajib sebelum akses bus. Verifikasi supply/pull-up/level shifting dan shared-bus secara fisik.

Driver supplied `Aht20<Bus>` khusus **AHT20** address0x38: reset0xBA, init0xBE/08/00, trigger0xAC/33/00, busy/calibration bits, 20-bit RH/temp decode, CRC8 polynomial0x31 init0xFF. Tidak mengasumsikan AHT10 interchangeable. Powerup/recovery delay5s, state waits20ms, conversion first poll80ms/deadline200ms, fault returns NaN lalu re-init. CRC failure tidak menghasilkan measurement. Installed Adafruit_AHTX0 mendukung AHT20 tetapi begin/getEvent mempunyai busy loops tanpa deadline; karena itu sketch memakai driver bounded ini. Pengujian register sequence/decode/CRC memakai mock bus, physical module acceptance belum dilakukan. Lihat sumber driver pembanding resmi [Adafruit AHTX0](https://github.com/adafruit/Adafruit_AHTX0).

BH1750 address0x23 atau0x5C sesuai ADDR. Setiap batch mengirim power-on/reset/one-time high-resolution0x20, menunggu180ms, membaca dua byte dan mengubah hasil sensor digital menjadi lux dengan scale1.2 pada MTreg default69. Ini konversi register BH1750, **bukan LDR ADC-to-lux**. Mode sensitivity/MTreg tambahan tidak diimplementasikan. Sensor absent/read failure => omitted, zero hanya pembacaan darkness yang valid. Protokol dibandingkan dengan [BH1750 library source](https://github.com/claws/BH1750); module fisik belum ada/diuji.

LCD opsional default OFF: `FW_LCD_ADDRESS=0x27`, columns16/rows2 historical. Mendukung16x2 atau20x4; pin mapping PCF8574 library default perlu dikonfirmasi. LCD presence probe/retry5s, redraw1s/page4s. Page local hanya sample fresh, backend absent/stale => `--`, tidak menampilkan relay ON/OFF dari demand. Draft reported page diberi label `rpt`; bukan physical feedback. Numeric registry IDs untuk target NodeMCU dan indoor actuator source perlu provisioning eksplisit.

## Keputusan yang belum disahkan

Identitas fisik dan ACL/TLS; pin/tegangan/pull-up/I2C addresses; actual BH1750 timing/mode; five-DHT sampling/placement; ADC presence/filter/calibration; gas/lux/temperature operational limits; GPIO polarity/boot/power/interlock/minimum runtime/anti-short-cycle; offline/reboot/expired-config authority; single automatic controller; actual actuator feedback; NVS power-cut durability; LCD geometry dan numeric ID provisioning. Tidak ada tanda approval yang dibuat otomatis dari hasil compile/tests.
