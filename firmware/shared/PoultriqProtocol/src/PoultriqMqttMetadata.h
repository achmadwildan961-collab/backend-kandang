#pragma once
#include <stddef.h>
#include <stdint.h>

namespace poultry {
inline bool inboundPolicy(bool known, bool retained, bool configuration, bool retainedAuthorized) {
  return known && (!retained || (configuration && retainedAuthorized));
}
// MQTT 3.1.1 framing only, no payload interpretation or allocation. Pinned lwmqtt
// reads exactly one packet before dispatching its callback. Feed decrypted RX
// bytes at the Client boundary; consume metadata once in that callback.
class MqttMetadata {
  uint32_t remaining_ = 0, multiplier_ = 1;
  uint8_t header_ = 0, lengthBytes_ = 0, state_ = 0;
  bool ready_ = false, failed_ = false;
  void complete() {
    ready_ = (header_ >> 4) == 3 && ((header_ >> 1) & 3) != 3;
    state_ = 0;
  }

public:
  void reset() {
    *this = MqttMetadata();
  }
  void feed(const uint8_t *bytes, size_t count) {
    if (failed_)
      return;
    for (size_t i = 0; i < count; ++i) {
      uint8_t b = bytes[i];
      if (state_ == 0) {
        ready_ = false;
        header_ = b;
        remaining_ = 0;
        multiplier_ = 1;
        lengthBytes_ = 0;
        state_ = 1;
      } else if (state_ == 1) {
        remaining_ += (b & 127) * multiplier_;
        ++lengthBytes_;
        if (b & 128) {
          if (lengthBytes_ == 4) {
            failed_ = true;
            ready_ = false;
            return;
          }
          multiplier_ *= 128;
        } else if (remaining_ == 0)
          complete();
        else
          state_ = 2;
      } else if (--remaining_ == 0)
        complete();
    }
  }
  bool take(bool &retained) {
    if (!ready_ || failed_ || state_ != 0)
      return false;
    retained = (header_ & 1) != 0;
    ready_ = false;
    return true;
  }
};
} // namespace poultry

#ifdef ARDUINO
#include <Client.h>
namespace poultry {
class MetadataClient : public Client {
  Client &socket_;

public:
  MqttMetadata metadata;
  explicit MetadataClient(Client &socket) : socket_(socket) {}
  int connect(IPAddress ip, uint16_t port) override {
    metadata.reset();
    return socket_.connect(ip, port);
  }
  int connect(const char *host, uint16_t port) override {
    metadata.reset();
    return socket_.connect(host, port);
  }
  size_t write(uint8_t value) override {
    return socket_.write(value);
  }
  size_t write(const uint8_t *bytes, size_t count) override {
    return socket_.write(bytes, count);
  }
  int available() override {
    return socket_.available();
  }
  int read() override {
    int result = socket_.read();
    if (result >= 0) {
      uint8_t value = uint8_t(result);
      metadata.feed(&value, 1);
    }
    return result;
  }
  int read(uint8_t *bytes, size_t count) override {
    int result = socket_.read(bytes, count);
    if (result > 0)
      metadata.feed(bytes, size_t(result));
    return result;
  }
  int peek() override {
    return socket_.peek();
  }
  void flush() override {
    socket_.flush();
  }
  void stop() override {
    metadata.reset();
    socket_.stop();
  }
  uint8_t connected() override {
    return socket_.connected();
  }
  operator bool() override {
    return bool(socket_);
  }
};
} // namespace poultry
#endif
