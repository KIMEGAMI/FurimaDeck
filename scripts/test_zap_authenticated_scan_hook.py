"""Fail-closed tests for the isolated authenticated ZAP scan hook."""

from contextlib import redirect_stdout
from importlib.util import module_from_spec, spec_from_file_location
import io
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


HOOK_PATH = Path(__file__).with_name("zap-authenticated-scan-hook.py")
SPEC = spec_from_file_location("zap_authenticated_scan_hook", HOOK_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the authenticated ZAP hook for testing.")
HOOK = module_from_spec(SPEC)
SPEC.loader.exec_module(HOOK)


class FakeSpider:
    def __init__(self, apply_changes=True):
        self.value = False
        self.apply_changes = apply_changes

    def set_option_logout_avoidance(self, enabled):
        if self.apply_changes:
            self.value = enabled

    @property
    def option_logout_avoidance(self):
        return str(self.value).lower()


class FakeContext:
    def __init__(self, *, export=True, apply_scope=True, include_readback=True):
        self.export = export
        self.apply_scope = apply_scope
        self.include_readback = include_readback
        self.created = False
        self.removed = False
        self.include_pattern = None
        self.in_scope = "false"

    def new_context(self, name):
        self.created = True
        self.name = name
        return {"contextId": "3"}

    def include_in_context(self, name, pattern):
        self.include_pattern = pattern
        return "OK"

    def set_context_in_scope(self, name, enabled):
        if self.apply_scope:
            self.in_scope = enabled
        return "OK"

    def context(self, name):
        return {"inScope": self.in_scope}

    def include_regexs(self, name):
        if not self.include_readback or self.include_pattern is None:
            return []
        return [self.include_pattern]

    def export_context(self, name, path):
        if self.export:
            Path(path).write_text("<context />", encoding="utf-8")
        return "OK"

    def remove_context(self, name):
        self.removed = True
        return "OK"


class FakePassiveScan:
    def __init__(self, records_to_scan="0"):
        self.records_to_scan = records_to_scan


class FakeZap:
    def __init__(self, *, spider=None, context=None, passive_scan=None):
        self.spider = spider or FakeSpider()
        self.context = context or FakeContext()
        self.pscan = passive_scan or FakePassiveScan()


class AuthenticatedZapHookTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(
            prefix=".furimadeck-zap-hook-", dir=Path(__file__).parent
        )
        self.directory = Path(self.temp.name)
        self.context_file = self.directory / "context.xml"
        self.environment = patch.dict(
            os.environ,
            {
                HOOK.AUTH_CONTEXT_FILE_ENV: str(self.context_file),
                "APP_URL": "https://127.0.0.1:8443",
                "ZAP_AUTH_HEADER": "Cookie",
                "ZAP_AUTH_HEADER_VALUE": "app_session=synthetic-cookie",
                "ZAP_AUTH_HEADER_SITE": "127.0.0.1:8443",
            },
        )
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.addCleanup(self.temp.cleanup)
        HOOK._ZAP = None
        HOOK._TARGET_ORIGIN = None

    def _run_full_setup(self, zap):
        HOOK.zap_started(zap, "https://127.0.0.1:8443/furimadeck-dashboard")
        self.assertTrue(zap.context.removed)
        self.assertEqual(zap.context.include_pattern, r"https://127\.0\.0\.1:8443/.*")
        self.assertEqual(zap.context.in_scope, "true")
        self.assertTrue(self.context_file.is_file())
        HOOK.zap_import_context_wrap("3")

    def test_exports_and_imports_only_the_loopback_scope(self):
        zap = FakeZap()
        output = io.StringIO()

        with redirect_stdout(output):
            self._run_full_setup(zap)

        self.assertFalse(self.context_file.exists())
        self.assertIn("preflight session cookie supplies authentication", output.getvalue())
        self.assertNotIn("cookie-value", output.getvalue())
        self.assertFalse(hasattr(zap, "users"))
        self.assertFalse(hasattr(zap, "authentication"))

    def test_rejects_non_loopback_or_non_https_targets_before_creating_context(self):
        for target in (
            "https://example.com",
            "http://127.0.0.1:8443",
            "https://user@127.0.0.1:8443",
            "https://127.0.0.1:8443/?target=external",
        ):
            with self.subTest(target=target):
                zap = FakeZap()
                with self.assertRaisesRegex(RuntimeError, "HTTPS loopback"):
                    HOOK.zap_started(zap, target)
                self.assertFalse(zap.context.created)

    def test_fails_closed_if_logout_avoidance_cannot_be_verified(self):
        zap = FakeZap(spider=FakeSpider(apply_changes=False))

        with self.assertRaisesRegex(RuntimeError, "logout-avoidance"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")

        self.assertFalse(zap.context.created)

    def test_fails_closed_if_scope_or_scope_readback_is_wrong(self):
        zap = FakeZap(context=FakeContext(apply_scope=False))
        with self.assertRaisesRegex(RuntimeError, "read-back verification"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")
        self.assertTrue(zap.context.removed)

        zap = FakeZap(context=FakeContext(include_readback=False))
        with self.assertRaisesRegex(RuntimeError, "scope failed read-back"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")
        self.assertTrue(zap.context.removed)

    def test_fails_closed_and_removes_context_if_export_is_missing(self):
        zap = FakeZap(context=FakeContext(export=False))

        with self.assertRaisesRegex(RuntimeError, "did not create"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")

        self.assertTrue(zap.context.removed)

    def test_rejects_context_for_a_different_origin_and_removes_it(self):
        zap = FakeZap()
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        os.environ["APP_URL"] = "https://localhost:9443"

        with self.assertRaisesRegex(RuntimeError, "does not match the authenticated scan target"):
            HOOK.zap_import_context_wrap("3")

        self.assertFalse(self.context_file.exists())

    def test_rejects_missing_or_mis_scoped_authentication_header(self):
        for variable, value in (
            ("ZAP_AUTH_HEADER_VALUE", ""),
            ("ZAP_AUTH_HEADER_SITE", "localhost:8443"),
        ):
            with self.subTest(variable=variable):
                zap = FakeZap()
                HOOK.zap_started(zap, "https://127.0.0.1:8443")
                os.environ[variable] = value
                with self.assertRaisesRegex(RuntimeError, "header configuration is missing or unsafe"):
                    HOOK.zap_import_context_wrap("3")
                self.assertFalse(self.context_file.exists())
                os.environ[variable] = (
                    "app_session=synthetic-cookie"
                    if variable == "ZAP_AUTH_HEADER_VALUE"
                    else "127.0.0.1:8443"
                )

    def test_rejects_invalid_imported_context_identifier(self):
        zap = FakeZap()
        HOOK.zap_started(zap, "https://127.0.0.1:8443")

        with self.assertRaisesRegex(RuntimeError, "invalid scan context identifier"):
            HOOK.zap_import_context_wrap("bad")

        self.assertFalse(self.context_file.exists())

    def test_refuses_to_report_until_passive_scan_is_complete(self):
        zap = FakeZap(passive_scan=FakePassiveScan("1"))

        with self.assertRaisesRegex(RuntimeError, "passive scan is incomplete"):
            HOOK.zap_get_alerts(zap, "https://127.0.0.1:8443", [], {})

        zap.pscan.records_to_scan = "0"
        self.assertEqual(
            HOOK.zap_get_alerts(zap, "https://127.0.0.1:8443", [], {}),
            (zap, "https://127.0.0.1:8443", [], {}),
        )


if __name__ == "__main__":
    unittest.main()
