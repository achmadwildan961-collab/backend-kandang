// ESP32 DevKit V1: five physical DHT22s and optional verified MQ135 ADC1.
#if __has_include("config.local.h")
#include "config.local.h"
#endif
#include "config.example.h"
#include <PoultriqArduino.h>
#include <DHT.h>
#if !defined(ESP32)
#error "This sketch requires ESP32 Dev Module."
#endif

namespace indoor {
constexpr uint8_t pins[5] = FW_DHT_PINS;
static_assert(FW_MQ135_PIN == 32 || FW_MQ135_PIN == 33 || FW_MQ135_PIN == 34 ||
                  FW_MQ135_PIN == 35 || FW_MQ135_PIN == 36 || FW_MQ135_PIN == 39,
              "Classic ESP32 ADC1 required");
static_assert(FW_MQ135_WARMUP_MS <= 86400000UL, "Warm-up rollover bound");
DHT sensors[5] = {DHT(pins[0], DHT22), DHT(pins[1], DHT22), DHT(pins[2], DHT22),
                  DHT(pins[3], DHT22), DHT(pins[4], DHT22)};
poultry::Runtime runtime(poultry::Board::Indoor);
poultry::Batch batch;
uint32_t lastBatch = 0, started = 0, channelAt = 0;
uint8_t channel = 0;
bool collecting = false;
bool pinMapValid() {
  // Never let a local sensor override accidentally drive historical relay pins.
  for (unsigned i = 0; i < 5; ++i) {
    if (pins[i] > 33 || pins[i] == 6 || pins[i] == 7 || pins[i] == 8 || pins[i] == 9 ||
        pins[i] == 10 || pins[i] == 11 || pins[i] == 25 || pins[i] == 26 || pins[i] == 27 ||
        pins[i] == 32 || pins[i] == FW_MQ135_PIN)
      return false;
    for (unsigned j = 0; j < i; ++j)
      if (pins[i] == pins[j])
        return false;
  }
  return true;
}
bool pinsEnabled = false, gasWarmed = false;
void begin() {
  runtime.begin();
  started = millis();
  lastBatch = started;
  pinsEnabled = FW_SENSOR_PINS_CONFIRMED && pinMapValid();
  if (pinsEnabled)
    for (auto &sensor : sensors)
      sensor.begin();
  if (pinsEnabled && FW_MQ135_ENABLED && FW_MQ135_WARMUP_MS > 0) {
    analogReadResolution(12);
    analogSetPinAttenuation(FW_MQ135_PIN, ADC_11db);
  }
  Serial.println("Indoor: sensors require confirmed pin map; heater/fan/light GPIOs untouched.");
}
void tick() {
  uint32_t now = millis();
  // During a coherent sensor batch, avoid synchronous MQTT timeout stretching
  // individual readings across multiple seconds. One DHT transaction per tick.
  if (!collecting)
    runtime.tick();
  now = millis();
  if (!collecting && poultry::elapsed(now, lastBatch, FW_SAMPLE_MS)) {
    lastBatch = now;
    batch.clear();
    batch.sampledAt = now;
    batch.seconds = runtime.clockValid() ? runtime.nowSeconds() : 0;
    collecting = true;
    channel = 0;
    channelAt = now;
  }
  if (!collecting)
    return;
  if (poultry::elapsed(now, batch.sampledAt, 1000)) {
    collecting = false;
    return;
  } // Never publish an overlong batch.
  if (channel < 5) {
    if (!poultry::elapsed(now, channelAt, 10))
      return;
    channelAt = now;
    if (pinsEnabled) {
      // Library caches ONE fresh sensor transaction for the paired reads. Every
      // sensor has its own DHT instance, >=2.5s intervals, no previous batch reuse.
      double t = sensors[channel].readTemperature();
      double h = sensors[channel].readHumidity();
      batch.values[channel] = poultry::inRange(t, -40, 80) ? t : NAN;
      batch.values[channel + 5] = poultry::inRange(h, 0, 100) ? h : NAN;
    }
    ++channel;
    return;
  }
  if (FW_MQ135_WARMUP_MS > 0 && poultry::elapsed(now, started, FW_MQ135_WARMUP_MS))
    gasWarmed = true;
  if (pinsEnabled && FW_MQ135_ENABLED && gasWarmed)
    batch.values[10] = analogRead(FW_MQ135_PIN);
  collecting = false;
  runtime.submit(batch);
#if FW_DRAFT_INTEGRATION
  const auto &config = runtime.operational();
  bool fresh = runtime.clockValid() && !poultry::elapsed(now, batch.sampledAt, 300000);
  // Gas calibration/policy is NOT approved. Preview stays unknown by default.
  auto gas = poultry::gasDemand(batch.values[10], config.gas, fresh, false,
                                config.valid(runtime.nowSeconds() * 1000000));
  auto demand =
      poultry::integratedThermal(poultry::indoorAverage(batch, fresh), config.low, config.high, gas,
                                 fresh, config.valid(runtime.nowSeconds() * 1000000));
  (void)demand; // Deliberately no GPIO driver, commands or reported-state mutation.
#endif
}
} // namespace indoor
void setup() {
  indoor::begin();
}
void loop() {
  indoor::tick();
  delay(1);
}
