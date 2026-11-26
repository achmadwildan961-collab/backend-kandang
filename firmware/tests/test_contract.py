"""Run real C++ protocol outputs through unchanged Laravel validators, offline.

python firmware/tests/test_contract.py --runner PATH_TO_native_runner.exe
No database, network clients, boards or relays are used by this test process.
"""
import argparse
import copy
import hashlib
import json
import math
import queue
from pathlib import Path
import re
import subprocess
import sys
import threading
import unittest

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "contracts/firmware"
NOW = 1795593600  # 2026-11-25T08:00:00Z, same deterministic fixture clock.
ARGS = None


class JsonProcess:
    def __init__(self, command):
        self.process = subprocess.Popen(command, cwd=ROOT, stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                        text=True, encoding="utf-8")
        self.responses = queue.Queue()
        def read_responses():
            for response in self.process.stdout:
                self.responses.put(response)
            self.responses.put(None)
        self.reader = threading.Thread(target=read_responses, daemon=True)
        self.reader.start()
        self.errors = []
        def read_errors():
            self.errors.extend(self.process.stderr)
        self.error_reader = threading.Thread(target=read_errors, daemon=True)
        self.error_reader.start()

    def call(self, value):
        self.process.stdin.write(json.dumps(value, allow_nan=False) + "\n")
        self.process.stdin.flush()
        try:
            response = self.responses.get(timeout=30)
        except queue.Empty as error:
            self.process.kill()
            self.process.wait(timeout=5)
            self.error_reader.join(timeout=5)
            raise RuntimeError("Test bridge timed out: " + str(self.process.args) + "\n" + "".join(self.errors)) from error
        if not response:
            self.error_reader.join(timeout=5)
            raise RuntimeError("".join(self.errors))
        return json.loads(response)

    def close(self):
        try:
            self.process.stdin.close()
        except OSError:
            pass
        self.process.wait(timeout=10)
        self.reader.join(timeout=5)
        self.error_reader.join(timeout=5)
        errors = "".join(self.errors)
        self.process.stdout.close()
        self.process.stderr.close()
        if self.process.returncode:
            raise RuntimeError(errors)


