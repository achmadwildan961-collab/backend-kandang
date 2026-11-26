#pragma once
#include "PoultriqProtocol.h"

namespace poultry {
// SHA-256 (FIPS 180-4). Integrity only, NOT authentication of a MQTT publisher.
class Sha256 {
  uint32_t h_[8] = {0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19};
  uint8_t block_[64] = {};
  size_t used_ = 0;
  uint64_t bytes_ = 0;
  static uint32_t r(uint32_t x, unsigned n) {
    return (x >> n) | (x << (32 - n));
  }
  void compress() {
    static const uint32_t k[64] = {
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4,
        0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe,
        0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f,
        0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
        0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc,
        0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
        0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116,
        0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7,
        0xc67178f2};
    uint32_t w[64];
    for (unsigned i = 0; i < 16; ++i)
      w[i] = (uint32_t(block_[i * 4]) << 24) | (uint32_t(block_[i * 4 + 1]) << 16) |
             (uint32_t(block_[i * 4 + 2]) << 8) | block_[i * 4 + 3];
    for (unsigned i = 16; i < 64; ++i)
      w[i] = w[i - 16] + (r(w[i - 15], 7) ^ r(w[i - 15], 18) ^ (w[i - 15] >> 3)) + w[i - 7] +
             (r(w[i - 2], 17) ^ r(w[i - 2], 19) ^ (w[i - 2] >> 10));
    uint32_t a = h_[0], b = h_[1], c = h_[2], d = h_[3], e = h_[4], f = h_[5], g = h_[6], h = h_[7];
    for (unsigned i = 0; i < 64; ++i) {
      uint32_t t = h + (r(e, 6) ^ r(e, 11) ^ r(e, 25)) + ((e & f) ^ (~e & g)) + k[i] + w[i];
      uint32_t u = (r(a, 2) ^ r(a, 13) ^ r(a, 22)) + ((a & b) ^ (a & c) ^ (b & c));
      h = g;
      g = f;
      f = e;
      e = d + t;
      d = c;
      c = b;
      b = a;
      a = t + u;
    }
    h_[0] += a;
    h_[1] += b;
    h_[2] += c;
    h_[3] += d;
    h_[4] += e;
    h_[5] += f;
    h_[6] += g;
    h_[7] += h;
  }

public:
  void add(uint8_t b) {
    block_[used_++] = b;
    ++bytes_;
    if (used_ == 64) {
      compress();
      used_ = 0;
    }
  }
  void add(const char *data, size_t n) {
    for (size_t i = 0; i < n; ++i)
      add(uint8_t(data[i]));
  }
  void finish(char out[65]) {
    uint64_t bits = bytes_ * 8;
    add(0x80);
    while (used_ != 56)
      add(0);
    for (int i = 7; i >= 0; --i)
      add(uint8_t(bits >> (i * 8)));
    for (unsigned i = 0; i < 8; ++i)
      snprintf(out + i * 8, 9, "%08lx", (unsigned long)h_[i]);
    out[64] = 0;
  }
};
// Config-only canonical reader: sort ASCII object keys, preserve PHP wire scalar
// lexemes, omit ROOT payload_hash. Never round numbers via Arduino float output.
// Backend sends CanonicalPayload::encode; differently encoded equivalent scalars
// intentionally fail hash rather than guess PHP floating-point normalization.
class ConfigCanonical {
  const char *text_;
  size_t length_, pos_ = 0;
  bool ok_ = true;
  void ws() {
    while (pos_ < length_ && (text_[pos_] == ' ' || text_[pos_] == '\r' || text_[pos_] == '\n' ||
                              text_[pos_] == '\t'))
      ++pos_;
  }
  bool take(char c) {
    ws();
    if (pos_ >= length_ || text_[pos_] != c)
      return ok_ = false;
    ++pos_;
    return true;
  }
  struct Member {
    // Offsets into the bounded immutable input, not copied key buffers. Keeping
    // recursive frames small matters on the ESP8266's 4KiB continuation stack.
    uint16_t start, end, keyStart, keyLength;
  };
  bool keyEquals(const Member &member, const char *key) const {
    return strlen(key) == member.keyLength &&
           memcmp(text_ + member.keyStart, key, member.keyLength) == 0;
  }
  int compareKeys(const Member &a, const Member &b) const {
    size_t n = a.keyLength < b.keyLength ? a.keyLength : b.keyLength;
    int compared = memcmp(text_ + a.keyStart, text_ + b.keyStart, n);
    return compared ? compared : int(a.keyLength) - int(b.keyLength);
  }
  bool string(size_t &start, size_t &end) {
    ws();
    start = pos_;
    if (!take('"'))
      return false;
    // Config has ASCII-only identifiers/timestamps/units. Reject escaped strings
    // instead of ambiguous duplicate keys or alternative Unicode normalization.
    while (pos_ < length_ && text_[pos_] != '"') {
      unsigned char c = text_[pos_++];
      if (c < 32 || c > 126 || c == '\\')
        return ok_ = false;
    }
    if (pos_ >= length_)
      return ok_ = false;
    ++pos_;
    end = pos_;
    return true;
  }
  bool value(Sha256 *digest, unsigned depth) {
    if (depth > 3)
      return ok_ = false;
    ws();
    if (pos_ >= length_)
      return ok_ = false;
    if (text_[pos_] == '{') {
      ++pos_;
      Member members[16];
      unsigned count = 0;
      ws();
      if (pos_ < length_ && text_[pos_] == '}') {
        ++pos_;
        if (digest)
          digest->add("{}", 2);
        return true;
      }
      do {
        if (count == 16)
          return ok_ = false;
        size_t a, b;
        if (!string(a, b) || b - a - 2 >= 40)
          return ok_ = false;
        members[count].keyStart = uint16_t(a + 1);
        members[count].keyLength = uint16_t(b - a - 2);
        for (unsigned j = 0; j < count; ++j)
          if (compareKeys(members[j], members[count]) == 0)
            return ok_ = false;
        if (!take(':'))
          return false;
        ws();
        members[count].start = pos_;
        if (!value(nullptr, depth + 1))
          return false;
        members[count++].end = pos_;
        ws();
        if (pos_ >= length_)
          return ok_ = false;
        if (text_[pos_] == '}') {
          ++pos_;
          break;
        }
        if (!take(','))
          return false;
      } while (ok_);
      size_t finish = pos_;
      for (unsigned i = 0; i < count; ++i)
        for (unsigned j = i + 1; j < count; ++j)
          if (compareKeys(members[i], members[j]) > 0) {
            Member temp = members[i];
            members[i] = members[j];
            members[j] = temp;
          }
      if (digest) {
        digest->add('{');
        bool first = true;
        for (unsigned i = 0; i < count; ++i) {
          if (depth == 0 && keyEquals(members[i], "payload_hash"))
            continue;
          if (!first)
            digest->add(',');
          first = false;
          digest->add('"');
          digest->add(text_ + members[i].keyStart, members[i].keyLength);
          digest->add("\":", 2);
          pos_ = members[i].start;
          if (!value(digest, depth + 1))
            return false;
        }
        digest->add('}');
      }
      pos_ = finish;
      return ok_;
    }
    size_t start = pos_, end = pos_;
    if (text_[pos_] == '"') {
      if (!string(start, end))
        return false;
    } else {
      while (pos_ < length_ && text_[pos_] != ',' && text_[pos_] != '}' && text_[pos_] != ' ' &&
             text_[pos_] != '\r' && text_[pos_] != '\n' && text_[pos_] != '\t')
        ++pos_;
      end = pos_;
      if (end == start)
        return ok_ = false;
    }
    if (digest)
      digest->add(text_ + start, end - start);
    return true;
  }

public:
  ConfigCanonical(const char *raw, size_t len) : text_(raw), length_(len) {}
  bool hash(char out[65]) {
    if (!text_ || !length_ || length_ >= PayloadBytes)
      return false;
    Sha256 d;
    if (!value(&d, 0))
      return false;
    ws();
    if (pos_ != length_)
      return false;
    d.finish(out);
    return true;
  }
};
} // namespace poultry
