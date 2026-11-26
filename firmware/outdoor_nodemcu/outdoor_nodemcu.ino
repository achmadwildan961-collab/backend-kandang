#if __has_include("config.local.h")
#include "config.local.h"
#endif
#include "config.example.h"
#include <PoultriqArduino.h>
#include <LiquidCrystal_PCF8574.h>
#if !defined(ESP8266)
#error "This sketch requires NodeMCU ESP8266."
#endif

namespace outdoor {
static_assert((FW_LCD_COLUMNS == 16 && FW_LCD_ROWS == 2) ||
                  (FW_LCD_COLUMNS == 20 && FW_LCD_ROWS == 4),
              "Supported LCD geometry 16x2 or20x4");
static_assert(FW_AHT20_ADDRESS == 0x38, "AHT20 address is0x38");
static_assert(FW_BH1750_ADDRESS == 0x23 || FW_BH1750_ADDRESS == 0x5c,
              "BH1750 address follows ADDR pin");
static_assert(FW_I2C_SDA != FW_I2C_SCL, "Distinct I2C pins required");
poultry::Runtime runtime(poultry::Board::Outdoor);
poultry::WireBus bus;
poultry::Aht20<poultry::WireBus> aht(bus, FW_AHT20_ADDRESS);
poultry::Bh1750<poultry::WireBus> light(bus, FW_BH1750_ADDRESS);
LiquidCrystal_PCF8574 lcd(FW_LCD_ADDRESS);
poultry::Batch batch, last;
uint32_t lastBatch = 0, lcdAt = 0, lcdRetry = 0;
bool collecting = false, ahtDone = false, lightDone = false, lcdReady = false;
void begin() {
  runtime.displayRegistryId = FW_REGISTRY_DEVICE_ID;
  runtime.displayIndoorId = FW_DISPLAY_INDOOR_ID;
  runtime.begin();
  if (FW_SENSOR_PINS_CONFIRMED) {
    Wire.begin(FW_I2C_SDA, FW_I2C_SCL);
    Wire.setClock(100000);
    Wire.setClockStretchLimit(1500);
  }
  lastBatch = millis();
  Serial.println("Outdoor: AHT20 + BH1750 only. Missing lux omitted; no LDR conversion.");
}
void render(uint32_t now) {
  if (!FW_LCD_ENABLED || !FW_SENSOR_PINS_CONFIRMED)
    return;
  if (!lcdReady) {
    if (!poultry::elapsed(now, lcdRetry, 5000))
      return;
    lcdRetry = now;
    if (!bus.probe(FW_LCD_ADDRESS))
      return;
    lcd.begin(FW_LCD_COLUMNS, FW_LCD_ROWS, Wire);
    lcd.setBacklight(255);
    lcdReady = true;
  }
  if (!poultry::elapsed(now, lcdAt, 1000))
    return;
  lcdAt = now;
  if (!bus.probe(FW_LCD_ADDRESS)) {
    lcdReady = false;
    return;
  }
  char lines[4][21] = {};
  unsigned page = (now / 4000) % 5;
  const auto &d = runtime.display;
  bool remote = FW_DRAFT_DISPLAY && runtime.clockValid() &&
                d.valid(runtime.nowSeconds() * 1000000) &&
                !poultry::elapsed(now, runtime.displayReceived, 30000);
  if (remote) {
    if (page < 3) {
      unsigned a = page * 2;
      const char *labels[] = {"In T", "Out T", "In RH", "Out RH", "Gas ADC", "Lux"};
      const char *units[] = {"C", "C", "%", "%", "", "lx"};
      poultry::metricLine(lines[0], 21, labels[a], d.values[a], units[a]);
      poultry::metricLine(lines[1], 21, labels[a + 1], d.values[a + 1], units[a + 1]);
    } else {
      unsigned a = (page - 3) * 2;
      const char *labels[] = {"Heater", "Fan1", "Fan2", "Light"};
      for (unsigned i = 0; i < 2; ++i)
        snprintf(lines[i], 21, "%s rpt:%s", labels[a + i],
                 d.actuators[a + i] == poultry::Truth::Unknown
                     ? "--"
                     : (d.actuators[a + i] == poultry::Truth::On ? "ON" : "OFF"));
    }
  } else {
    bool fresh = last.seconds > 0 && runtime.clockValid() && last.seconds <= runtime.nowSeconds() &&
                 !poultry::elapsed(now, last.sampledAt, 300000);
    unsigned a = (now / 4000) % 3;
    const char *labels[] = {"Local T", "Local RH", "Local lux"};
    const char *units[] = {"C", "%", "lx"};
    poultry::metricLine(lines[0], 21, labels[a], fresh ? last.values[a] : NAN, units[a]);
    snprintf(lines[1], 21, "Backend: --");
  }
  if (FW_LCD_ROWS == 4) {
    snprintf(lines[2], 21, "Relays: DISABLED");
    snprintf(lines[3], 21, remote ? "Draft reported only" : "Display feed absent");
  }
  for (unsigned row = 0; row < FW_LCD_ROWS; ++row) {
    char padded[21];
    memset(padded, ' ', FW_LCD_COLUMNS);
    padded[FW_LCD_COLUMNS] = 0;
    size_t n = strlen(lines[row]);
    if (n > FW_LCD_COLUMNS)
      n = FW_LCD_COLUMNS;
    memcpy(padded, lines[row], n);
    lcd.setCursor(0, row);
    lcd.print(padded);
  }
}
void tick() {
  uint32_t now = millis();
  if (!collecting)
    runtime.tick();
  now = millis();
  if (FW_SENSOR_PINS_CONFIRMED) {
    aht.tick(now);
    light.tick(now);
  }
  if (!collecting && poultry::elapsed(now, lastBatch, FW_SAMPLE_MS)) {
    lastBatch = now;
    batch.clear();
    batch.sampledAt = now;
    batch.seconds = runtime.clockValid() ? runtime.nowSeconds() : 0;
    collecting = true;
    ahtDone = lightDone = !FW_SENSOR_PINS_CONFIRMED;
    if (FW_SENSOR_PINS_CONFIRMED) {
      aht.request(now);
      light.request(now);
    }
  }
  if (collecting) {
    if (!ahtDone && aht.take()) {
      batch.values[0] = aht.temperature;
      batch.values[1] = aht.humidity;
      ahtDone = true;
    }
    if (!lightDone && light.take()) {
      batch.values[2] = light.lux;
      lightDone = true;
    }
    if ((ahtDone && lightDone) || poultry::elapsed(now, batch.sampledAt, 400)) {
      collecting = false;
      last = batch;
      runtime.submit(batch);
    }
  } else
    render(now);
}
} // namespace outdoor
void setup() {
  outdoor::begin();
}
void loop() {
  outdoor::tick();
  delay(1);
}
