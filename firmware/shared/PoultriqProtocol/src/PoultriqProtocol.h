#pragma once
#include <ArduinoJson.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <math.h>
#include <time.h>

namespace poultry {
constexpr size_t PayloadBytes = 4096;
constexpr size_t TelemetryBytes = 1536;
constexpr size_t TopicBytes = 224;
constexpr bool PhysicalOutputsEnabled = false;
enum class Board { Indoor, Outdoor };
enum class Truth : int8_t { Unknown = -1, Off = 0, On = 1 };
inline bool elapsed(uint32_t now, uint32_t since, uint32_t interval) {
  return uint32_t(now - since) >= interval;
}
inline bool inRange(double n, double lo, double hi) {
  return isfinite(n) && n >= lo && n <= hi;
}
inline bool same(const char *a, const char *b) {
  return a && b && strcmp(a, b) == 0;
}
inline bool identifier(const char *s) {
  if (!s || !*s || strlen(s) > 100)
    return false;
  for (; *s; ++s)
    if (!((*s >= 'a' && *s <= 'z') || (*s >= 'A' && *s <= 'Z') || (*s >= '0' && *s <= '9') ||
          *s == '_' || *s == '-'))
      return false;
  return true;
}
inline bool hex64(const char *s) {
  if (!s || strlen(s) != 64)
    return false;
  for (size_t i = 0; i < 64; ++i)
    if (!((s[i] >= '0' && s[i] <= '9') || (s[i] >= 'a' && s[i] <= 'f')))
      return false;
  return true;
}
inline bool uuidString(const char *s) {
  if (!s || strlen(s) != 36)
    return false;
  for (size_t i = 0; i < 36; ++i) {
    if (i == 8 || i == 13 || i == 18 || i == 23) {
      if (s[i] != '-')
        return false;
    } else if (!((s[i] >= '0' && s[i] <= '9') || (s[i] >= 'a' && s[i] <= 'f') ||
                 (s[i] >= 'A' && s[i] <= 'F')))
      return false;
  }
  return true;
}
inline bool topic(char *out, size_t cap, uint32_t coop, const char *device, const char *suffix) {
  if (!coop || !identifier(device))
    return false;
  const char *allowed[] = {"telemetry",
                           "status",
                           "integration/v1/config/operational",
                           "integration/v1/config/lighting_lux",
                           "integration/v1/ack",
                           "integration/v1/report",
                           "integration/v1/display"};
  bool ok = false;
  for (auto a : allowed)
    ok |= same(a, suffix);
  if (!ok)
    return false;
  int n = snprintf(out, cap, "smart-kandang/coops/%lu/devices/%s/%s", (unsigned long)coop, device,
                   suffix);
  return n > 0 && size_t(n) < cap;
}
inline bool utc(int64_t seconds, char out[21]) {
  if (seconds < 1 || seconds > INT32_MAX)
    return false; // Laravel v1 storage range.
  time_t t = time_t(seconds);
  struct tm *value = gmtime(&t);
  return value && strftime(out, 21, "%Y-%m-%dT%H:%M:%SZ", value) == 20;
}
// Exact UTC calendar validation, including optional 1..6 fractional digits.
inline int64_t parseUtc(const char *s) {
  if (!s)
    return -1;
  size_t n = strlen(s);
  if (n != 20 && (n < 22 || n > 27))
    return -1;
  if (s[4] != '-' || s[7] != '-' || s[10] != 'T' || s[13] != ':' || s[16] != ':' || s[n - 1] != 'Z')
    return -1;
  for (size_t i = 0; i < 19; ++i) {
    if (i == 4 || i == 7 || i == 10 || i == 13 || i == 16)
      continue;
    if (s[i] < '0' || s[i] > '9')
      return -1;
  }
  auto two = [s](int i) { return (s[i] - '0') * 10 + s[i + 1] - '0'; };
  int y = two(0) * 100 + two(2), m = two(5), d = two(8), h = two(11), mi = two(14), se = two(17);
  const int md[] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
  bool leap = y % 4 == 0 && (y % 100 != 0 || y % 400 == 0);
  if (y < 1970 || y > 2038 || m < 1 || m > 12 || d < 1 || d > md[m - 1] + (m == 2 && leap) ||
      h > 23 || mi > 59 || se > 59)
    return -1;
  int64_t days = 0;
  for (int yr = 1970; yr < y; ++yr)
    days += 365 + (yr % 4 == 0 && (yr % 100 != 0 || yr % 400 == 0));
  for (int mo = 1; mo < m; ++mo)
    days += md[mo - 1] + (mo == 2 && leap);
  int64_t sec = ((days + d - 1) * 24 + h) * 3600 + mi * 60 + se;
  if (sec < 1 || sec > INT32_MAX)
    return -1;
  int64_t micros = 0;
  if (n != 20) {
    if (s[19] != '.')
      return -1;
    int digits = 0;
    for (size_t i = 20; i < n - 1; ++i, ++digits) {
      if (s[i] < '0' || s[i] > '9')
        return -1;
      micros = micros * 10 + s[i] - '0';
    }
    while (digits++ < 6)
      micros *= 10;
  }
  return sec * 1000000 + micros;
}
inline void uuid(const uint8_t entropy[16], char out[37]) {
  uint8_t b[16];
  memcpy(b, entropy, 16);
  b[6] = (b[6] & 15) | 64;
  b[8] = (b[8] & 63) | 128;
  snprintf(out, 37, "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x", b[0],
           b[1], b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9], b[10], b[11], b[12], b[13], b[14],
           b[15]);
}
class MessageIds {
  char boot_[37] = {};
  uint32_t sequence_ = 0;

public:
  void begin(const uint8_t random[16]) {
    uuid(random, boot_);
    sequence_ = 0;
  }
  const char *boot() const {
    return boot_;
  }
  bool next(char out[50]) {
    if (!*boot_ || sequence_ == UINT32_MAX)
      return false;
    snprintf(out, 50, "%s-%lu", boot_, (unsigned long)++sequence_);
    return true;
  }
};
inline bool serialize(const JsonDocument &doc, char *out, size_t cap) {
  size_t n = measureJson(doc);
  if (doc.overflowed() || n >= cap || n >= PayloadBytes)
    return false;
  return serializeJson(doc, out, cap) == n;
}
static const char *const IndoorIds[] = {"indoor_dht22_1",
                                        "indoor_dht22_2",
                                        "indoor_dht22_3",
                                        "indoor_dht22_4",
                                        "indoor_dht22_5",
                                        "indoor_dht22_1_humidity",
                                        "indoor_dht22_2_humidity",
                                        "indoor_dht22_3_humidity",
                                        "indoor_dht22_4_humidity",
                                        "indoor_dht22_5_humidity",
                                        "gas_adc"};
