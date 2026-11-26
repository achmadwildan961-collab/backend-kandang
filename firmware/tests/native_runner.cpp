// Host execution of the SAME headers used by the Arduino sketches. No network.
#include <PoultriqDisplay.h>
#include <PoultriqSensors.h>
#include <iostream>
#include <string>
#include <vector>
using namespace poultry;
struct MemoryStorage {
  std::string data[2];
  bool fail = false, corrupt = false;
  unsigned writes = 0;
  bool read(bool light, char *out, size_t cap, size_t &n) {
    n = data[light].size();
    if (n >= cap)
      return false;
    memcpy(out, data[light].data(), n);
    out[n] = 0;
    return true;
  }
  bool write(bool light, const char *raw, size_t n) {
    ++writes;
    if (fail)
      return false;
    data[light].assign(raw, n);
    if (corrupt)
      data[light][0] = '!';
    return true;
  }
};
struct MockBus {
  bool absent = false, busy = false, badCrc = false;
  unsigned writes = 0, reads = 0;
  std::vector<unsigned> commands;
  bool write(uint8_t, const uint8_t *bytes, size_t) {
    ++writes;
    commands.push_back(bytes[0]);
    return !absent;
  }
  bool read(uint8_t address, uint8_t *bytes, size_t n) {
    ++reads;
    if (absent)
      return false;
    if (address == 0x23) {
      if (n != 2)
        return false;
      bytes[0] = 0;
      bytes[1] = 120;
      return true;
    }
    if (n == 1) {
      bytes[0] = 8;
      return true;
    }
    if (n != 7)
      return false;
    uint32_t rh = uint32_t(65.0 / 100 * 1048576), t = uint32_t((28.0 + 50) / 200 * 1048576);
    bytes[0] = busy ? 0x88 : 8;
    bytes[1] = rh >> 12;
    bytes[2] = rh >> 4;
    bytes[3] = (rh << 4) | (t >> 16);
    bytes[4] = t >> 8;
    bytes[5] = t;
    uint8_t crc = 0xff;
    for (unsigned i = 0; i < 6; ++i) {
      crc ^= bytes[i];
      for (unsigned j = 0; j < 8; ++j)
        crc = crc & 0x80 ? uint8_t((crc << 1) ^ 0x31) : uint8_t(crc << 1);
    }
    bytes[6] = badCrc ? uint8_t(crc ^ 1) : crc;
    return true;
  }
};
double num(JsonVariantConst v) {
  if (v.isNull() || v.is<bool>() || !v.is<double>())
    return NAN;
  return v.as<double>();
}
Truth truth(JsonVariantConst v) {
  return v.is<bool>() ? (v.as<bool>() ? Truth::On : Truth::Off) : Truth::Unknown;
}
template <class Target> void setTruth(Target target, Truth value) {
  if (value == Truth::Unknown)
    target.set(nullptr);
  else
    target.set(value == Truth::On);
}
void execute(JsonDocument &in, JsonDocument &out) {
  std::string mode = in["mode"] | "";
  char buffer[PayloadBytes] = {};
  bool ok = false;
  if (mode == "telemetry") {
    Batch b;
    b.seconds = in["now"] | int64_t(1795593600);
    unsigned i = 0;
    for (JsonVariantConst v : in["values"].as<JsonArrayConst>()) {
      if (i < 11)
        b.values[i++] = num(v);
    }
    ok = telemetry(same(in["board"], "outdoor") ? Board::Outdoor : Board::Indoor, b,
                   "12345678-1234-4234-8234-123456789abc-1", buffer,
                   in["capacity"] | sizeof(buffer));
  } else if (mode == "status")
    ok = status("test-status-1", in["now"] | int64_t(1795593600), in["online"] | true, buffer,
                sizeof(buffer));
  else if (mode == "topic") {
    ok = topic(buffer, in["capacity"] | sizeof(buffer), in["coop"] | 1,
               in["device"] | "VIRTUAL_INDOOR", in["suffix"] | "telemetry");
    out["topic"] = buffer;
  } else if (mode == "time") {
    int64_t parsed = parseUtc(in["value"]);
    out["microseconds"] = parsed;
    char t[21];
    if (utc(in["seconds"] | int64_t(1795593600), t))
      out["formatted"] = t;
    ok = parsed >= 0;
  } else if (mode == "sha") {
    const char *s = in["value"] | "";
    Sha256 hash;
    hash.add(s, strlen(s));
    hash.finish(buffer);
    out["hash"] = buffer;
    ok = true;
  } else if (mode == "ids") {
    uint8_t entropy[16];
    for (unsigned i = 0; i < 16; ++i)
      entropy[i] = uint8_t(i + (in["seed"] | 0));
    MessageIds ids;
    ids.begin(entropy);
    JsonArray list = out["ids"].to<JsonArray>();
    for (unsigned i = 0; i < 1000; ++i) {
      char id[50];
      if (!ids.next(id))
        return;
      list.add(id);
    }
    out["boot"] = ids.boot();
    ok = true;
  } else if (mode == "config") {
    MemoryStorage storage;
    storage.fail = in["fail_write"] | false;
    storage.corrupt = in["corrupt_write"] | false;
    bool light = in["light"] | false;
    storage.data[light] = in["stored"] | "";
    ConfigStore<MemoryStorage> store(storage, in["coop"] | 1, in["device"] | "VIRTUAL_INDOOR",
                                     light);
    out["recovered"] = store.recover();
    JsonArray results = out["accepted"].to<JsonArray>();
    for (JsonVariantConst raw : in["steps"].as<JsonArrayConst>()) {
      const char *s = raw.as<const char *>();
      results.add(s && store.accept(s, strlen(s), in["now_us"] | int64_t(1795593600000000)));
    }
    out["version"] = store.current.version;
    out["writes"] = storage.writes;
    out["healthy"] = store.healthy();
    if (store.current.version && store.healthy()) {
      ok = ack(store.current, "acknowledged", in["coop"] | 1, in["device"] | "VIRTUAL_INDOOR",
               "12345678-1234-4234-8234-123456789abc", "12345678-1234-4234-8234-123456789abd", 1,
               (in["now_us"] | int64_t(1795593600000000)) / 1000000, buffer, sizeof(buffer));
    }
  } else if (mode == "report")
    ok = unknownReport(1, "VIRTUAL_INDOOR", "12345678-1234-4234-8234-123456789abc",
                       "12345678-1234-4234-8234-123456789abd", in["sequence"] | 1,
                       in["now"] | int64_t(1795593600), buffer, sizeof(buffer));
  else if (mode == "display") {
    const char *s = in["raw"] | "";
    Display display;
    ok = parseDisplay(s, strlen(s), in["coop"] | 1, in["registry"] | 2, in["indoor"] | 1,
                      in["now_us"] | int64_t(1795593600000000), display);
    JsonArray v = out["values"].to<JsonArray>();
    for (double d : display.values) {
      if (isfinite(d))
        v.add(d);
      else
        v.add(nullptr);
    }
    JsonArray a = out["actuators"].to<JsonArray>();
    for (Truth t : display.actuators) {
      if (t == Truth::Unknown)
        a.add(nullptr);
      else
        a.add(t == Truth::On);
    }
    metricLine(buffer, sizeof(buffer), "Lux", display.values[5], "lx");
    out["line"] = buffer;
  } else if (mode == "control") {
    bool valid = in["config_valid"] | true, fresh = in["fresh"] | true;
    Truth gas =
        gasDemand(num(in["gas"]), num(in["threshold"]), fresh, in["verified"] | false, valid);
    auto thermalResult =
        integratedThermal(num(in["average"]), num(in["min"]), num(in["max"]), gas, fresh, valid);
    out["available"] = thermalResult.available;
    setTruth(out["heater"], thermalResult.heater);
    // Exact Integration/ControlEngine gas-priority wrapper.
    setTruth(out["fans"], thermalResult.fans);
    setTruth(out["gas"], gas);
    setTruth(out["light"],
             lighting(num(in["lux"]), num(in["min_lux"]), num(in["max_lux"]), truth(in["previous"]),
                      same(in["lighting_mode"], "auto"), fresh, valid));
    out["physical_outputs"] = PhysicalOutputsEnabled;
    ok = true;
  } else if (mode == "sensors") {
    MockBus bus;
    bus.absent = in["absent"] | false;
    bus.busy = in["busy"] | false;
    bus.badCrc = in["crc"] | false;
    Aht20<MockBus> aht(bus);
    Bh1750<MockBus> bh(bus);
    aht.tick(0);
    aht.tick(5000);
    aht.tick(5020);
    aht.tick(5040);
    aht.request(5100);
    bh.request(5100);
    aht.tick(5180);
    bh.tick(5279);
    out["bh_early"] = bh.take();
    bh.tick(5280);
    aht.tick(5300);
    out["done"] = aht.take();
    if (isfinite(aht.temperature))
      out["temperature"] = aht.temperature;
    else
      out["temperature"] = nullptr;
    if (isfinite(aht.humidity))
      out["humidity"] = aht.humidity;
    else
      out["humidity"] = nullptr;
    if (isfinite(bh.lux))
      out["lux"] = bh.lux;
    else
      out["lux"] = nullptr;
    if (in["recover"] | false) {
      bus.absent = bus.busy = bus.badCrc = false;
      aht.tick(10400);
      aht.tick(10420);
      aht.tick(10440);
      aht.request(10500);
      aht.tick(10580);
      out["recovered"] = isfinite(aht.temperature);
    }
    out["reads"] = bus.reads;
    out["writes"] = bus.writes;
    JsonArray cmd = out["commands"].to<JsonArray>();
    for (auto c : bus.commands)
      cmd.add(c);
    ok = true;
  } else if (mode == "reconnect") {
    WifiSchedule wifi;
    out["wifi_start"] = wifi.tick(0, false, 10000) == WifiAction::Start;
    out["wifi_wait"] = wifi.tick(9999, false, 10000) == WifiAction::None;
    out["wifi_stop"] = wifi.tick(10000, false, 10000) == WifiAction::Stop;
    out["wifi_backoff"] = wifi.tick(10999, false, 10000) == WifiAction::None;
    out["wifi_retry"] = wifi.tick(11000, false, 10000) == WifiAction::Start;
    out["wifi_connected"] = wifi.tick(11001, true, 10000) == WifiAction::None;
    out["wifi_reconnect"] = wifi.tick(11002, false, 10000) == WifiAction::Start;
    Backoff b;
    uint32_t t = 0xfffffff0;
    b.failed(t);
    out["early"] = b.ready(t + 999);
    out["due"] = b.ready(t + 1000);
    for (int i = 0; i < 20; ++i)
      b.failed(t);
    out["cap"] = b.wait();
    b.success();
    out["reset"] = b.ready(t);
    Pending p;
    p.occupied = true;
    p.created = t;
    strcpy(p.payload, "immutable");
    p.result(t, false);
    out["retry_early"] = p.due(t + 1999);
    out["retry_due"] = p.due(t + 2000);
    p.result(t + 2000, false);
    p.result(t + 4000, false);
    p.expire(t + 4000);
    out["exhausted"] = !p.occupied;
    out["payload"] = p.payload;
    p.occupied = true;
    p.attempts = 0;
    p.created = t;
    p.expire(t + 30000);
    out["expired"] = !p.occupied;
    p.occupied = true;
    p.result(t, true);
    out["puback_cleared"] = !p.occupied;
    ok = true;
  }
  out["ok"] = ok;
  if (ok && (mode == "telemetry" || mode == "status" || mode == "report" || mode == "config")) {
    JsonDocument payload;
    deserializeJson(payload, buffer);
    out["payload"].set(payload.as<JsonVariantConst>());
    out["bytes"] = strlen(buffer);
    out["wire"] = buffer;
  }
}
int main() {
  std::string line;
  while (std::getline(std::cin, line)) {
    JsonDocument in, out;
    if (deserializeJson(in, line)) {
      std::cout << "{\"error\":\"invalid test input\"}\n";
      continue;
    }
    execute(in, out);
    serializeJson(out, std::cout);
    std::cout << std::endl;
  }
  return 0;
}
