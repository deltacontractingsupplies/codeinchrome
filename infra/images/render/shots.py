#!/usr/bin/env python3
"""A page at several screen sizes, as those devices show it (agent render.go).

    shots.py '[{"name": "phone", "width": 390, "height": 844, "url": "https://..."}, ...]'

Chromium's own --window-size cannot go below 500 px, so a "phone" was a
500 px page cropped to 390 - not a phone. This drives one headless Chromium
over the DevTools protocol instead: each size is a real device emulation
(Emulation.setDeviceMetricsOverride, mobile viewport handling below 700 px),
then the page is loaded, given its scripts' time, measured - the width its
content really takes, so sideways scrolling is a number, not a guess - and
captured. One line of JSON per size on stdout; nothing else is printed there.

It runs in the renderer's container: no capabilities, read-only root, its own
egress-chained network, as an unprivileged user. The DevTools port is on the
container's loopback only.
"""
import json
import subprocess
import sys
import time
import urllib.request

import websocket  # python3-websocket (websocket-client)

PORT = 9222
DESKTOP_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")
MOBILE_UA = ("Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 "
             "(KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36")
LOAD_SECONDS = 20
SETTLE_SECONDS = 1.5


class Page:
    def __init__(self, ws_url):
        self.ws = websocket.create_connection(ws_url, timeout=LOAD_SECONDS, suppress_origin=True)
        self.seq = 0
        self.events = []

    def call(self, method, params=None):
        self.seq += 1
        self.ws.send(json.dumps({"id": self.seq, "method": method, "params": params or {}}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self.seq:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error'].get('message')}")
                return msg.get("result", {})
            self.events.append(msg)

    def wait_event(self, name, seconds):
        deadline = time.time() + seconds
        while time.time() < deadline:
            for i, e in enumerate(self.events):
                if e.get("method") == name:
                    del self.events[: i + 1]
                    return True
            self.ws.settimeout(max(0.1, deadline - time.time()))
            try:
                self.events.append(json.loads(self.ws.recv()))
            except websocket.WebSocketTimeoutException:
                break
        return False


def browser():
    proc = subprocess.Popen([
        "chromium", "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
        "--no-first-run", "--disable-extensions", "--disable-background-networking", "--disable-sync",
        "--mute-audio", "--hide-scrollbars", "--disable-crash-reporter", "--crash-dumps-dir=/tmp/crash",
        "--user-data-dir=/tmp/profile", f"--remote-debugging-port={PORT}", "--remote-debugging-address=127.0.0.1",
        "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/list", timeout=1) as r:
                pages = [t for t in json.load(r) if t.get("type") == "page"]
            if pages:
                return proc, pages[0]["webSocketDebuggerUrl"]
        except OSError:
            pass
        time.sleep(0.1)
    proc.kill()
    raise RuntimeError("the browser did not start")


def main():
    sizes = json.loads(sys.argv[1])
    proc, ws_url = browser()
    try:
        page = Page(ws_url)
        page.call("Page.enable")
        for s in sizes:
            mobile = s["width"] < 700
            page.call("Emulation.setDeviceMetricsOverride", {
                "width": s["width"], "height": s["height"], "deviceScaleFactor": 1, "mobile": mobile,
            })
            page.call("Emulation.setTouchEmulationEnabled", {"enabled": mobile})
            page.call("Network.setUserAgentOverride", {"userAgent": MOBILE_UA if mobile else DESKTOP_UA})
            page.call("Page.navigate", {"url": s["url"]})
            page.wait_event("Page.loadEventFired", LOAD_SECONDS)
            time.sleep(SETTLE_SECONDS)
            measured = page.call("Runtime.evaluate", {
                "expression": "[document.documentElement.scrollWidth, window.innerWidth]",
                "returnByValue": True,
            }).get("result", {}).get("value") or [0, 0]
            png = page.call("Page.captureScreenshot", {"format": "png"})["data"]
            print(json.dumps({
                "name": s["name"], "width": s["width"], "height": s["height"],
                "contentWidth": int(measured[0]), "viewportWidth": int(measured[1]), "png": png,
            }), flush=True)
    finally:
        proc.kill()


if __name__ == "__main__":
    main()
