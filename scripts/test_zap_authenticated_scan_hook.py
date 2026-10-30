"""Unit tests for the ZAP authenticated-scan hook without external dependencies."""

from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path
import unittest


HOOK_PATH = Path(__file__).with_name("zap-authenticated-scan-hook.py")
SPEC = spec_from_file_location("zap_authenticated_scan_hook", HOOK_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the authenticated ZAP hook for testing.")
HOOK = module_from_spec(SPEC)
SPEC.loader.exec_module(HOOK)


class FakeSpider:
    def __init__(self, initial_value=False, apply_changes=True):
        self.value = initial_value
        self.apply_changes = apply_changes

    def set_option_logout_avoidance(self, enabled):
        if self.apply_changes:
            self.value = enabled

    @property
    def option_logout_avoidance(self):
        return str(self.value).lower()


class FakeZap:
    def __init__(self, spider):
        self.spider = spider


class AuthenticatedZapHookTest(unittest.TestCase):
    def test_enables_and_reads_back_logout_avoidance(self):
        spider = FakeSpider()

        HOOK.zap_started(FakeZap(spider), "https://example.invalid")

        self.assertTrue(spider.value)

    def test_fails_closed_when_zap_does_not_apply_the_setting(self):
        spider = FakeSpider(initial_value=False, apply_changes=False)

        with self.assertRaisesRegex(RuntimeError, "could not be enabled and verified"):
            HOOK.zap_started(FakeZap(spider), "https://example.invalid")

    def test_propagates_zap_api_errors(self):
        class UnavailableSpider:
            def set_option_logout_avoidance(self, enabled):
                raise RuntimeError("API unavailable")

            @property
            def option_logout_avoidance(self):
                raise AssertionError("The getter must not run after setter failure.")

        with self.assertRaisesRegex(RuntimeError, "API unavailable"):
            HOOK.zap_started(FakeZap(UnavailableSpider()), "https://example.invalid")


if __name__ == "__main__":
    unittest.main()
