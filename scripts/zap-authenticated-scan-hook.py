"""Configure a disposable, fail-closed authenticated OWASP ZAP scan."""

from __future__ import annotations

import os
import re
from pathlib import Path
from urllib.parse import quote, unquote, urlsplit


CONTEXT_NAME = "FurimaDeck CI authenticated scan"
USER_NAME = "FurimaDeck CI scanner"
AUTH_CONTEXT_FILE_ENV = "ZAP_AUTH_CONTEXT_FILE"
AUTH_CREDENTIALS_FILE_ENV = "ZAP_AUTH_CREDENTIALS_FILE"
LOGGED_OUT_MARKER = r"<title>ログイン\s*\|\s*FurimaDeck</title>"
AUTHENTICATED_DASHBOARD_MARKER = '<h1 class="sr-only">FurimaDeck Dashboard</h1>'
_ZAP = None
_TARGET_ORIGIN = None


def _api_ok(response, operation):
    if isinstance(response, dict):
        ok = str(response.get("code", "")).upper() == "OK"
    else:
        ok = str(response).upper() == "OK"
    if not ok:
        raise RuntimeError(f"ZAP could not {operation}.")
    return response


def _value(response, *names):
    if isinstance(response, dict):
        wanted = {name.lower() for name in names}
        for key, value in response.items():
            if str(key).lower() in wanted:
                return value
    if isinstance(response, (str, int, bool)):
        return response
    return None


def _require_value(response, expected, *names):
    actual = _value(response, *names)
    if str(actual) != expected:
        raise RuntimeError("ZAP authentication configuration failed read-back verification.")


