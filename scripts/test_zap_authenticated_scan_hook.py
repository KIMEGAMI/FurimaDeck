"""Fail-closed tests for the disposable authenticated ZAP scan hook."""

from contextlib import redirect_stdout
from importlib.util import module_from_spec, spec_from_file_location
import io
import os
from pathlib import Path
import re
import shlex
import tempfile
import unittest
from unittest.mock import patch
from urllib.parse import unquote


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
    def __init__(
        self,
        *,
        export=True,
        apply_scope=True,
        apply_strategy=True,
        apply_poll_readback=True,
        include_readback=True,
    ):
        self.export = export
        self.apply_scope = apply_scope
        self.apply_strategy = apply_strategy
        self.apply_poll_readback = apply_poll_readback
        self.include_readback = include_readback
        self.created = False
        self.removed = False
        self.include_pattern = None
        self.in_scope = "false"
        self.strategy = "EACH_REQ"
        self.poll_url = ""
        self.poll_frequency = ""
        self.poll_frequency_units = ""
        self.auth_type = ""
        self.logged_in = ""
        self.logged_out = ""

    def new_context(self, name):
        self.created = True
        self.name = name
        return "3"

    def include_in_context(self, name, pattern):
        self.include_pattern = pattern
        return "OK"

    def set_context_in_scope(self, name, enabled):
        if self.apply_scope:
            self.in_scope = enabled
        return "OK"

    def export_context(self, name, path):
        if self.export:
            Path(path).write_text(
                '<context><users><user id="0" name="FurimaDeck CI scanner" /></users></context>',
                encoding="utf-8",
            )
        return "OK"

    def remove_context(self, name):
        self.removed = True
        return "OK"

    def set_context_checking_strategy(
        self,
        name,
        strategy,
        *,
        pollurl=None,
        pollfrequency=None,
        pollfrequencyunits=None,
    ):
        if self.apply_strategy:
            self.strategy = strategy
            self.poll_url = pollurl or ""
            self.poll_frequency = str(pollfrequency or "")
            self.poll_frequency_units = pollfrequencyunits or ""
        return "OK"

    def include_regexs(self, name):
        if not self.include_readback or self.include_pattern is None:
            return []
        return [self.include_pattern]

    def context(self, name):
        return {
            "inScope": self.in_scope,
            "checkingStrategy": self.strategy,
            "pollUrl": self.poll_url if self.apply_poll_readback else "",
            "pollFrequency": self.poll_frequency if self.apply_poll_readback else "",
            "pollFrequencyUnits": self.poll_frequency_units if self.apply_poll_readback else "",
            "authType": self.auth_type,
            "loggedInPattern": self.logged_in,
            "loggedOutPattern": self.logged_out,
        }


class FakeUsers:
    def __init__(self, *, imported=True, auth_result=None):
        self.imported = imported
        self.auth_result = auth_result or {
            "id": "41",
            "type": "-1",
            "requestHeader": "POST /login HTTP/1.1",
            "requestBody": "email=test&password=redacted",
            "responseHeader": "HTTP/1.1 302 Found\\r\\nLocation: /furimadeck-dashboard",
            "responseBody": "",
            "tags": [],
            "authSuccessful": "true",
        }
        self.enabled = False
        self.credentials = None
        self.authenticated = False

    def new_user(self, context_id, name):
        return "0"

    def users_list(self, context_id):
        if not self.imported:
            return []
        return [{"userId": "0", "name": HOOK.USER_NAME, "enabled": str(self.enabled).lower()}]

    def set_authentication_credentials(self, context_id, user_id, credentials):
        self.credentials = credentials
        return "OK"

    def set_user_enabled(self, context_id, user_id, enabled):
        self.enabled = enabled == "true"
        return "OK"

    def authenticate_as_user(self, context_id, user_id):
        self.authenticated = True
        return self.auth_result


class FakeSessionManagement:
    def __init__(self, actual="cookieBasedSessionManagement"):
        self.method = actual

    def set_session_management_method(self, context_id, method, parameters):
        self.method = method
        return "OK"

    def get_session_management_method(self, context_id):
        return self.method


