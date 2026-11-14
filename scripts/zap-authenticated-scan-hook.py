"""Scope and validate the disposable authenticated OWASP ZAP scan."""

from __future__ import annotations

import os
from pathlib import Path
import re
from urllib.parse import urlsplit


CONTEXT_NAME = "FurimaDeck CI authenticated scan"
AUTH_CONTEXT_FILE_ENV = "ZAP_AUTH_CONTEXT_FILE"
AUTH_COOKIE_RULE_DESCRIPTION = "FurimaDeck authenticated scan session"
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
        raise RuntimeError("ZAP scan context failed read-back verification.")


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


def _context_file(*, must_exist):
    configured = os.environ.get(AUTH_CONTEXT_FILE_ENV, "")
    path = Path(configured)
    if not configured or not path.is_absolute() or path.is_symlink():
        raise RuntimeError("ZAP authenticated context path is missing or unsafe.")
    if path.exists() != must_exist:
        state = "unavailable" if must_exist else "already exists"
        raise RuntimeError(f"ZAP authenticated context output path is {state}.")
    return path


def _new_id(response, *names):
    value = _value(response, *names)
    if value is not None and str(value).isdigit():
        return str(value)
    raise RuntimeError("ZAP did not return the identifier required for authenticated scanning.")


def _login_exclusion_pattern(origin):
    return re.escape(origin) + r"/login/?(?:\?.*)?"


def _add_authenticated_cookie_rule(zap, origin, cookie):
    """Apply the preflight session cookie only to the exact scanned origin."""
    url_pattern = "^" + re.escape(origin) + r"/"
    _api_ok(
        zap.replacer.add_rule(
            AUTH_COOKIE_RULE_DESCRIPTION,
            "true",
            "REQ_HEADER",
            "false",
            "Cookie",
            replacement=cookie,
            url=url_pattern,
        ),
        "add the scoped authenticated-session header",
    )
    rules_response = zap.replacer.rules
    rules = _value(rules_response, "rules")
    if rules is None and isinstance(rules_response, list):
        rules = rules_response
    if not isinstance(rules, list):
        raise RuntimeError("ZAP authenticated-session header rule failed read-back verification.")
    for rule in rules:
        if _value(rule, "description") != AUTH_COOKIE_RULE_DESCRIPTION:
            continue
        if (
            str(_value(rule, "enabled")).lower() == "true"
            and str(_value(rule, "matchType", "matchtype")).upper() == "REQ_HEADER"
            and str(_value(rule, "matchRegex", "matchregex")).lower() == "false"
            and _value(rule, "matchString", "matchstring") == "Cookie"
            and _value(rule, "replacement") == cookie
            and _value(rule, "url") == url_pattern
        ):
            return
        break
    raise RuntimeError("ZAP authenticated-session header rule failed read-back verification.")


def zap_started(zap, target):
    """Export an in-scope context skeleton and prevent Spider from following logout."""
    global _ZAP, _TARGET_ORIGIN
    _ZAP = None
    _TARGET_ORIGIN = None
    origin = _loopback_origin(target)
    context_file = _context_file(must_exist=False)
    _ZAP = zap
    _TARGET_ORIGIN = origin

    zap.spider.set_option_logout_avoidance(True)
    if str(zap.spider.option_logout_avoidance).lower() != "true":
        raise RuntimeError("ZAP Spider logout-avoidance could not be enabled and verified.")

    context_id = None
    try:
        context_id = _new_id(zap.context.new_context(CONTEXT_NAME), "contextId", "id")
        scope_pattern = origin.replace(".", r"\.") + r"/.*"
        _api_ok(
            zap.context.include_in_context(CONTEXT_NAME, scope_pattern),
            "scope the isolated application",
        )
        login_exclusion = _login_exclusion_pattern(origin)
        _api_ok(
            zap.context.exclude_from_context(CONTEXT_NAME, login_exclusion),
            "exclude the login form from the authenticated scan",
        )
        _api_ok(zap.context.set_context_in_scope(CONTEXT_NAME, "true"), "mark the scan context in scope")
        _require_value(zap.context.context(CONTEXT_NAME), "true", "inScope")
        include_regexes = zap.context.include_regexs(CONTEXT_NAME)
        if not isinstance(include_regexes, list) or scope_pattern not in include_regexes:
            raise RuntimeError("ZAP scan context scope failed read-back verification.")
        exclude_regexes = zap.context.exclude_regexs(CONTEXT_NAME)
        if not isinstance(exclude_regexes, list) or login_exclusion not in exclude_regexes:
            raise RuntimeError("ZAP login-form exclusion failed read-back verification.")
        _api_ok(zap.context.export_context(CONTEXT_NAME, str(context_file)), "export the scan context")
        if not context_file.is_file() or context_file.stat().st_size == 0:
            raise RuntimeError("ZAP did not create the authenticated scan context file.")
    finally:
        if context_id is not None:
            _api_ok(zap.context.remove_context(CONTEXT_NAME), "remove the temporary context skeleton")

    print("ZAP scan context skeleton created for the HTTPS loopback target.")


