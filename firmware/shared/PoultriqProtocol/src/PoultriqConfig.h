#pragma once
#include "PoultriqIntegrity.h"

namespace poultry {
template <size_t N> bool exactKeys(JsonObjectConst o, const char *const (&keys)[N]) {
  if (o.isNull() || o.size() != N)
    return false;
  for (auto k : keys)
    if (o[k].isUnbound())
      return false;
  return true;
}
inline bool number(JsonVariantConst v, double lo, double hi, bool nullable = false) {
  return (nullable && v.isNull()) ||
         (!v.is<bool>() && v.is<double>() && inRange(v.as<double>(), lo, hi));
}
struct Configuration {
  uint64_t version = 0;
  bool light = false, automatic = false;
  double low = NAN, high = NAN, gas = NAN;
  int64_t generated = -1, expires = -1;
  char message[65] = {}, hash[65] = {};
  bool valid(int64_t nowUs) const {
    return version && generated <= nowUs && expires > nowUs;
  }
};
inline const char *domain(bool light) {
  return light ? "lighting_lux" : "operational";
}
inline bool parseConfig(const char *raw, size_t len, uint32_t coop, const char *device, bool light,
                        int64_t nowUs, Configuration &result, bool recovery = false) {
  if (!raw || !len || len >= PayloadBytes || !identifier(device) || !coop)
    return false;
  JsonDocument doc;
  if (deserializeJson(doc, raw, len, DeserializationOption::NestingLimit(4)))
    return false;
  JsonObjectConst root = doc.as<JsonObjectConst>();
  const char *const keys[] = {"schema_version",    "contract",   "message_id",     "coop_id",
                              "device_identifier", "domain",     "config_version", "settings",
                              "generated_at",      "expires_at", "payload_hash"};
  if (!exactKeys(root, keys) || !root["schema_version"].is<unsigned>() ||
      root["schema_version"].as<unsigned>() != 1 || !same(root["contract"], "phase8_draft") ||
      !root["coop_id"].is<uint32_t>() || root["coop_id"].as<uint32_t>() != coop ||
      !same(root["device_identifier"], device) || !same(root["domain"], domain(light)) ||
      !root["config_version"].is<uint64_t>() || root["config_version"].as<uint64_t>() == 0 ||
      root["config_version"].as<uint64_t>() > INT64_MAX || !hex64(root["message_id"]) ||
      !hex64(root["payload_hash"]))
    return false;
  Configuration c;
  c.light = light;
  c.version = root["config_version"].as<uint64_t>();
  c.generated = parseUtc(root["generated_at"]);
  c.expires = parseUtc(root["expires_at"]);
  if (c.generated < 0 || c.expires <= c.generated || (!recovery && (nowUs < 0 || !c.valid(nowUs))))
    return false;
  JsonObjectConst s = root["settings"].as<JsonObjectConst>();
  if (light) {
    const char *const fields[] = {"lighting_mode", "min_lux", "max_lux", "threshold_unit",
                                  "measurement_version"};
    if (!exactKeys(s, fields) ||
        (!same(s["lighting_mode"], "auto") && !same(s["lighting_mode"], "manual")) ||
        !same(s["threshold_unit"], "lux") || !same(s["measurement_version"], "bh1750_lux_v1") ||
        !number(s["min_lux"], 0, 65535, true) || !number(s["max_lux"], 0, 65535, true))
      return false;
    c.automatic = same(s["lighting_mode"], "auto");
    c.low = s["min_lux"].isNull() ? NAN : s["min_lux"].as<double>();
    c.high = s["max_lux"].isNull() ? NAN : s["max_lux"].as<double>();
  } else {
    const char *const fields[] = {"minimum_indoor_temperature", "maximum_indoor_temperature",
                                  "gas_threshold", "temperature_unit", "gas_unit"};
    if (!exactKeys(s, fields) || !same(s["temperature_unit"], "celsius") ||
        !same(s["gas_unit"], "adc") || !number(s["minimum_indoor_temperature"], -40, 80, true) ||
        !number(s["maximum_indoor_temperature"], -40, 80) || !number(s["gas_threshold"], 0, 4095))
      return false;
    c.low = s["minimum_indoor_temperature"].isNull() ? NAN
                                                     : s["minimum_indoor_temperature"].as<double>();
    c.high = s["maximum_indoor_temperature"].as<double>();
    c.gas = s["gas_threshold"].as<double>();
  }
  if (isfinite(c.low) && isfinite(c.high) && c.low >= c.high)
    return false;
  char computed[65];
  ConfigCanonical canon(raw, len);
  if (!canon.hash(computed) || !same(computed, root["payload_hash"]))
    return false;
  strcpy(c.hash, root["payload_hash"]);
  strcpy(c.message, root["message_id"]);
  result = c;
  return true;
}
enum class Accept { Rejected, Duplicate, NewVersion };
inline Accept compare(const Configuration &current, const Configuration &candidate) {
  if (candidate.version < current.version)
    return Accept::Rejected;
  if (candidate.version == current.version)
    return same(current.hash, candidate.hash) ? Accept::Duplicate : Accept::Rejected;
  return Accept::NewVersion;
}
// Same state machine used with Preferences (ESP32) and RAM-only development
// storage (ESP8266). A torn/corrupt stored blob fails closed, never resets version.
template <class Storage> class ConfigStore {
  Storage &storage_;
  uint32_t coop_;
  const char *device_;
  bool light_;
  bool healthy_ = false;
  char scratch_[1536] = {};

public:
  Configuration current;
  ConfigStore(Storage &s, uint32_t coop, const char *device, bool light)
      : storage_(s), coop_(coop), device_(device), light_(light) {}
  bool recover() {
    size_t n = 0;
    if (!storage_.read(light_, scratch_, sizeof(scratch_), n)) {
      healthy_ = false;
      return false;
    }
    if (!n)
      return healthy_ = true;
    healthy_ = parseConfig(scratch_, n, coop_, device_, light_, 0, current, true);
    return healthy_;
  }
  bool healthy() const {
    return healthy_;
  }
  bool accept(const char *raw, size_t n, int64_t nowUs) {
    if (!healthy_)
      return false;
    Configuration next;
    if (!parseConfig(raw, n, coop_, device_, light_, nowUs, next))
      return false;
    Accept mode = compare(current, next);
    if (mode == Accept::Rejected)
      return false;
    if (mode == Accept::Duplicate)
      return true;
    if (!storage_.write(light_, raw, n)) {
      healthy_ = false;
      return false;
    }
    size_t read = 0;
    if (!storage_.read(light_, scratch_, sizeof(scratch_), read) || read != n ||
        memcmp(scratch_, raw, n) != 0) {
      healthy_ = false;
      return false;
    }
    Configuration verified;
    if (!parseConfig(scratch_, read, coop_, device_, light_, nowUs, verified)) {
      healthy_ = false;
      return false;
    }
    current = verified;
    return true;
  }
};
inline bool feedbackBase(JsonDocument &d, const char *kind, uint32_t coop, const char *device,
                         const char *boot, const char *id, uint32_t sequence, int64_t now) {
  char t[21];
  if (!utc(now, t) || !sequence || sequence > INT32_MAX || !coop || !identifier(device) ||
      !uuidString(boot) || !uuidString(id))
    return false;
  d["schema_version"] = 1;
  d["kind"] = kind;
  d["message_id"] = id;
  d["coop_id"] = coop;
  d["device_identifier"] = device;
  d["boot_id"] = boot;
  d["sequence"] = sequence;
  d["sent_at"] = t;
  return true;
}
inline bool ack(const Configuration &c, const char *state, uint32_t coop, const char *device,
                const char *boot, const char *id, uint32_t sequence, int64_t now, char *out,
                size_t cap) {
  if (!c.valid(now * 1000000) ||
      (!same(state, "acknowledged") && !same(state, "applied") && !same(state, "rejected")))
    return false;
  JsonDocument d;
  if (!feedbackBase(d, "ack", coop, device, boot, id, sequence, now))
    return false;
  d["configuration_message_id"] = c.message;
  d["domain"] = domain(c.light);
  d["config_version"] = c.version;
  d["payload_hash"] = c.hash;
  d["status"] = state;
  return serialize(d, out, cap);
}
inline bool unknownReport(uint32_t coop, const char *device, const char *boot, const char *id,
                          uint32_t sequence, int64_t now, char *out, size_t cap) {
  JsonDocument d;
  if (!feedbackBase(d, "report", coop, device, boot, id, sequence, now))
    return false;
  d["feedback_kind"] = "gpio";
  JsonObject s = d["states"].to<JsonObject>();
  for (auto name : {"heating_lamp", "ventilation_fan_1", "ventilation_fan_2", "lighting_lamp"})
    s[name] = nullptr;
  return serialize(d, out, cap);
}
} // namespace poultry