def _loopback_origin(target):
    parsed = urlsplit(target)
    if (
        parsed.scheme != "https"
        or parsed.hostname not in {"127.0.0.1", "localhost"}
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError("ZAP authenticated scanning is restricted to an HTTPS loopback target.")
    return f"{parsed.scheme}://{parsed.netloc}"


def _context_file():
    configured = os.environ.get(AUTH_CONTEXT_FILE_ENV, "")
    path = Path(configured)
    if not configured or not path.is_absolute() or path.exists() or path.is_symlink():
        raise RuntimeError("ZAP authenticated context output path is missing, unsafe, or already exists.")
    return path


def _new_id(response, *names):
    value = _value(response, *names)
    if value is not None and str(value).isdigit():
        return str(value)
    raise RuntimeError("ZAP did not return the identifier required for authenticated scanning.")


def zap_started(zap, target):
    """Export an in-scope context skeleton and keep Spider from following logout."""
    global _ZAP, _TARGET_ORIGIN
    _ZAP = None
    _TARGET_ORIGIN = None
    origin = _loopback_origin(target)
    context_file = _context_file()
    _ZAP = zap
    _TARGET_ORIGIN = origin

    zap.spider.set_option_logout_avoidance(True)
    if str(zap.spider.option_logout_avoidance).lower() != "true":
        raise RuntimeError("ZAP Spider logout-avoidance could not be enabled and verified.")

    context_id = None
    try:
        context_id = _new_id(zap.context.new_context(CONTEXT_NAME), "contextId", "id")
        scope_pattern = re.escape(origin) + r"/.*"
        _api_ok(
            zap.context.include_in_context(CONTEXT_NAME, scope_pattern),
            "scope the isolated application",
        )
        _api_ok(zap.context.set_context_in_scope(CONTEXT_NAME, "true"), "mark the scan context in scope")
        _require_value(zap.context.context(CONTEXT_NAME), "true", "inScope")
        include_regexes = zap.context.include_regexs(CONTEXT_NAME)
        if not isinstance(include_regexes, list) or scope_pattern not in include_regexes:
            raise RuntimeError("ZAP scan context scope failed read-back verification.")
        _new_id(zap.users.new_user(context_id, USER_NAME), "userId", "userid", "id")
        _api_ok(zap.context.export_context(CONTEXT_NAME, str(context_file)), "export the scan context")
        if not context_file.is_file() or context_file.stat().st_size == 0:
            raise RuntimeError("ZAP did not create the authenticated scan context file.")
    finally:
        if context_id is not None:
            _api_ok(zap.context.remove_context(CONTEXT_NAME), "remove the temporary context skeleton")

    print("ZAP authenticated scan context skeleton created for the HTTPS loopback target.")


def _read_credentials():
    path = Path(os.environ.get(AUTH_CREDENTIALS_FILE_ENV, ""))
    if not path.is_absolute() or path.is_symlink() or not path.is_file():
        raise RuntimeError("ZAP disposable credentials file is unavailable or unsafe.")
    lines = path.read_text(encoding="utf-8").splitlines()
    if len(lines) != 2:
        raise RuntimeError("ZAP disposable credentials file has an unexpected format.")
    email, password = lines
    if not re.fullmatch(r"furimadeck-zap-[0-9]+\.[0-9]+@example\.invalid", email):
        raise RuntimeError("ZAP scan email is not a disposable CI identity.")
    if not re.fullmatch(r"[0-9a-f]{64}", password):
        raise RuntimeError("ZAP scan password does not match the generated disposable format.")
    return email, password


def _mapping_value(response, name):
    if not isinstance(response, dict):
        return None
    wanted = name.lower()
    return next((value for key, value in response.items() if str(key).lower() == wanted), None)


def _auth_indicator(origin):
    dashboard_url = re.escape(f"{origin}/furimadeck-dashboard")
    return (
        rf"(?im)(?:^HTTP/[0-9.]+[ \t]+(?:302|303)\b[^\r\n]*\r?\n"
        rf"(?:[^\r\n]+\r?\n)*?^Location:[ \t]*(?:{dashboard_url}|/furimadeck-dashboard)"
        rf"(?:[?#][^\r\n]*|[ \t]*\r?\n)|{re.escape(AUTHENTICATED_DASHBOARD_MARKER)})"
    )


def _logged_out_indicator(origin):
    login_url = re.escape(f"{origin}/login")
    return (
        rf"(?im)(?:^HTTP/[0-9.]+[ \t]+(?:401|403)\b[^\r\n]*"
        rf"|^HTTP/[0-9.]+[ \t]+(?:302|303)\b[^\r\n]*\r?\n"
        rf"(?:[^\r\n]+\r?\n)*?^Location:[ \t]*(?:{login_url}|/login)"
        rf"(?:[?#][^\r\n]*|[ \t]*\r?\n)|{LOGGED_OUT_MARKER})"
    )


def _configure_csrf_token(zap):
    # zap-api-python exposes option_tokens_names as a property returning a list.
    before = zap.acsrf.option_tokens_names
    if not isinstance(before, list):
        raise RuntimeError("ZAP did not return the configured anti-CSRF token names.")
    if "_token" not in before:
        _api_ok(zap.acsrf.add_option_token("_token"), "register the login CSRF token")
    after = zap.acsrf.option_tokens_names
    if not isinstance(after, list) or "_token" not in after:
        raise RuntimeError("ZAP login CSRF token registration failed read-back verification.")


def zap_import_context_wrap(context_id):
    """Configure and exercise authentication only after importing the context skeleton."""
    if _ZAP is None:
        raise RuntimeError("ZAP was not initialized before importing the authenticated context.")

    context_file = Path(os.environ.get(AUTH_CONTEXT_FILE_ENV, ""))
    if not context_file.is_absolute() or not context_file.is_file() or context_file.is_symlink():
        raise RuntimeError("ZAP authenticated context file is unavailable or unsafe.")

    try:
        context_id = str(context_id)
        origin = _loopback_origin(os.environ.get("APP_URL", ""))
        if _TARGET_ORIGIN is None or origin != _TARGET_ORIGIN:
            raise RuntimeError("ZAP login origin does not match the authenticated scan target.")
        email, password = _read_credentials()
        login_url = f"{origin}/login"
        login_data = "email={%username%}&password={%password%}"
        auth_config = (
            f"loginUrl={quote(login_url, safe='')}"
            f"&loginRequestData={quote(login_data, safe='')}"
        )

        _api_ok(
            _ZAP.sessionManagement.set_session_management_method(
                context_id, "cookieBasedSessionManagement", ""
            ),
            "configure cookie-based session management",
        )
        _api_ok(
            _ZAP.authentication.set_authentication_method(
                context_id, "formBasedAuthentication", auth_config
            ),
            "configure form-based authentication",
        )
        _configure_csrf_token(_ZAP)

        logged_in_indicator = _auth_indicator(origin)
        logged_out_indicator = _logged_out_indicator(origin)
        re.compile(logged_in_indicator)
        re.compile(logged_out_indicator)
        _api_ok(
            _ZAP.authentication.set_logged_in_indicator(context_id, logged_in_indicator),
            "set the logged-in indicator",
        )
        _api_ok(
            _ZAP.authentication.set_logged_out_indicator(context_id, logged_out_indicator),
            "set the logged-out indicator",
        )
        _api_ok(
            _ZAP.context.set_context_checking_strategy(
                CONTEXT_NAME,
                "POLL_URL",
                pollurl=f"{origin}/furimadeck-dashboard",
                pollfrequency=1,
                pollfrequencyunits="REQUESTS",
            ),
            "enable protected-dashboard authentication polling",
        )

        users = _ZAP.users.users_list(context_id)
        if not isinstance(users, list):
            raise RuntimeError("ZAP did not return the imported context users.")
        user = next(
            (
                item for item in users
                if isinstance(item, dict)
                and str(_mapping_value(item, "name")) == USER_NAME
            ),
            None,
        )
        if user is None:
            raise RuntimeError("ZAP did not import the expected disposable scan user.")
        user_id = _new_id(user, "userId", "userid", "id")
        credentials = f"username={email}&password={password}"
        _api_ok(
            _ZAP.users.set_authentication_credentials(context_id, user_id, credentials),
            "set the disposable scan credentials",
        )
        _api_ok(_ZAP.users.set_user_enabled(context_id, user_id, "true"), "enable the scan user")

        context_state = _ZAP.context.context(CONTEXT_NAME)
        _require_value(context_state, "true", "inScope")
        _require_value(context_state, "POLL_URL", "checkingStrategy")
        _require_value(context_state, "formBasedAuthentication", "authType")
        _require_value(context_state, logged_in_indicator, "loggedInPattern")
        _require_value(context_state, logged_out_indicator, "loggedOutPattern")
        if _mapping_value(context_state, "pollUrl") != f"{origin}/furimadeck-dashboard":
            raise RuntimeError("ZAP protected-dashboard poll URL failed read-back verification.")
        if str(_mapping_value(context_state, "pollFrequency")) != "1":
            raise RuntimeError("ZAP authentication poll frequency failed read-back verification.")
        if str(_mapping_value(context_state, "pollFrequencyUnits")) != "REQUESTS":
            raise RuntimeError("ZAP authentication poll units failed read-back verification.")
        _require_value(
            _ZAP.sessionManagement.get_session_management_method(context_id),
            "cookieBasedSessionManagement",
        )
        auth_parameters = _ZAP.authentication.get_authentication_method(context_id)
        if not isinstance(auth_parameters, dict):
            raise RuntimeError("ZAP form-based login request failed read-back verification.")
        _require_value(auth_parameters, "formBasedAuthentication", "methodName")
        configured_login_url = _mapping_value(auth_parameters, "loginUrl")
        configured_login_data = _mapping_value(auth_parameters, "loginRequestData")
        if configured_login_url != login_url or configured_login_data is None:
            raise RuntimeError("ZAP form-based login request failed read-back verification.")
        decoded_login_data = unquote(unquote(str(configured_login_data)))
        if decoded_login_data != login_data:
            raise RuntimeError("ZAP form-based login request failed read-back verification.")
        _require_value(
            _ZAP.authentication.get_logged_in_indicator(context_id), logged_in_indicator
        )
        _require_value(
            _ZAP.authentication.get_logged_out_indicator(context_id), logged_out_indicator
        )
        users_after = _ZAP.users.users_list(context_id)
        enabled_user = next(
            (
                item for item in users_after
                if isinstance(item, dict)
                and str(_mapping_value(item, "name")) == USER_NAME
                and str(_mapping_value(item, "enabled")).lower() == "true"
            ),
            None,
        ) if isinstance(users_after, list) else None
        if enabled_user is None:
            raise RuntimeError("ZAP did not enable the disposable scan user.")

        # This action executes the configured login and checks the logged-in
        # indicator against its response. Do not treat configuration alone as proof.
        auth_result = _ZAP.users.authenticate_as_user(context_id, user_id)
        if (
            not isinstance(auth_result, dict)
            or _mapping_value(auth_result, "authSuccessful") != "true"
        ):
            raise RuntimeError("ZAP could not authenticate the disposable scan user.")
    except Exception as error:
        if isinstance(error, RuntimeError) and str(error).startswith("ZAP "):
            raise
        raise RuntimeError("ZAP authenticated scan setup failed; details were suppressed.") from None
    finally:
        try:
            context_file.unlink(missing_ok=True)
        except OSError:
            raise RuntimeError("ZAP could not remove the temporary context file.") from None

    print("ZAP form login succeeded for the disposable scan user; CSRF, session, and protected-dashboard polling were verified.")


def zap_get_alerts(zap, baseurl, ignore_scan_rules, out_of_scope_dict):
    """Refuse to produce a passing report while passive checks remain queued."""
    try:
        pending_records = int(zap.pscan.records_to_scan)
    except (AttributeError, TypeError, ValueError):
        raise RuntimeError("ZAP could not verify passive scan completion.") from None
    if pending_records != 0:
        raise RuntimeError("ZAP passive scan is incomplete; refusing to accept a partial report.")
    return zap, baseurl, ignore_scan_rules, out_of_scope_dict
