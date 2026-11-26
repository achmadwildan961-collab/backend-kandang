#pragma once
// Override in ignored config.local.h BEFORE including config.example.h.
#ifndef FW_NETWORK_ENABLED
#define FW_NETWORK_ENABLED 0
#endif
#ifndef FW_DEVELOPMENT
#define FW_DEVELOPMENT 0
#endif
#ifndef FW_DRAFT_INTEGRATION
#define FW_DRAFT_INTEGRATION 0
#endif
#ifndef FW_DRAFT_DISPLAY
#define FW_DRAFT_DISPLAY 0
#endif
#ifndef FW_DRAFT_RETAINED_CONFIG
#define FW_DRAFT_RETAINED_CONFIG 0
#endif
#if FW_DRAFT_RETAINED_CONFIG && (!FW_DEVELOPMENT || !FW_DRAFT_INTEGRATION)
#error "Retained snapshots require explicit isolated draft configuration authorization."
#endif
#if (FW_DRAFT_INTEGRATION || FW_DRAFT_DISPLAY) && !FW_DEVELOPMENT
#error "Draft integration requires FW_DEVELOPMENT=1; no authenticated live ingress exists."
#endif
#ifndef FW_WIFI_SSID
#define FW_WIFI_SSID ""
#endif
#ifndef FW_WIFI_PASSWORD
#define FW_WIFI_PASSWORD ""
#endif
#ifndef FW_MQTT_HOST
#define FW_MQTT_HOST "0.0.0.0"
#endif
#ifndef FW_MQTT_PORT
#define FW_MQTT_PORT 8883
#endif
#ifndef FW_MQTT_USERNAME
#define FW_MQTT_USERNAME ""
#endif
#ifndef FW_MQTT_PASSWORD
#define FW_MQTT_PASSWORD ""
#endif
#ifndef FW_MQTT_TLS
#define FW_MQTT_TLS 1
#endif
#if !FW_MQTT_TLS && !FW_DEVELOPMENT
#error "Plain MQTT is only allowed in explicitly isolated development."
#endif
#ifndef FW_CA_CERT
#define FW_CA_CERT ""
#endif
#ifndef FW_COOP_ID
#define FW_COOP_ID 0
#endif
#ifndef FW_DEVICE_IDENTIFIER
#define FW_DEVICE_IDENTIFIER "UNCONFIGURED"
#endif
#ifndef FW_NTP_SERVER
#define FW_NTP_SERVER "pool.ntp.org"
#endif
#ifndef FW_SAMPLE_MS
#define FW_SAMPLE_MS 10000UL
#endif
#ifndef FW_NETWORK_TIMEOUT_MS
#define FW_NETWORK_TIMEOUT_MS 2000UL
#endif
#ifndef FW_WIFI_ATTEMPT_MS
#define FW_WIFI_ATTEMPT_MS 10000UL
#endif
#ifndef FW_HEARTBEAT_MS
#define FW_HEARTBEAT_MS 30000UL
#endif
#ifndef FW_CLOCK_HOLDOVER_MS
#define FW_CLOCK_HOLDOVER_MS 3600000UL
#endif
#ifndef FW_SENSOR_PINS_CONFIRMED
#define FW_SENSOR_PINS_CONFIRMED 0
#endif
static_assert(FW_SAMPLE_MS >= 2500 && FW_SAMPLE_MS <= 3600000,
              "DHT22 minimum interval and rollover bounds");
static_assert(FW_NETWORK_TIMEOUT_MS >= 100 && FW_NETWORK_TIMEOUT_MS <= 5000,
              "Bounded MQTT/socket timeout");
static_assert(FW_WIFI_ATTEMPT_MS >= 1000 && FW_WIFI_ATTEMPT_MS <= 30000, "Bounded WiFi attempt");
static_assert(FW_HEARTBEAT_MS >= 1000 && FW_HEARTBEAT_MS < 120000,
              "Backend default offline window 120 seconds");
static_assert(FW_CLOCK_HOLDOVER_MS >= 1000 && FW_CLOCK_HOLDOVER_MS <= 86400000,
              "Bounded clock holdover");
