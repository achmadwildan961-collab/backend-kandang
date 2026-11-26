#pragma once
#include "PoultriqProtocol.h"
namespace poultry {
// Bus interface: write(address, bytes, count), read(address, bytes, count).
// AHT20 only (0x38, init 0xBE). Bounded state machine; no busy wait loops.
template <class Bus> class Aht20 {
  Bus &bus_;
  uint8_t address_;
  uint8_t state_ = 0;
  uint32_t since_ = 0;
  bool started_ = false, ready_ = false, done_ = false;
  void fault(uint32_t now) {
    state_ = 0;
    since_ = now;
    ready_ = false;
    done_ = true;
    temperature = humidity = NAN;
  }

public:
  double temperature = NAN, humidity = NAN;
  Aht20(Bus &b, uint8_t address = 0x38) : bus_(b), address_(address) {}
  bool ready() const {
    return ready_;
  }
  bool take() {
    bool result = done_;
    done_ = false;
    return result;
  }
  void request(uint32_t now) {
    temperature = humidity = NAN;
    done_ = false;
    if (!ready_ || state_ != 3) {
      done_ = true;
      return;
    }
    const uint8_t cmd[] = {0xac, 0x33, 0x00};
    if (!bus_.write(address_, cmd, 3)) {
      fault(now);
      return;
    }
    state_ = 4;
    since_ = now;
  }
  void tick(uint32_t now) {
    if (!started_) {
      started_ = true;
      since_ = now;
      return;
    }
    if (state_ == 0) {
      if (!elapsed(now, since_, 5000))
        return;
      uint8_t reset = 0xba;
      if (!bus_.write(address_, &reset, 1)) {
        fault(now);
        return;
      }
      state_ = 1;
      since_ = now;
      return;
    }
    if (state_ == 1 && elapsed(now, since_, 20)) {
      const uint8_t init[] = {0xbe, 0x08, 0x00};
      if (!bus_.write(address_, init, 3)) {
        fault(now);
        return;
      }
      state_ = 2;
      since_ = now;
      return;
    }
    if (state_ == 2 && elapsed(now, since_, 20)) {
      uint8_t s = 0;
      if (!bus_.read(address_, &s, 1) || s & 0x80 || !(s & 0x08)) {
        fault(now);
        return;
      }
      state_ = 3;
      ready_ = true;
      return;
    }
    if (state_ != 4 || !elapsed(now, since_, 80))
      return;
    uint8_t data[7];
    if (!bus_.read(address_, data, 7)) {
      fault(now);
      return;
    }
    if (data[0] & 0x80) {
      if (elapsed(now, since_, 200))
        fault(now);
      return;
    }
    uint8_t crc = 0xff;
    for (unsigned i = 0; i < 6; ++i) {
      crc ^= data[i];
      for (unsigned j = 0; j < 8; ++j)
        crc = crc & 0x80 ? uint8_t((crc << 1) ^ 0x31) : uint8_t(crc << 1);
    }
    if (!(data[0] & 0x08) || crc != data[6]) {
      fault(now);
      return;
    }
    uint32_t rh = (uint32_t(data[1]) << 12) | (uint32_t(data[2]) << 4) | (data[3] >> 4);
    uint32_t t = (uint32_t(data[3] & 15) << 16) | (uint32_t(data[4]) << 8) | data[5];
    humidity = double(rh) * 100 / 1048576;
    temperature = double(t) * 200 / 1048576 - 50;
    if (!inRange(temperature, -40, 80))
      temperature = NAN;
    if (!inRange(humidity, 0, 100))
      humidity = NAN;
    state_ = 3;
    done_ = true;
  }
};
// BH1750 normal MTreg=69, one-time high resolution mode 0x20, max180ms.
// Each request starts a NEW measurement, never reuses an old conversion as fresh.
template <class Bus> class Bh1750 {
  Bus &bus_;
  uint8_t address_;
  bool pending_ = false, done_ = false;
  uint32_t since_ = 0;

public:
  double lux = NAN;
  Bh1750(Bus &b, uint8_t address = 0x23) : bus_(b), address_(address) {}
  void request(uint32_t now) {
    lux = NAN;
    done_ = false;
    pending_ = false;
    const uint8_t commands[] = {0x01, 0x07, 0x20};
    for (uint8_t cmd : commands)
      if (!bus_.write(address_, &cmd, 1)) {
        done_ = true;
        return;
      }
    since_ = now;
    pending_ = true;
  }
  void tick(uint32_t now) {
    if (!pending_ || !elapsed(now, since_, 180))
      return;
    pending_ = false;
    done_ = true;
    uint8_t data[2];
    if (!bus_.read(address_, data, 2))
      return;
    double v = ((uint16_t(data[0]) << 8) | data[1]) / 1.2;
    if (inRange(v, 0, 65535))
      lux = v;
  }
  bool take() {
    bool result = done_;
    done_ = false;
    return result;
  }
};
} // namespace poultry
