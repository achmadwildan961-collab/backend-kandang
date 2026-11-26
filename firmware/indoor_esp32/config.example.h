#pragma once
// Compile-safe placeholders. Override macros in config.local.h, never this file.
// Historical breadboard sensor pins only; NOT approved production wiring.
#ifndef FW_DHT_PINS
#define FW_DHT_PINS {16, 17, 18, 21, 23}
#endif
#ifndef FW_MQ135_PIN
#define FW_MQ135_PIN 34
#endif
#ifndef FW_MQ135_ENABLED
#define FW_MQ135_ENABLED 0
#endif
#ifndef FW_MQ135_WARMUP_MS
#define FW_MQ135_WARMUP_MS 0UL
#endif
// Set a verified warm-up interval >0 and confirm ADC1 voltage/wiring before use.
// Raw ADC 12-bit / 11dB attenuation, no ppm conversion or safety thresholds.
#include <PoultriqDefaults.h>