static const char *const OutdoorIds[] = {"outdoor_temperature", "outdoor_humidity",
                                         "outdoor_light_lux"};
struct Batch {
  double values[11];
  int64_t seconds = 0;
  uint32_t sampledAt = 0;
  Batch() {
    clear();
  }
  void clear() {
    for (auto &v : values)
      v = NAN;
    seconds = 0;
  }
};
inline bool telemetry(Board board, const Batch &b, const char *id, char *out, size_t cap) {
  char timestamp[21];
  if (!utc(b.seconds, timestamp) || !id || !*id)
    return false;
  JsonDocument doc;
  doc["message_id"] = id;
  doc["sent_at"] = timestamp;
  JsonArray readings = doc["readings"].to<JsonArray>();
  for (size_t i = 0; i < (board == Board::Indoor ? 11u : 3u); ++i) {
    double lo = 0, hi = 100;
    if ((board == Board::Indoor && i < 5) || (board == Board::Outdoor && i == 0)) {
      lo = -40;
      hi = 80;
    }
    if (board == Board::Indoor && i == 10)
      hi = 4095;
    if (board == Board::Outdoor && i == 2)
      hi = 65535;
    if (!inRange(b.values[i], lo, hi))
      continue;
    JsonObject r = readings.add<JsonObject>();
    r["sensor_identifier"] = board == Board::Indoor ? IndoorIds[i] : OutdoorIds[i];
    r["value"] = b.values[i];
  }
  return readings.size() > 0 && serialize(doc, out, cap);
}
inline bool status(const char *id, int64_t seconds, bool online, char *out, size_t cap) {
  char timestamp[21];
  if (!utc(seconds, timestamp))
    return false;
  JsonDocument doc;
  doc["message_id"] = id;
  doc["sent_at"] = timestamp;
  doc["status"] = online ? "online" : "offline";
  return serialize(doc, out, cap);
}
class Backoff {
  uint32_t last_ = 0, wait_ = 0;
  uint8_t failures_ = 0;

public:
  bool ready(uint32_t now) const {
    return elapsed(now, last_, wait_);
  }
  void failed(uint32_t now, uint32_t jitter = 0) {
    last_ = now;
    if (failures_ < 6)
      ++failures_;
    wait_ = (1000u << (failures_ - 1));
    if (wait_ > 30000)
      wait_ = 30000;
    wait_ += jitter % 1000;
  }
  void success() {
    wait_ = 0;
    failures_ = 0;
  }
  uint32_t wait() const {
    return wait_;
  }
};
enum class WifiAction { None, Start, Stop };
class WifiSchedule {
  Backoff backoff_;
  bool attempting_ = false;
  uint32_t started_ = 0;

public:
  WifiAction tick(uint32_t now, bool connected, uint32_t timeout) {
    if (connected) {
      attempting_ = false;
      backoff_.success();
      return WifiAction::None;
    }
    if (attempting_) {
      if (elapsed(now, started_, timeout)) {
        attempting_ = false;
        backoff_.failed(now);
        return WifiAction::Stop;
      }
      return WifiAction::None;
    }
    if (!backoff_.ready(now))
      return WifiAction::None;
    attempting_ = true;
    started_ = now;
    return WifiAction::Start;
  }
};
struct Pending {
  char payload[TelemetryBytes] = {};
  char route[TopicBytes] = {};
  uint32_t created = 0, lastAttempt = 0;
  uint8_t attempts = 0;
  bool occupied = false;
  bool due(uint32_t now) const {
    return occupied && attempts < 3 && !elapsed(now, created, 30000) &&
           (attempts == 0 || elapsed(now, lastAttempt, 2000));
  }
  void expire(uint32_t now) {
    if (occupied && (attempts >= 3 || elapsed(now, created, 30000)))
      occupied = false;
  }
  void result(uint32_t now, bool brokerAck) {
    lastAttempt = now;
    ++attempts;
    if (brokerAck)
      occupied = false;
  }
};
struct ThermalDemand {
  Truth heater = Truth::Unknown, fans = Truth::Unknown;
  bool available = false;
};
// Exact ControlDemandPreview semantics: missing temperature keeps fans unknown,
// even if gas=true. The broader integration service separately exposes gas override.
inline ThermalDemand thermal(double avg, double low, double high, Truth gas, bool fresh,
                             bool configValid) {
  ThermalDemand d;
  if (!fresh || !configValid || !isfinite(avg) || !isfinite(low) || !isfinite(high) ||
      low >= high || gas == Truth::Unknown)
    return d;
  d.available = true;
  d.heater = avg < low ? Truth::On : Truth::Off;
  d.fans = (avg > high || gas == Truth::On) ? Truth::On : Truth::Off;
  return d;
}
inline Truth gasDemand(double adc, double limit, bool fresh, bool policyVerified,
                       bool configValid) {
  if (!fresh || !policyVerified || !configValid || !inRange(adc, 0, 4095) ||
      !inRange(limit, 0, 4095))
    return Truth::Unknown;
  return adc > limit ? Truth::On : Truth::Off;
}
inline ThermalDemand integratedThermal(double avg, double low, double high, Truth gas, bool fresh,
                                       bool configValid) {
  auto result = thermal(avg, low, high, gas, fresh, configValid);
  if (configValid && fresh && gas == Truth::On)
    result.fans = Truth::On;
  return result;
}
inline Truth lighting(double lux, double low, double high, Truth previous, bool automatic,
                      bool fresh, bool configValid) {
  if (!automatic || !fresh || !configValid || !inRange(lux, 0, 65535) || !inRange(low, 0, 65535) ||
      !inRange(high, 0, 65535) || low >= high)
    return Truth::Unknown;
  return lux < low ? Truth::On : (lux > high ? Truth::Off : previous);
}
inline double indoorAverage(const Batch &b, bool fresh) {
  if (!fresh)
    return NAN;
  double sum = 0;
  for (size_t i = 0; i < 5; ++i) {
    if (!inRange(b.values[i], -40, 80))
      return NAN;
    sum += b.values[i];
  }
  return sum / 5;
}
} // namespace poultry