class FakeAuthentication:
    def __init__(
        self,
        *,
        method="",
        readback_login_data=None,
        reject_set=False,
    ):
        self.method = method
        self.login_url = ""
        self.login_data = ""
        self.readback_login_data = readback_login_data
        self.logged_in = ""
        self.logged_out = ""
        self.reject_set = reject_set

    def set_authentication_method(self, context_id, method, parameters):
        if self.reject_set:
            return "ERROR"
        self.method = method
        fields = dict(part.split("=", 1) for part in parameters.split("&"))
        self.login_url = unquote(fields["loginUrl"])
        self.login_data = unquote(fields["loginRequestData"])
        if hasattr(self, "context"):
            self.context.auth_type = method
        return "OK"

    def get_authentication_method(self, context_id):
        return {
            "methodName": self.method,
            "loginUrl": self.login_url,
            "loginRequestData": (
                self.login_data
                if self.readback_login_data is None
                else self.readback_login_data
            ),
        }

    def set_logged_in_indicator(self, context_id, indicator):
        self.logged_in = indicator
        if hasattr(self, "context"):
            self.context.logged_in = indicator
        return "OK"

    def get_logged_in_indicator(self, context_id):
        return self.logged_in

    def set_logged_out_indicator(self, context_id, indicator):
        self.logged_out = indicator
        if hasattr(self, "context"):
            self.context.logged_out = indicator
        return "OK"

    def get_logged_out_indicator(self, context_id):
        return self.logged_out


class FakeCsrf:
    def __init__(self, tokens=None, *, apply_changes=True):
        self.tokens = list(tokens or [])
        self.apply_changes = apply_changes

    @property
    def option_tokens_names(self):
        return list(self.tokens)

    def add_option_token(self, token):
        if self.apply_changes:
            self.tokens.append(token)
        return "OK"


class FakePassiveScan:
    def __init__(self, records_to_scan="0"):
        self.records_to_scan = records_to_scan


class FakeZap:
    def __init__(
        self,
        *,
        spider=None,
        context=None,
        users=None,
        session=None,
        auth=None,
        csrf=None,
        passive_scan=None,
    ):
        self.spider = spider or FakeSpider()
        self.context = context or FakeContext()
        self.users = users or FakeUsers()
        self.sessionManagement = session or FakeSessionManagement()
        self.authentication = auth or FakeAuthentication()
        self.authentication.context = self.context
        self.acsrf = csrf or FakeCsrf()
        self.pscan = passive_scan or FakePassiveScan()


class AuthenticatedZapHookTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(
            prefix=".furimadeck-zap-hook-", dir=Path(__file__).parent
        )
        self.directory = Path(self.temp.name)
        self.context_file = self.directory / "context.xml"
        self.credentials_file = self.directory / "credentials.txt"
        self.credentials_file.write_text(
            "furimadeck-zap-12345.2@example.invalid\n" + "a" * 64 + "\n",
            encoding="utf-8",
        )
        self.environment = patch.dict(
            os.environ,
            {
                HOOK.AUTH_CONTEXT_FILE_ENV: str(self.context_file),
                HOOK.AUTH_CREDENTIALS_FILE_ENV: str(self.credentials_file),
                "APP_URL": "https://127.0.0.1:8443",
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

    def test_configures_real_client_return_shapes_and_executes_login_successfully(self):
        zap = FakeZap(csrf=FakeCsrf(["_token"]))
        output = io.StringIO()

        with redirect_stdout(output):
            self._run_full_setup(zap)

        self.assertEqual(zap.sessionManagement.method, "cookieBasedSessionManagement")
        self.assertEqual(zap.authentication.method, "formBasedAuthentication")
        self.assertEqual(zap.authentication.login_url, "https://127.0.0.1:8443/login")
        self.assertEqual(
            zap.authentication.login_data,
            "email={%username%}&password={%password%}",
        )
        self.assertEqual(
            zap.users.credentials,
            "username=furimadeck-zap-12345.2@example.invalid&password=" + "a" * 64,
        )
        self.assertTrue(zap.users.enabled)
        self.assertTrue(zap.users.authenticated)
        self.assertEqual(zap.context.strategy, "POLL_URL")
        self.assertEqual(zap.context.poll_url, "https://127.0.0.1:8443/furimadeck-dashboard")
        self.assertEqual(zap.context.poll_frequency, "1")
        self.assertEqual(zap.context.poll_frequency_units, "REQUESTS")
        self.assertEqual(zap.acsrf.option_tokens_names, ["_token"])
        self.assertFalse(self.context_file.exists())
        self.assertNotIn("example.invalid", output.getvalue())
        self.assertNotIn("a" * 64, output.getvalue())
        self.assertIn("login succeeded", output.getvalue())

        indicator = re.compile(zap.authentication.logged_in)
        self.assertRegex(
            "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1:8443/furimadeck-dashboard\r\n",
            indicator,
        )
        self.assertRegex(
            "HTTP/1.1 302 Found\r\nLocation: /furimadeck-dashboard\r\n",
            indicator,
        )
        self.assertNotRegex(
            "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1:8443/login\r\n",
            indicator,
        )
        self.assertNotRegex(
            "HTTP/1.1 302 Found\r\nX-Location: /furimadeck-dashboard\r\n",
            indicator,
        )
        self.assertNotRegex(
            "HTTP/1.1 200 OK\r\nLocation: /furimadeck-dashboard\r\n",
            indicator,
        )
        self.assertNotRegex(
            "HTTP/1.1 302 Found\r\n\r\nLocation: /furimadeck-dashboard\r\n",
            indicator,
        )
        self.assertNotRegex(
            "HTTP/1.1 302 Found\r\nLocation: /furimadeck-dashboard.evil\r\n",
            indicator,
        )
        self.assertNotRegex("FurimaDeck Dashboard", indicator)
        self.assertRegex(
            '<h1 class="sr-only">FurimaDeck Dashboard</h1>',
            indicator,
        )

        logged_out_indicator = re.compile(zap.authentication.logged_out)
        self.assertRegex(
            "HTTP/1.1 302 Found\r\nLocation: /login\r\n",
            logged_out_indicator,
        )
        self.assertRegex("HTTP/1.1 401 Unauthorized\r\n", logged_out_indicator)
        self.assertRegex("HTTP/2 403 Forbidden\r\n", logged_out_indicator)
        self.assertRegex("<title>ログイン | FurimaDeck</title>", logged_out_indicator)
        self.assertNotRegex(
            "HTTP/1.1 302 Found\r\nLocation: /furimadeck-dashboard\r\n",
            logged_out_indicator,
        )
        self.assertNotRegex(
            '<h1 class="sr-only">FurimaDeck Dashboard</h1>',
            logged_out_indicator,
        )

    def test_registers_csrf_token_and_fails_closed_if_zap_does_not_retain_it(self):
        zap = FakeZap(csrf=FakeCsrf())
        self._run_full_setup(zap)
        self.assertEqual(zap.acsrf.option_tokens_names, ["_token"])

        HOOK._ZAP = None
        context_file = self.directory / "context-missing-csrf.xml"
        context_file.write_text("<context />", encoding="utf-8")
        os.environ[HOOK.AUTH_CONTEXT_FILE_ENV] = str(context_file)
        zap = FakeZap(csrf=FakeCsrf(apply_changes=False))
        HOOK._ZAP = zap
        with self.assertRaisesRegex(RuntimeError, "CSRF token registration"):
            HOOK.zap_import_context_wrap("3")
        self.assertFalse(context_file.exists())

    def test_rejects_non_loopback_target_before_creating_context(self):
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

    def test_rejects_login_origin_that_differs_from_scanner_target(self):
        zap = FakeZap()
        HOOK.zap_started(zap, "https://127.0.0.1:8443/furimadeck-dashboard")
        os.environ["APP_URL"] = "https://localhost:9443"

        with self.assertRaisesRegex(RuntimeError, "does not match the authenticated scan target"):
            HOOK.zap_import_context_wrap("3")

        self.assertIsNone(zap.users.credentials)
        self.assertFalse(self.context_file.exists())

    def test_fails_closed_if_logout_avoidance_cannot_be_verified(self):
        zap = FakeZap(spider=FakeSpider(apply_changes=False))

        with self.assertRaisesRegex(RuntimeError, "logout-avoidance"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")

        self.assertFalse(zap.context.created)

    def test_fails_closed_if_context_scope_or_auth_check_strategy_readback_is_wrong(self):
        zap = FakeZap(context=FakeContext(apply_scope=False))
        with self.assertRaisesRegex(RuntimeError, "read-back verification"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")
        self.assertTrue(zap.context.removed)

        zap = FakeZap(context=FakeContext(apply_strategy=False))
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        with self.assertRaisesRegex(RuntimeError, "read-back verification"):
            HOOK.zap_import_context_wrap("3")
        self.assertFalse(self.context_file.exists())

        zap = FakeZap(context=FakeContext(apply_poll_readback=False))
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        with self.assertRaisesRegex(RuntimeError, "poll URL failed read-back"):
            HOOK.zap_import_context_wrap("3")
        self.assertFalse(self.context_file.exists())

    def test_fails_closed_if_context_scope_regex_readback_is_wrong(self):
        zap = FakeZap(context=FakeContext(include_readback=False))
        with self.assertRaisesRegex(RuntimeError, "scope failed read-back"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")
        self.assertTrue(zap.context.removed)

    def test_fails_closed_and_removes_context_if_export_is_missing(self):
        zap = FakeZap(context=FakeContext(export=False))

        with self.assertRaisesRegex(RuntimeError, "did not create"):
            HOOK.zap_started(zap, "https://127.0.0.1:8443")

        self.assertTrue(zap.context.removed)

    def test_rejects_existing_context_path_and_credentials_symlink(self):
        self.context_file.write_text("occupied", encoding="utf-8")
        with self.assertRaisesRegex(RuntimeError, "output path"):
            HOOK.zap_started(FakeZap(), "https://127.0.0.1:8443")
        self.context_file.unlink()

        zap = FakeZap()
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        original_is_symlink = Path.is_symlink

        def report_credentials_as_symlink(path):
            if path == self.credentials_file:
                return True
            return original_is_symlink(path)

        with patch.object(Path, "is_symlink", autospec=True, side_effect=report_credentials_as_symlink):
            with self.assertRaisesRegex(RuntimeError, "credentials file"):
                HOOK.zap_import_context_wrap("3")
        self.assertIsNone(zap.users.credentials)

    def test_rejects_non_disposable_credentials_before_setting_them(self):
        zap = FakeZap()
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        self.credentials_file.write_text(
            "real-person@example.com\n" + "b" * 64 + "\n", encoding="utf-8"
        )

        with self.assertRaisesRegex(RuntimeError, "disposable CI identity"):
            HOOK.zap_import_context_wrap("3")

        self.assertFalse(self.context_file.exists())
        self.assertIsNone(zap.users.credentials)

    def test_fails_closed_if_imported_context_has_no_expected_user(self):
        zap = FakeZap(users=FakeUsers(imported=False))
        HOOK.zap_started(zap, "https://127.0.0.1:8443")

        with self.assertRaisesRegex(RuntimeError, "expected disposable scan user"):
            HOOK.zap_import_context_wrap("3")

        self.assertFalse(self.context_file.exists())

    def test_fails_closed_when_zap_rejects_authentication_configuration(self):
        zap = FakeZap(auth=FakeAuthentication(reject_set=True))
        HOOK.zap_started(zap, "https://127.0.0.1:8443")

        with self.assertRaisesRegex(RuntimeError, "configure form-based authentication"):
            HOOK.zap_import_context_wrap("3")

        self.assertFalse(self.context_file.exists())

    def test_fails_closed_when_zap_changes_configured_login_request(self):
        zap = FakeZap(
            auth=FakeAuthentication(readback_login_data="email=wrong&password=wrong")
        )
        HOOK.zap_started(zap, "https://127.0.0.1:8443")

        with self.assertRaisesRegex(RuntimeError, "login request failed read-back"):
            HOOK.zap_import_context_wrap("3")

        self.assertFalse(self.context_file.exists())

    def test_fails_closed_when_zap_does_not_authenticate_and_suppresses_response_data(self):
        sensitive_response = {
            "authSuccessful": "false",
            "request": "password=secret-placeholder",
            "response": "private-response-placeholder",
        }
        zap = FakeZap(users=FakeUsers(auth_result=sensitive_response))
        HOOK.zap_started(zap, "https://127.0.0.1:8443")
        output = io.StringIO()

        with redirect_stdout(output):
            with self.assertRaisesRegex(RuntimeError, "could not authenticate") as raised:
                HOOK.zap_import_context_wrap("3")

        self.assertNotIn("secret-placeholder", str(raised.exception))
        self.assertNotIn("secret-placeholder", output.getvalue())
        self.assertNotIn("private-response-placeholder", output.getvalue())
        self.assertFalse(self.context_file.exists())

    def test_fails_closed_when_authentication_response_is_ambiguous(self):
        for response in (
            "not-confirmed",
            "true",
            {"authSuccessful": True},
            {"authSuccessful": "TRUE"},
            {"id": "41", "responseHeader": "HTTP/1.1 302 Found"},
        ):
            with self.subTest(response_type=type(response).__name__):
                zap = FakeZap(users=FakeUsers(auth_result=response))
                HOOK.zap_started(zap, "https://127.0.0.1:8443")

                with self.assertRaisesRegex(RuntimeError, "could not authenticate"):
                    HOOK.zap_import_context_wrap("3")

                self.assertFalse(self.context_file.exists())

    def test_api_failures_are_generic_and_context_file_is_removed(self):
        class RejectingAuthentication(FakeAuthentication):
            def set_authentication_method(self, context_id, method, parameters):
                return {"code": "FAIL", "message": "never surface this detail"}

        zap = FakeZap(auth=RejectingAuthentication())
        HOOK.zap_started(zap, "https://127.0.0.1:8443")

        with self.assertRaisesRegex(RuntimeError, "configure form-based authentication") as raised:
            HOOK.zap_import_context_wrap("3")

        self.assertNotIn("never surface this detail", str(raised.exception))
        self.assertFalse(self.context_file.exists())

    def test_refuses_to_accept_a_report_while_passive_scan_records_remain(self):
        zap = FakeZap(passive_scan=FakePassiveScan("3"))

        with self.assertRaisesRegex(RuntimeError, "passive scan is incomplete"):
            HOOK.zap_get_alerts(zap, "https://127.0.0.1:8443", [], {})

    def test_fails_closed_if_passive_scan_completion_cannot_be_read(self):
        zap = FakeZap(passive_scan=FakePassiveScan("unknown"))

        with self.assertRaisesRegex(RuntimeError, "could not verify passive scan completion"):
            HOOK.zap_get_alerts(zap, "https://127.0.0.1:8443", [], {})

    def test_allows_alert_report_only_when_passive_scan_queue_is_empty(self):
        zap = FakeZap(passive_scan=FakePassiveScan("0"))
        args = (zap, "https://127.0.0.1:8443", [], {})

        self.assertEqual(HOOK.zap_get_alerts(*args), args)


class AuthenticatedZapWorkflowPolicyTest(unittest.TestCase):
    def test_both_zap_api_allowlists_survive_the_scanner_argument_parser(self):
        workflow_path = Path(__file__).parents[1] / ".github" / "workflows" / "security.yml"
        workflow = workflow_path.read_text(encoding="utf-8")
        scan_commands = [
            line for line in workflow.splitlines()
            if "zap-full-scan.py" in line
        ]
        self.assertEqual(len(scan_commands), 2)
        expected_options = [
            "-config",
            r"api.addrs.addr.name=^(localhost|127[.]0[.]0[.]1|::1|0:0:0:0:0:0:0:1|::ffff:127[.]0[.]0[.]1)(:[0-9]+)?$",
            "-config",
            "api.addrs.addr.regex=true",
        ]

        for command in scan_commands:
            with self.subTest(command="authenticated" if "--hook=" in command else "passive"):
                match = re.search(r'-z "([^"]+)"', command)
                self.assertIsNotNone(match)
                self.assertEqual(shlex.split(match.group(1)), expected_options)
                allowlist = re.compile(expected_options[1].split("=", 1)[1])
                for local_address in (
                    "localhost",
                    "localhost:12345",
                    "127.0.0.1",
                    "::1",
                    "0:0:0:0:0:0:0:1",
                    "::ffff:127.0.0.1",
                ):
                    with self.subTest(address=local_address):
                        self.assertRegex(local_address, allowlist)
                for external_address in (
                    "192.0.2.10",
                    "2001:db8::1",
                    "0:0:0:0:0:0:0:2",
                    "example.invalid",
                    "localhost.example.invalid",
                ):
                    with self.subTest(address=external_address):
                        self.assertNotRegex(external_address, allowlist)


if __name__ == "__main__":
    unittest.main()
