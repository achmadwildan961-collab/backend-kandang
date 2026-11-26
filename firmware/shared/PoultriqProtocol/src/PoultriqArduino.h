#pragma once
#include <Arduino.h>
#include <MQTT.h>
#include <Wire.h>
#include "PoultriqDefaults.h"
#include "PoultriqDisplay.h"
#include "PoultriqMqttMetadata.h"
#include "PoultriqSensors.h"
#if defined(ESP32)
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <esp_system.h>
#include <esp_sntp.h>
#elif defined(ESP8266)
#include <ESP8266WiFi.h>
#include <WiFiClientSecureBearSSL.h>
#include <coredecls.h>
extern "C" {
#include <user_interface.h>
}
#else
#error "Select ESP32 Dev Module or NodeMCU 1.0 ESP-12E."
#endif

namespace poultry {
inline void randomBytes(uint8_t b[16]) {
#if defined(ESP32)
  esp_fill_random(b, 16);
#else
  os_get_random(b, 16);
#endif
}
inline void newUuid(char out[37]) {
  uint8_t b[16];
  randomBytes(b);
  uuid(b, out);
}
struct WireBus {
  bool write(uint8_t address, const uint8_t *bytes, size_t count) {
    Wire.beginTransmission(address);
    Wire.write(bytes, count);
    return Wire.endTransmission() == 0;
  }
  bool read(uint8_t address, uint8_t *bytes, size_t count) {
    if (Wire.requestFrom(address, uint8_t(count)) != count) {
      while (Wire.available())
        Wire.read();
      return false;
    }
    for (size_t i = 0; i < count; ++i)
      bytes[i] = Wire.read();
    return true;
  }
  bool probe(uint8_t address) {
    Wire.beginTransmission(address);
    return Wire.endTransmission() == 0;
  }
};
#if FW_DRAFT_INTEGRATION
class BoardStorage {
#if defined(ESP32)
  Preferences preferences_;
  bool open_ = false;
#else
  char raw_[2][1536] = {};
  size_t sizes_[2] = {0, 0};
#endif
public:
  bool begin() {
#if defined(ESP32)
    open_ = preferences_.begin("poultriq-draft", false);
    return open_;
#else
    return true; // Reference-only RAM. Never applied/persistent claim on Outdoor.
#endif
  }
  bool read(bool light, char *out, size_t cap, size_t &n) {
#if defined(ESP32)
    if (!open_)
      return false;
    const char *key = light ? "lux" : "operational";
    n = preferences_.getBytesLength(key);
    if (!n)
      return !preferences_.isKey(key);
    if (n >= cap)
      return false;
    if (preferences_.getBytes(key, out, n) != n)
      return false;
    out[n] = 0;
    return true;
#else
    n = sizes_[light];
    if (n >= cap)
      return false;
    memcpy(out, raw_[light], n);
    out[n] = 0;
    return true;
#endif
  }
  bool write(bool light, const char *raw, size_t n) {
    if (n >= 1536)
      return false;
#if defined(ESP32)
    return open_ && preferences_.putBytes(light ? "lux" : "operational", raw, n) == n;
#else
    memcpy(raw_[light], raw, n);
    raw_[light][n] = 0;
    sizes_[light] = n;
    return true;
#endif
  }
};
#endif
static volatile bool timeSyncSignal = false;
#if FW_MQTT_TLS
using TransportSocket = WiFiClientSecure;
#else
using TransportSocket = WiFiClient;
#endif
class BoundedSocket : public TransportSocket {
public:
#if defined(ESP32)
  int connect(const char *host, uint16_t port) override {
    return TransportSocket::connect(host, port, int32_t(FW_NETWORK_TIMEOUT_MS));
  }
  int connect(IPAddress ip, uint16_t port) override {
    return TransportSocket::connect(ip, port, int32_t(FW_NETWORK_TIMEOUT_MS));
  }
#endif
};
class Runtime {
  BoundedSocket socket_;
  MetadataClient metadataSocket_{socket_};
#if FW_MQTT_TLS
#if defined(ESP8266)
  BearSSL::X509List anchors_{FW_CA_CERT};
#endif
#endif
  MQTTClient mqtt_{int(PayloadBytes + TopicBytes + 8), 256};
  Backoff mqttBackoff_;
  WifiSchedule wifiSchedule_;
  bool configured_ = false, clockSynced_ = false;
  uint32_t lastSync_ = 0, heartbeat_ = 0;
  int64_t lastBatchSeconds_ = 0;
  Board board_;
  MessageIds ids_;
  Pending pending_;
  char inbound_[FW_DRAFT_DISPLAY ? PayloadBytes
                                 : (FW_DRAFT_INTEGRATION ? 1536 : 1)] = {},
                                                                  inboundTopic_[TopicBytes] = {};
  size_t inboundLength_ = 0;
  bool inboundRetained_ = false;
  char work_[1024] = {};
  uint32_t feedbackSequence_ = 0;
#if FW_DRAFT_INTEGRATION
  BoardStorage storage_;
  ConfigStore<BoardStorage> operational_{storage_, FW_COOP_ID, FW_DEVICE_IDENTIFIER, false};
  ConfigStore<BoardStorage> light_{storage_, FW_COOP_ID, FW_DEVICE_IDENTIFIER, true};
#endif
  bool route(char *out, const char *suffix) {
    return topic(out, TopicBytes, FW_COOP_ID, FW_DEVICE_IDENTIFIER, suffix);
  }
  bool publish(const char *t, const char *p) {
    // 256dpi MQTT 2.5.2: QoS1 returns only after PUBACK (bounded timeout).
    // This is NOT Laravel ingestion, device apply or physical feedback.
    return strlen(t) + strlen(p) + 8 < PayloadBytes + TopicBytes + 8 &&
           mqtt_.publish(t, p, false, 1);
  }
  void received(char *t, char *bytes, int count) {
    bool retained = false;
    if (!metadataSocket_.metadata.take(retained))
      return; // Unknown metadata fails closed, never inferred from payload.
    // Never publish/subscribe inside callback (library can dispatch while waiting
    // for PUBACK). Copy one bounded inbound record and process after mqtt.loop.
    if (inboundLength_ || count <= 0 || size_t(count) >= sizeof(inbound_) ||
        strlen(t) >= sizeof(inboundTopic_))
      return;
    memcpy(inbound_, bytes, count);
    inbound_[count] = 0;
    strcpy(inboundTopic_, t);
    inboundLength_ = size_t(count);
    inboundRetained_ = retained;
  }
  void processInbound() {
    if (!inboundLength_)
      return;
    char expected[TopicBytes];
    int64_t us = nowSeconds() * 1000000;
#if FW_DRAFT_INTEGRATION
    for (bool light : {false, true}) {
      route(expected,
            light ? "integration/v1/config/lighting_lux" : "integration/v1/config/operational");
      if (!same(expected, inboundTopic_))
        continue;
      auto &store = light ? light_ : operational_;
      if (inboundPolicy(true, inboundRetained_, true, FW_DRAFT_RETAINED_CONFIG) && clockValid() &&
          store.accept(inbound_, inboundLength_, us) && feedbackSequence_ < INT32_MAX) {
        char id[37];
        newUuid(id);
        if (ack(store.current, "acknowledged", FW_COOP_ID, FW_DEVICE_IDENTIFIER, ids_.boot(), id,
                ++feedbackSequence_, nowSeconds(), work_, sizeof(work_))) {
          // Serial-only draft: no authenticated backend session exists. NVS saved
          // is not safety-authorized actuator application; NEVER emit applied.
          Serial.print("DRAFT_ACK_NOT_PUBLISHED ");
          Serial.println(work_);
        }
      } else
        Serial.println("Draft configuration rejected/unavailable");
    }
#endif
#if FW_DRAFT_DISPLAY
    if (inboundPolicy(true, inboundRetained_, false, false) && board_ == Board::Outdoor &&
        clockValid() && route(expected, "integration/v1/display") &&
        same(expected, inboundTopic_)) {
      Display candidate;
      if (parseDisplay(inbound_, inboundLength_, FW_COOP_ID, displayRegistryId, displayIndoorId, us,
                       candidate) &&
          candidate.generated > display.generated) {
        display = candidate;
        displayReceived = millis();
      }
    }
#endif
    inboundLength_ = 0;
  }
  void subscribeDraft() {
    char t[TopicBytes];
    bool ok = true;
#if FW_DRAFT_INTEGRATION
    for (auto suffix :
         {"integration/v1/config/operational", "integration/v1/config/lighting_lux"}) {
      if (!route(t, suffix) || !mqtt_.subscribe(t, 1))
        ok = false;
    }
#endif
#if FW_DRAFT_DISPLAY
    if (board_ == Board::Outdoor && (!route(t, "integration/v1/display") || !mqtt_.subscribe(t, 1)))
      ok = false;
#endif
    if (!ok)
      mqtt_.disconnect();
  }

public:
  Display display;
  uint32_t displayReceived = 0, displayRegistryId = 0, displayIndoorId = 0;
  explicit Runtime(Board b) : board_(b) {}
  bool clockValid() const {
    int64_t seconds = nowSeconds();
    return clockSynced_ && !elapsed(millis(), lastSync_, FW_CLOCK_HOLDOVER_MS) &&
           seconds >= 1704067200 && seconds <= INT32_MAX;
  }
  int64_t nowSeconds() const {
    return int64_t(time(nullptr));
  }
  void begin() {
    Serial.begin(115200);
    uint8_t b[16];
    randomBytes(b);
    ids_.begin(b);
    Serial.println("POULTRIQ: physical outputs absent; network default disabled.");
#if FW_DRAFT_INTEGRATION
    bool opened = storage_.begin();
    bool op = operational_.recover();
    bool li = light_.recover();
    if (!opened || !op || !li)
      Serial.println("Draft storage invalid: acceptance blocked; manual review required");
#endif
    if (!FW_NETWORK_ENABLED)
      return;
    IPAddress ip;
    configured_ = FW_COOP_ID > 0 && identifier(FW_DEVICE_IDENTIFIER) &&
                  !same(FW_DEVICE_IDENTIFIER, "UNCONFIGURED") && strlen(FW_WIFI_SSID) > 0 &&
                  strlen(FW_MQTT_USERNAME) > 0 && strlen(FW_MQTT_PASSWORD) > 0 &&
                  ip.fromString(FW_MQTT_HOST) && uint32_t(ip) != 0;
#if FW_MQTT_TLS
    configured_ = configured_ && strlen(FW_CA_CERT) > 0;
#if defined(ESP32)
    socket_.setCACert(FW_CA_CERT);
    socket_.setHandshakeTimeout(3);
#else
    socket_.setTrustAnchors(&anchors_);
#endif
#endif
    if (!configured_) {
      Serial.println("Network blocked: incomplete identity, numeric broker IP, credentials or CA");
      return;
    }
    socket_.setTimeout(FW_NETWORK_TIMEOUT_MS);
    WiFi.persistent(false);
    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(false);
    mqtt_.begin(FW_MQTT_HOST, FW_MQTT_PORT, metadataSocket_);
    mqtt_.setOptions(20, true, FW_NETWORK_TIMEOUT_MS);
    mqtt_.dropOverflow(true);
    mqtt_.onMessageAdvanced([this](MQTTClient *, char *t, char *p, int n) { received(t, p, n); });
#if defined(ESP32)
    sntp_set_time_sync_notification_cb([](struct timeval *) { timeSyncSignal = true; });
#else
    settimeofday_cb([]() { timeSyncSignal = true; });
#endif
    configTime(0, 0, FW_NTP_SERVER);
  }
  void tick() {
    uint32_t now = millis();
    pending_.expire(now);
    if (timeSyncSignal) {
      timeSyncSignal = false;
      lastSync_ = now;
      clockSynced_ = true;
    }
    // Latch expiry: a clock with no new SNTP event must not become trusted again
    // when millis() wraps after a prolonged outage.
    if (clockSynced_ && elapsed(now, lastSync_, FW_CLOCK_HOLDOVER_MS))
      clockSynced_ = false;
    if (!FW_NETWORK_ENABLED || !configured_)
      return;
    bool wifiConnected = WiFi.status() == WL_CONNECTED;
    WifiAction wifiAction = wifiSchedule_.tick(now, wifiConnected, FW_WIFI_ATTEMPT_MS);
    if (!wifiConnected) {
      if (mqtt_.connected())
        mqtt_.disconnect();
      if (wifiAction == WifiAction::Stop)
        WiFi.disconnect();
      else if (wifiAction == WifiAction::Start)
        WiFi.begin(FW_WIFI_SSID, FW_WIFI_PASSWORD);
      return;
    }
    if (!clockValid())
      return; // No timestamp from uptime; TLS also needs valid time.
    if (!mqtt_.connected()) {
      if (!mqttBackoff_.ready(now))
        return;
      char client[80];
      snprintf(client, sizeof(client), "poultriq-%s", ids_.boot());
      if (!mqtt_.connect(client, FW_MQTT_USERNAME, FW_MQTT_PASSWORD)) {
        mqttBackoff_.failed(now);
        return;
      }
      socket_.setTimeout(FW_NETWORK_TIMEOUT_MS);
      mqttBackoff_.success();
      subscribeDraft();
      heartbeat_ = now - FW_HEARTBEAT_MS;
    }
    if (!mqtt_.loop()) {
      mqttBackoff_.failed(now);
      return;
    }
    processInbound();
    now = millis();
    pending_.expire(now);
    if (pending_.due(now)) {
      bool acknowledged = publish(pending_.route, pending_.payload);
      pending_.result(millis(), acknowledged);
      if (!acknowledged)
        mqttBackoff_.failed(millis());
      return;
    }
    if (elapsed(now, heartbeat_, FW_HEARTBEAT_MS)) {
      char id[50], t[TopicBytes];
      heartbeat_ = now;
      if (ids_.next(id) && route(t, "status") &&
          status(id, nowSeconds(), true, work_, sizeof(work_)))
        publish(t, work_);
    }
  }
  bool submit(const Batch &batch) {
    if (!clockValid() || !FW_NETWORK_ENABLED || pending_.occupied ||
        elapsed(millis(), batch.sampledAt, 30000) || batch.seconds <= lastBatchSeconds_ ||
        batch.seconds > nowSeconds())
      return false;
    char id[50];
    if (!ids_.next(id) ||
        !telemetry(board_, batch, id, pending_.payload, sizeof(pending_.payload)) ||
        !route(pending_.route, "telemetry"))
      return false;
    pending_.created = millis();
    pending_.attempts = 0;
    pending_.occupied = true;
    lastBatchSeconds_ = batch.seconds;
    return true;
  }
  void gracefulDisconnect() {
    if (mqtt_.connected() && clockValid()) {
      char id[50], t[TopicBytes];
      if (ids_.next(id) && route(t, "status") &&
          status(id, nowSeconds(), false, work_, sizeof(work_)))
        publish(t, work_);
      mqtt_.disconnect();
    }
    // Abrupt loss is handled by backend offline timer; no stale fixed-time LWT.
  }
#if FW_DRAFT_INTEGRATION
  const Configuration &operational() const {
    return operational_.current;
  }
  const Configuration &lightingConfig() const {
    return light_.current;
  }
#endif
};
} // namespace poultry