def zap_import_context_wrap(context_id):
    """Verify imported scope; authentication is supplied by the preflight cookie header."""
    if _ZAP is None:
        raise RuntimeError("ZAP was not initialized before importing the authenticated context.")

    context_file = _context_file(must_exist=True)
    try:
        if not str(context_id).isdigit():
            raise RuntimeError("ZAP imported an invalid scan context identifier.")
        origin = _loopback_origin(os.environ.get("APP_URL", ""))
        if _TARGET_ORIGIN is None or origin != _TARGET_ORIGIN:
            raise RuntimeError("ZAP scan origin does not match the authenticated scan target.")
        expected_site = urlsplit(origin).netloc
        auth_cookie = os.environ.get("ZAP_AUTH_HEADER_VALUE", "")
        if (
            os.environ.get("ZAP_AUTH_HEADER", "").casefold() != "cookie"
            or os.environ.get("ZAP_AUTH_HEADER_SITE") != expected_site
            or not re.fullmatch(r"[A-Za-z0-9_-]+=[A-Za-z0-9+/_=%-]+", auth_cookie)
        ):
            raise RuntimeError("ZAP authenticated-session header configuration is missing or unsafe.")
        scope_pattern = origin.replace(".", r"\.") + r"/.*"
        login_exclusion = _login_exclusion_pattern(origin)
        context_state = _ZAP.context.context(CONTEXT_NAME)
        _require_value(context_state, "true", "inScope")
        include_regexes = _ZAP.context.include_regexs(CONTEXT_NAME)
        if not isinstance(include_regexes, list) or scope_pattern not in include_regexes:
            raise RuntimeError("ZAP imported scan context scope failed read-back verification.")
        exclude_regexes = _ZAP.context.exclude_regexs(CONTEXT_NAME)
        if not isinstance(exclude_regexes, list) or login_exclusion not in exclude_regexes:
            raise RuntimeError("ZAP imported login-form exclusion failed read-back verification.")
        _add_authenticated_cookie_rule(_ZAP, origin, auth_cookie)
    except Exception as error:
        if isinstance(error, RuntimeError) and str(error).startswith("ZAP "):
            raise
        raise RuntimeError("ZAP authenticated scan context validation failed; details were suppressed.") from None
    finally:
        try:
            context_file.unlink(missing_ok=True)
        except OSError:
            raise RuntimeError("ZAP could not remove the temporary context file.") from None

    print("ZAP imported the scoped context; the preflight session cookie supplies authentication.")


def zap_get_alerts(zap, baseurl, ignore_scan_rules, out_of_scope_dict):
    """Refuse to produce a passing report while passive checks remain queued."""
    try:
        pending_records = int(zap.pscan.records_to_scan)
    except (AttributeError, TypeError, ValueError):
        raise RuntimeError("ZAP could not verify passive scan completion.") from None
    if pending_records != 0:
        raise RuntimeError("ZAP passive scan is incomplete; refusing to accept a partial report.")
    return zap, baseurl, ignore_scan_rules, out_of_scope_dict