class FirmwareContract(unittest.TestCase):
    def test_mqtt_metadata_fragmented_publish_and_one_shot_consumption(self):
        result = self.call('metadata', chunks=[[0x31], [4], [0, 1], [97, 120], []])['results']
        self.assertEqual([r['known'] for r in result], [False, False, False, True, False])
        self.assertTrue(result[3]['retained'])
        self.assertFalse(result[3]['allowed'])  # retained display rejected

    def test_mqtt_metadata_retained_configuration_needs_explicit_authorization(self):
        for configuration, authorized, expected in [(False, True, False), (True, False, False), (True, True, True)]:
            r = self.call('metadata', chunks=[[0x33, 6, 0, 1, 97, 0, 1, 120]],
                          configuration=configuration, authorized=authorized)['results'][0]
            self.assertTrue(r['known'])
            self.assertEqual(r['allowed'], expected)
        r = self.call('metadata', chunks=[[0x32, 6, 0, 1, 97, 0, 1, 120]])['results'][0]
        self.assertTrue(r['allowed'])  # QoS1 non-retained display

    def test_mqtt_metadata_multibyte_length_and_nonpublish_packet(self):
        r = self.call('metadata', chunks=[[0x30, 0x80], [1] + [0] * 127, [0], [0xD0, 0]])['results']
        self.assertEqual([v['known'] for v in r], [False, False, True, False])
        self.assertTrue(r[2]['allowed'])

    def test_mqtt_metadata_invalid_length_latches_and_incomplete_is_unknown(self):
        r = self.call('metadata', chunks=[[0x31, 0x80, 0x80, 0x80, 0x80], [0x30, 1, 0]])['results']
        self.assertEqual([v['known'] for v in r], [False, False])
        self.assertFalse(self.call('metadata', chunks=[[0x31, 3, 0]])['results'][0]['known'])

    @classmethod
    def setUpClass(cls):
        cls.cpp = JsonProcess([ARGS.runner])
        cls.php = JsonProcess([ARGS.php, "-d", "output_buffering=0", "-d", "implicit_flush=1",
                              "firmware/tests/backend_validate.php"])

    @classmethod
    def tearDownClass(cls):
        cls.cpp.close()
        cls.php.close()

    def call(self, mode, **kwargs):
        return self.cpp.call(dict(mode=mode, **kwargs))

    def fixture(self, name):
        return json.loads((CONTRACT / "fixtures" / (name + ".json")).read_text())

    def validate(self, schema, result):
        self.assertTrue(result["ok"], result)
        accepted = self.php.call(dict(schema=schema, payload=result["payload"], wire=result.get("wire")))
        self.assertTrue(accepted["ok"], accepted)

    def signed(self, payload):
        return self.php.call(dict(mode="canonical", payload=payload))["wire"]

    def config(self, steps=None, **kwargs):
        if steps is None:
            steps = [self.signed(self.fixture("config-operational"))]
        return self.call("config", steps=steps, **kwargs)

    def control(self, **kwargs):
        args = dict(average=25, min=20, max=30, gas=1000, threshold=1500, verified=True,
                    lux=50, min_lux=10, max_lux=100, previous=None, lighting_mode="auto")
        args.update(kwargs)
        return self.call("control", **args), args

    def test_locked_schema_and_fixture_checksums(self):
        lock = json.loads((CONTRACT / "contract-lock.json").read_text())
        for name, expected in lock["schemas"].items():
            with self.subTest(schema=name):
                self.assertEqual(hashlib.sha256((CONTRACT / "schemas" / name).read_bytes()).hexdigest(), expected)
        for entry in lock["fixtures"]:
            with self.subTest(fixture=entry["file"]):
                raw = (CONTRACT / entry["file"]).read_bytes()
                self.assertEqual(hashlib.sha256(raw).hexdigest(), entry["sha256"])
                self.assertTrue(self.php.call(dict(schema=entry["schema"], payload=json.loads(raw)))["ok"])

    def test_complete_indoor_real_serialization(self):
        r = self.call("telemetry", values=[28] * 5 + [65] * 5 + [1000])
        self.validate("telemetry", r)
        self.assertEqual(r["payload"]["readings"], self.fixture("telemetry-indoor")["readings"])
        self.assertEqual(set(r["payload"]), {"message_id", "sent_at", "readings"})
        self.assertLess(r["bytes"] + 224 + 8, 4096 + 224 + 8)

    def test_machine_readable_batch_vectors(self):
        vectors = json.loads((ROOT / "firmware/tests/fixtures/sensor_batches.json").read_text())
        for vector in vectors["cases"]:
            r = self.call("telemetry", board=vector["board"], values=vector["values"])
            if vector["expected_count"]:
                self.validate("telemetry", r)
                self.assertEqual(len(r["payload"]["readings"]), vector["expected_count"])
            else:
                self.assertFalse(r["ok"])

    def test_complete_outdoor_real_serialization(self):
        r = self.call("telemetry", board="outdoor", values=[28, 65, 120])
        self.validate("telemetry", r)
        self.assertEqual(r["payload"]["readings"], self.fixture("telemetry-outdoor")["readings"])

    def test_partial_humidity_does_not_change_temperature(self):
        r = self.call("telemetry", values=[28] * 5 + [65, None, 66, 67, 68, 1000])
        self.validate("telemetry", r)
        ids = [x["sensor_identifier"] for x in r["payload"]["readings"]]
        self.assertEqual(len(ids), 10)
        self.assertNotIn("indoor_dht22_2_humidity", ids)
        self.assertIn("indoor_dht22_2", ids)

    def test_missing_bh1750_is_omitted(self):
        r = self.call("telemetry", board="outdoor", values=[27, 60, None])
        self.validate("telemetry", r)
        self.assertEqual(len(r["payload"]["readings"]), 2)

    def test_zero_lux_is_a_valid_measurement(self):
        r = self.call("telemetry", board="outdoor", values=[None, None, 0])
        self.validate("telemetry", r)
        self.assertEqual(r["payload"]["readings"][0]["value"], 0)

    def test_invalid_values_are_not_substituted(self):
        r = self.call("telemetry", values=[-41, 81, None, "NaN", True, -1, 101, "Infinity", None, 100, 4096])
        self.validate("telemetry", r)
        self.assertEqual(r["payload"]["readings"], [{"sensor_identifier": "indoor_dht22_5_humidity", "value": 100}])

    def test_empty_batch_suppressed(self):
        self.assertFalse(self.call("telemetry", values=[None] * 11)["ok"])

    def test_buffer_overflow_suppressed(self):
        self.assertFalse(self.call("telemetry", values=[28] * 11, capacity=100)["ok"])

    def test_valid_limits_and_maximal_payload(self):
        r = self.call("telemetry", values=[-40, 80, 28.123456, 28.123457, 28.123458, 0, 100, 65.123456, 65.123457, 65.123458, 4095])
        self.validate("telemetry", r)
        self.assertLess(r["bytes"], 1536)

    def test_status_both_states(self):
        for online in [False, True]:
            with self.subTest(online=online):
                r = self.call("status", online=online)
                self.validate("status", r)
                self.assertEqual(r["payload"]["status"], "online" if online else "offline")

    def test_no_time_no_telemetry(self):
        for now in [0, -1, 2147483648]:
            self.assertFalse(self.call("telemetry", values=[28] * 11, now=now)["ok"])

    def test_topics_match_locked_manifest(self):
        lock = json.loads((CONTRACT / "contract-lock.json").read_text())
        for name, spec in lock["topics"].items():
            if name == "session":
                continue  # No fake authenticated session sender is implemented.
            suffix = spec["suffix"].lstrip("/")
            r = self.call("topic", coop=42, device="Registered-Outdoor_2", suffix=suffix)
            self.assertTrue(r["ok"])
            self.assertEqual(r["topic"], "smart-kandang/coops/42/devices/Registered-Outdoor_2/" + suffix)

    def test_topic_scope_and_commands_rejected(self):
        for kwargs in [dict(coop=0), dict(device="a/other"), dict(device="+"), dict(device="x" * 101),
                       dict(suffix="integration/v1/command"), dict(suffix="integration/v1/session"), dict(capacity=10)]:
            self.assertFalse(self.call("topic", **kwargs)["ok"])

    def test_timestamp_exact_calendar_and_fractions(self):
        r = self.call("time", value="2026-11-25T08:00:00.123456Z", seconds=NOW)
        self.assertTrue(r["ok"])
        self.assertEqual(r["microseconds"], NOW * 1000000 + 123456)
        self.assertEqual(r["formatted"], "2026-11-25T08:00:00Z")

    def test_invalid_timestamp_rejected(self):
        for value in ["2026-02-29T00:00:00Z", "2026-11-25T08:00:60Z", "2026-11-25T08:00:00+00:00",
                      "2026-11-25T08:00:00.1234567Z", "2026-13-01T00:00:00Z", "2038-01-19T03:14:08Z"]:
            self.assertFalse(self.call("time", value=value)["ok"], value)

    def test_message_identity_reboot_and_sequence(self):
        first, second = self.call("ids", seed=1), self.call("ids", seed=2)
        self.assertEqual(len(set(first["ids"] + second["ids"])), 2000)
        self.assertRegex(first["boot"], r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-)4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$")

    def test_sha256_known_vectors(self):
        for value in ["", "abc", "a" * 1000]:
            self.assertEqual(self.call("sha", value=value)["hash"], hashlib.sha256(value.encode()).hexdigest())

    def test_operational_fixture_ack_schema(self):
        r = self.config()
        self.assertEqual(r["accepted"], [True])
        self.assertEqual(r["writes"], 1)
        self.validate("ack", r)
        self.assertEqual(r["payload"]["status"], "acknowledged")

    def test_lighting_fixture_independent_version(self):
        raw = self.signed(self.fixture("config-lighting-lux"))
        self.assertEqual(self.config([raw], light=True)["accepted"], [True])
        self.assertEqual(self.config([raw])["accepted"], [False])

    def test_null_threshold_remains_unconfigured(self):
        for name in ["config-operational", "config-lighting-lux"]:
            p = self.fixture(name)
            if name == "config-operational":
                p["settings"]["minimum_indoor_temperature"] = None
            else:
                p["settings"]["min_lux"] = p["settings"]["max_lux"] = None
            self.assertEqual(self.config([self.signed(p)], light=name.endswith("lux"))["accepted"], [True])

    def test_duplicate_reack_without_flash_wear(self):
        raw = self.signed(self.fixture("config-operational"))
        r = self.config([raw, raw])
        self.assertEqual(r["accepted"], [True, True])
        self.assertEqual(r["writes"], 1)

    def test_version_replay_and_collision(self):
        old = self.fixture("config-operational")
        new = copy.deepcopy(old); new["config_version"] = 2
        collision = copy.deepcopy(new); collision["settings"]["gas_threshold"] = 1600
        r = self.config([self.signed(new), self.signed(old), self.signed(collision)])
        self.assertEqual(r["accepted"], [True, False, False])
        self.assertEqual(r["version"], 2)

    def test_version_integer_preserved_above_double_precision(self):
        p = self.fixture("config-operational"); p["config_version"] = 9007199254740993
        r = self.config([self.signed(p)])
        self.assertEqual(r["version"], p["config_version"])
        self.validate("ack", r)

    def test_config_scope_types_units_extra_fields(self):
        p = self.fixture("config-operational")
        variants = []
        for key, value in [("coop_id", 2), ("device_identifier", "OTHER"), ("schema_version", True), ("config_version", "1"), ("command", "ON")]:
            item = copy.deepcopy(p); item[key] = value; variants.append(item)
        for key, value in [("gas_unit", "ppm"), ("gas_threshold", True), ("maximum_indoor_temperature", "30"), ("minimum_indoor_temperature", 31)]:
            item = copy.deepcopy(p); item["settings"][key] = value; variants.append(item)
        for item in variants:
            self.assertEqual(self.config([self.signed(item)])["accepted"], [False], item)

    def test_config_hash_tamper_and_duplicate_keys(self):
        raw = self.signed(self.fixture("config-operational"))
        self.assertEqual(self.config([raw.replace('1500', '1501')])["accepted"], [False])
        self.assertEqual(self.config([raw.replace('"coop_id":1', '"coop_id":1,"coop_id":1')])["accepted"], [False])

    def test_config_php_float_and_microsecond_encoding(self):
        for value in [20.1, 20.000000000001, 0.00001, -0.0]:
            p = self.fixture("config-operational")
            p["settings"]["minimum_indoor_temperature"] = value
            p["generated_at"] = "2026-11-25T07:59:59.123456Z"
            self.assertEqual(self.config([self.signed(p)])["accepted"], [True], value)

    def test_config_reordered_whitespace_and_nested_duplicates(self):
        raw = self.signed(self.fixture("config-operational"))
        payload = dict(reversed(list(json.loads(raw).items())))
        payload["settings"] = dict(reversed(list(payload["settings"].items())))
        pretty = json.dumps(payload, indent=1)
        self.assertEqual(self.config([pretty])["accepted"], [True])
        duplicated = raw.replace('"gas_unit":"adc"', '"gas_unit":"adc","gas_unit":"adc"')
        self.assertNotEqual(duplicated, raw)
        self.assertEqual(self.config([duplicated])["accepted"], [False])

    def test_expired_and_future_config(self):
        raw = self.signed(self.fixture("config-operational"))
        self.assertEqual(self.config([raw], now_us=(NOW + 3600) * 1000000)["accepted"], [False])
        self.assertEqual(self.config([raw], now_us=NOW * 1000000 - 1)["accepted"], [False])

    def test_nvs_write_failure_never_acknowledges(self):
        r = self.config(fail_write=True)
        self.assertEqual(r["accepted"], [False])
        self.assertFalse(r["ok"])
        self.assertFalse(r["healthy"])

    def test_nvs_readback_corruption_fails_closed(self):
        r = self.config(corrupt_write=True)
        self.assertEqual(r["accepted"], [False])
        self.assertFalse(r["ok"])

    def test_nvs_recovery_preserves_replay_watermark(self):
        p = self.fixture("config-operational"); p["config_version"] = 4
        r = self.config(stored=self.signed(p))
        self.assertTrue(r["recovered"])
        self.assertEqual(r["accepted"], [False])
        self.assertEqual(r["version"], 4)

    def test_corrupt_nvs_does_not_reset_version(self):
        r = self.config(stored="corrupt")
        self.assertFalse(r["recovered"])
        self.assertEqual(r["accepted"], [False])
        self.assertEqual(r["writes"], 0)

    def test_unknown_report_not_gpio_or_physical_claim(self):
        r = self.call("report")
        self.validate("report", r)
        self.assertTrue(all(v is None for v in r["payload"]["states"].values()))
        self.assertEqual(r["payload"]["feedback_kind"], "gpio")
        self.assertFalse(self.call("report", sequence=0)["ok"])

    def test_display_fixture_unknown_not_zero(self):
        r = self.call("display", raw=json.dumps(self.fixture("display")))
        self.assertTrue(r["ok"])
        self.assertEqual(r["values"], [None] * 6)
        self.assertEqual(r["line"], "Lux: --")

    def test_display_target_expiry_scope(self):
        raw = json.dumps(self.fixture("display"))
        for kwargs in [dict(registry=3), dict(coop=2), dict(now_us=(NOW + 30) * 1000000), dict(now_us=(NOW - 1) * 1000000)]:
            self.assertFalse(self.call("display", raw=raw, **kwargs)["ok"])

    def test_display_stale_measurement_is_unavailable(self):
        p = self.fixture("display")
        p["fields"]["outdoor_light_lux"].update(value=120, status="stale", recorded_at="2026-11-25T07:00:00Z")
        r = self.call("display", raw=json.dumps(p))
        self.assertTrue(r["ok"])
        self.assertIsNone(r["values"][5])
        p["fields"]["outdoor_light_lux"].update(value=0, status="fresh", recorded_at="2026-11-25T08:00:00Z")
        self.assertEqual(self.call("display", raw=json.dumps(p))["values"][5], 0)

    def test_display_invalid_unit_and_actuator_types(self):
        p = self.fixture("display"); p["fields"]["outdoor_humidity"]["unit"] = "celsius"
        self.assertFalse(self.call("display", raw=json.dumps(p))["ok"])
        p = self.fixture("display"); p["publication_enabled"] = True
        self.assertFalse(self.call("display", raw=json.dumps(p))["ok"])

    def test_control_matches_actual_php_engines(self):
        for kwargs in [dict(average=15), dict(average=20), dict(average=30), dict(average=35), dict(gas=2000),
                       dict(average=None, gas=2000), dict(verified=False), dict(lux=9), dict(lux=101),
                       dict(lux=10, previous=True), dict(lux=100, previous=False), dict(lighting_mode="manual")]:
            with self.subTest(kwargs=kwargs):
                result, params = self.control(**kwargs)
                expected = self.php.call(dict(mode="control", payload=params))
                for key in ["heater", "fans", "light"]:
                    self.assertEqual(result[key], expected[key])
                self.assertFalse(result["physical_outputs"])

    def test_expired_config_and_stale_sensors_no_demands(self):
        for kwargs in [dict(config_valid=False), dict(fresh=False)]:
            r, _ = self.control(gas=2000, **kwargs)
            self.assertIsNone(r["heater"]); self.assertIsNone(r["fans"]); self.assertIsNone(r["light"])

    def test_sensor_drivers_real_decode_and_conversion(self):
        r = self.call("sensors")
        self.assertAlmostEqual(r["temperature"], 28, places=3)
        self.assertAlmostEqual(r["humidity"], 65, places=3)
        self.assertEqual(r["lux"], 100)
        self.assertFalse(r["bh_early"])
        self.assertEqual(r["commands"], [0xba, 0xbe, 0xac, 0x01, 0x07, 0x20])

    def test_sensor_absence_busy_crc_and_recovery(self):
        for flag in ["absent", "busy", "crc"]:
            r = self.call("sensors", **{flag: True, "recover": True})
            self.assertIsNone(r["temperature"], flag)
            self.assertIsNone(r["humidity"], flag)
            self.assertTrue(r["recovered"], flag)
            self.assertLess(r["reads"], 12)

    def test_reconnect_backoff_rollover_and_retry_identity(self):
        r = self.call("reconnect")
        self.assertFalse(r["early"]); self.assertTrue(r["due"])
        self.assertEqual(r["cap"], 30000); self.assertTrue(r["reset"])
        self.assertFalse(r["retry_early"]); self.assertTrue(r["retry_due"])
        self.assertTrue(r["exhausted"]); self.assertTrue(r["expired"]); self.assertTrue(r["puback_cleared"])
        self.assertEqual(r["payload"], "immutable")
        for key in ["wifi_start", "wifi_wait", "wifi_stop", "wifi_backoff", "wifi_retry", "wifi_connected", "wifi_reconnect"]:
            self.assertTrue(r[key], key)

    def test_no_physical_output_driver_or_command_subscription(self):
        paths = list((ROOT / "firmware").rglob("*.ino")) + list((ROOT / "firmware/shared").rglob("*.h"))
        for path in paths:
            text = path.read_text()
            self.assertNotRegex(text, r"\b(digitalWrite|analogWrite|ledcWrite|pinMode)\s*\(", str(path))
        defaults = (ROOT / "firmware/shared/PoultriqProtocol/src/PoultriqDefaults.h").read_text()
        self.assertIn("#define FW_NETWORK_ENABLED 0", defaults)
        self.assertIn("#define FW_DRAFT_INTEGRATION 0", defaults)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--runner", required=True)
    parser.add_argument("--php", default="php")
    ARGS, rest = parser.parse_known_args()
    unittest.main(argv=[sys.argv[0]] + rest, verbosity=2)
