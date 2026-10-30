"""Fail-closed ZAP hook for authenticated scans."""


def zap_started(zap, target):
    """Prevent the traditional Spider from logging out the disposable scan user."""
    del target

    zap.spider.set_option_logout_avoidance(True)

    if str(zap.spider.option_logout_avoidance).lower() != "true":
        raise RuntimeError(
            "ZAP Spider logout-avoidance could not be enabled and verified."
        )

    print("ZAP Spider logout avoidance enabled and verified.")
