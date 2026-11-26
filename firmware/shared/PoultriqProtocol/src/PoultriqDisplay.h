#pragma once
#include "PoultriqConfig.h"
namespace poultry {
static const char *const MetricNames[] = {
    "indoor_temperature", "outdoor_temperature", "indoor_humidity", "outdoor_humidity", "gas",
    "outdoor_light_lux"};
static const char *const ActuatorNames[] = {"heating_lamp", "ventilation_fan_1",
                                            "ventilation_fan_2", "lighting_lamp"};
struct Display {
  double values[6] = {NAN, NAN, NAN, NAN, NAN, NAN};
  Truth actuators[4] = {Truth::Unknown, Truth::Unknown, Truth::Unknown, Truth::Unknown};
  int64_t generated = -1, expires = -1;
  bool valid(int64_t now) const {
    return generated >= 0 && generated <= now && expires > now;
  }
};
inline bool metricStatus(const char *s) {
  for (auto x : {"fresh", "stale", "missing", "invalid", "unsupported", "unknown", "unavailable",
                 "unverified_source"})
    if (same(s, x))
      return true;
  return false;
}
inline bool parseDisplay(const char *raw, size_t len, uint32_t coop, uint32_t registryId,
                         uint32_t indoorId, int64_t now, Display &output) {
  if (!registryId || !len || len >= PayloadBytes)
    return false;
  JsonDocument d;
  if (deserializeJson(d, raw, len, DeserializationOption::NestingLimit(6)))
    return false;
  JsonObjectConst o = d.as<JsonObjectConst>();
  const char *const keys[] = {"schema_version",   "contract",           "coop_id", "generated_at",
                              "expires_at",       "target_device_ids",  "fields",  "actuators",
                              "display_geometry", "publication_enabled"};
  if (!exactKeys(o, keys) || !o["schema_version"].is<unsigned>() || o["schema_version"] != 1 ||
      !same(o["contract"], "phase8_draft") || !o["coop_id"].is<uint32_t>() ||
      o["coop_id"].as<uint32_t>() != coop || !o["display_geometry"].isNull() ||
      !o["publication_enabled"].is<bool>() || o["publication_enabled"].as<bool>() ||
      !o["target_device_ids"].is<JsonArrayConst>() || !o["actuators"].is<JsonArrayConst>())
    return false;
  bool target = false;
  for (JsonVariantConst id : o["target_device_ids"].as<JsonArrayConst>()) {
    if (!id.is<uint32_t>() || id.as<uint32_t>() == 0)
      return false;
    target |= id.as<uint32_t>() == registryId;
  }
  Display parsed;
  parsed.generated = parseUtc(o["generated_at"]);
  parsed.expires = parseUtc(o["expires_at"]);
  if (!target || !parsed.valid(now))
    return false;
  JsonObjectConst fields = o["fields"].as<JsonObjectConst>();
  if (!exactKeys(fields, MetricNames))
    return false;
  for (unsigned i = 0; i < 6; ++i) {
    JsonObjectConst f = fields[MetricNames[i]].as<JsonObjectConst>();
    bool hum = i == 2 || i == 3;
    const char *const normal[] = {"value", "unit", "status", "recorded_at"};
    const char *const humid[] = {"value", "unit", "status", "recorded_at", "unit_symbol"};
    if (!(hum ? exactKeys(f, humid) : exactKeys(f, normal)) || !metricStatus(f["status"]) ||
        !same(f["unit"], i < 2 ? "celsius" : (hum ? "percent" : (i == 4 ? "adc" : "lux"))) ||
        (hum && !same(f["unit_symbol"], "%")) ||
        !number(f["value"], i < 2 ? -40 : 0, i < 2 ? 80 : (hum ? 100 : (i == 4 ? 4095 : 65535)),
                true))
      return false;
    int64_t recorded = f["recorded_at"].isNull() ? -1 : parseUtc(f["recorded_at"]);
    if (!f["recorded_at"].isNull() && recorded < 0)
      return false;
    if (same(f["status"], "fresh") && !f["value"].isNull() && recorded >= 0 && recorded <= now &&
        now - recorded <= 300000000)
      parsed.values[i] = f["value"].as<double>();
  }
  bool indoorSeen = false;
  for (JsonObjectConst a : o["actuators"].as<JsonArrayConst>()) {
    const char *const ak[] = {"device_id",     "online",      "status",           "states",
                              "feedback_kind", "reported_at", "physical_verified"};
    JsonObjectConst states = a["states"].as<JsonObjectConst>();
    if (!exactKeys(a, ak) || !exactKeys(states, ActuatorNames) || !a["device_id"].is<uint32_t>() ||
        a["device_id"].as<uint32_t>() == 0 || !a["online"].is<bool>() ||
        (!same(a["status"], "fresh") && !same(a["status"], "missing") &&
         !same(a["status"], "unavailable")) ||
        (!a["feedback_kind"].isNull() && !same(a["feedback_kind"], "gpio") &&
         !same(a["feedback_kind"], "physical")) ||
        !a["physical_verified"].is<bool>() || a["physical_verified"].as<bool>())
      return false;
    int64_t t = a["reported_at"].isNull() ? -1 : parseUtc(a["reported_at"]);
    if (!a["reported_at"].isNull() && t < 0)
      return false;
    bool selected = indoorId && a["device_id"].as<uint32_t>() == indoorId;
    if (selected && indoorSeen)
      return false;
    if (selected)
      indoorSeen = true;
    for (unsigned j = 0; j < 4; ++j) {
      JsonVariantConst state = states[ActuatorNames[j]];
      if (!state.isNull() && !state.is<bool>())
        return false;
      if (selected && a["online"].as<bool>() && same(a["status"], "fresh") && t >= 0 && t <= now &&
          now - t <= 60000000 && !state.isNull())
        parsed.actuators[j] = state.as<bool>() ? Truth::On : Truth::Off;
    }
  }
  output = parsed;
  return true;
}
inline void metricLine(char *out, size_t cap, const char *label, double value, const char *unit) {
  if (!isfinite(value))
    snprintf(out, cap, "%s: --", label);
  else
    snprintf(out, cap, "%s: %.1f%s", label, value, unit);
}
} // namespace poultry
