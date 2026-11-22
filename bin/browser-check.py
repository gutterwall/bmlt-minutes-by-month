#!/usr/bin/env python3
"""Browser check of [bmlt_minutes_by_month] in headless Chrome, driven over the DevTools protocol (standard library
only; the driver is the one in bmlt-minutes-archive-importer's bin/browser-e2e.py).

  browser-check.py <page url> [--login <site> <user> <password>] [--phone] [--shot <file.png>]

Desktop (default): no script errors; each committee has exactly one open month; the open months' PDFs load and show
as 500 px File block previews; no closed month's PDF is downloaded with the page; opening a closed month loads its
PDFs; every File block's link and Download button answer 200.
--phone: a phone browser (mobile user agent, no PDF viewer): previews stay hidden, links and Download buttons show.
--login: log in first (to check a draft's preview). Set CHROME to another browser binary.
"""
import base64, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request
from urllib.parse import urlparse

PORT = int(os.environ.get('CHROME_DEBUG_PORT', '9337'))

class WS:
    def __init__(self, url):
        u = urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.s.recv(1)
        assert b' 101 ' in head.split(b'\r\n')[0], head

    def _read(self, n):
        out = b''
        while len(out) < n:
            chunk = self.s.recv(n - len(out))
            if not chunk:
                raise ConnectionError('closed')
            out += chunk
        return out

    def send(self, text):
        data = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._read(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._read(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._read(8))[0]
            payload = self._read(n)
            if (b1 & 0x0f) == 9:
                continue
            message += payload
            if b1 & 0x80:
                return message.decode()


class Browser:
    """Headless Chrome with a fresh profile; collects console errors, exceptions and every request URL."""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='mai-chrome-')
        self.proc = subprocess.Popen([os.environ.get('CHROME', 'google-chrome'), '--headless=new', f'--remote-debugging-port={PORT}',
                                      f'--user-data-dir={self.profile}', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
                                      '--lang=en-US', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pages = []
        for i in range(180):
            try:
                pages = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list', timeout=5)) if t['type'] == 'page']
                if pages:
                    break
                if i >= 8:
                    urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT'), timeout=5).read()
            except Exception:
                pass
            time.sleep(0.25)
        if not pages:
            self.close()
            sys.exit('Chrome did not start')
        self.ws = WS(pages[0]['webSocketDebuggerUrl'])
        self.n = 0
        self.errors, self.requests = [], []
        for domain in ('Page', 'Runtime', 'Network', 'Log'):
            self.call(domain + '.enable')

    def _event(self, msg):
        m, p = msg.get('method'), msg.get('params', {})
        if m == 'Runtime.exceptionThrown':
            self.errors.append('exception: ' + p['exceptionDetails'].get('text', '') + ' ' + json.dumps(p['exceptionDetails'].get('exception', {}).get('description', ''))[:300])
        elif m == 'Runtime.consoleAPICalled' and p.get('type') in ('error', 'assert'):
            self.errors.append('console: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in p.get('args', [])))
        elif m == 'Log.entryAdded' and p['entry'].get('level') == 'error' and 'favicon' not in p['entry'].get('url', ''):
            self.errors.append('log: ' + p['entry'].get('text', '') + ' ' + p['entry'].get('url', ''))
        elif m == 'Network.requestWillBeSent':
            self.requests.append(p['request']['url'])

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.n:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
            self._event(msg)

    def js(self, expression):
        r = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def wait(self, check, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.js(check):
                    return
            except Exception:
                pass
            time.sleep(0.25)
        raise TimeoutError(check + '\n' + (self.js('document.body ? document.body.innerText.slice(0, 1500) : ""') or ''))

    def go(self, url):
        # Mark the current page: readyState is already "complete" there, so without the mark the wait could pass
        # before the new page has replaced it.
        self.js('window.__e2eLeaving = true')
        self.call('Page.navigate', url=url)
        self.wait('!window.__e2eLeaving && document.readyState === "complete"')

    def login(self, site, user, password):
        self.call('Network.clearBrowserCookies')
        self.go(site + '/wp-login.php')
        self.wait('!!document.getElementById("wp-submit")')
        # Submit from a jQuery ready callback, so it runs after WordPress's own: user-profile.js, which wp-login.php
        # loads, sets up its form there, and its beforeunload handler throws if the page is left before that.
        self.js('(function () { var submit = function () {'
                f'document.getElementById("user_login").value={json.dumps(user)};'
                f'document.getElementById("user_pass").value={json.dumps(password)};'
                'document.getElementById("wp-submit").click(); };'
                'window.jQuery ? jQuery(submit) : submit(); })(); true')
        time.sleep(0.5)
        self.wait('document.readyState === "complete" && !location.href.includes("wp-login.php")')

    def close(self):
        try:
            self.proc.terminate()
            self.proc.wait(10)
        except Exception:
            pass
        subprocess.run(['rm', '-rf', self.profile], stderr=subprocess.DEVNULL)



def ok(text):
    print('  ok   ' + text)


def fail(text):
    print('  FAIL ' + text)
    sys.exit(1)


PHONE_UA = ('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) '
            'Chrome/130.0.0.0 Mobile Safari/537.36')


def main():
    args = sys.argv[1:]
    if not args or args[0].startswith('-'):
        sys.exit(__doc__)
    page, login, phone, shot = args.pop(0), None, False, ''
    while args:
        a = args.pop(0)
        if a == '--login':
            login, args = args[:3], args[3:]
        elif a == '--phone':
            phone = True
        elif a == '--shot':
            shot = args.pop(0)
        else:
            sys.exit(__doc__)
    b = Browser()
    try:
        if phone:
            b.call('Emulation.setDeviceMetricsOverride', width=412, height=915, deviceScaleFactor=2, mobile=True)
            b.call('Emulation.setUserAgentOverride', userAgent=PHONE_UA)
            # Phone browsers have no built-in PDF viewer; headless Chrome reports one whatever the user agent.
            b.call('Page.addScriptToEvaluateOnNewDocument',
                   source='Object.defineProperty(Navigator.prototype, "pdfViewerEnabled", { get: () => false });')
        else:
            b.call('Emulation.setDeviceMetricsOverride', width=1280, height=1600, deviceScaleFactor=1, mobile=False)
        if login:
            b.login(*login)
        b.go(page)
        b.wait('!!document.querySelector(".bmbm")')
        time.sleep(2)
        if b.errors:
            fail('script errors: ' + '; '.join(b.errors))
        ok('no script errors' + (' (phone)' if phone else ''))
        # What the page fetched, from the browser's own resource timing (DevTools network events miss loads made by
        # the PDF viewer).
        fetched = lambda: b.js('performance.getEntriesByType("resource").map(e => e.name)') or []
        loaded = fetched()
        state = b.js('''(() => {
            const pdfs = (root) => [...root.querySelectorAll("object.wp-block-file__embed")].map(o => o.getAttribute("data") || o.getAttribute("data-bmbm-src"));
            const visible = (el) => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== "hidden";
            return [...document.querySelectorAll(".bmbm__committee")].map(c => ({
                committee: c.querySelector(".bmbm__committee-title").textContent,
                open: [...c.querySelectorAll("details.bmbm__month[open]")].map(d => ({
                    month: d.querySelector(".bmbm__month-title").textContent,
                    pdfs: pdfs(d),
                    shown: [...d.querySelectorAll("object.wp-block-file__embed")].filter(o => !o.hidden && o.offsetHeight >= 400).length,
                    files: d.querySelectorAll(".wp-block-file").length,
                    usable: [...d.querySelectorAll(".wp-block-file")].filter(f => visible(f.querySelector("a:not([download])")) && visible(f.querySelector("a[download]"))).length,
                    links: d.querySelectorAll("li.bmlt-minutes__item").length })),
                closedPdfs: [...c.querySelectorAll("details.bmbm__month:not([open])")].flatMap(d => pdfs(d)) }));
        })()''')
        if not state:
            fail('no committees on the page')
        for c in state:
            if len(c['open']) != 1:
                fail(f"{c['committee']}: {len(c['open'])} open months")
            o = c['open'][0]
            if o['usable'] != o['files']:
                fail(f"{c['committee']} {o['month']}: {o['files'] - o['usable']} File blocks without a visible link and Download button")
            if phone:
                if o['shown']:
                    fail(f"{c['committee']} {o['month']}: {o['shown']} PDF previews shown on a phone")
            else:
                missing = [u for u in o['pdfs'] if u not in loaded]
                if missing:
                    fail(f"{c['committee']} {o['month']}: PDFs not loaded: {missing}")
                if o['shown'] != len(o['pdfs']):
                    fail(f"{c['committee']} {o['month']}: {o['shown']} of {len(o['pdfs'])} previews shown")
            early = [u for u in c['closedPdfs'] if u in loaded]
            if early:
                fail(f"{c['committee']}: closed months' PDFs downloaded with the page: {early}")
            ok(f"{c['committee']}: {o['month']} open, {o['files']} File blocks ({len(o['pdfs'])} PDFs, "
               f"{0 if phone else o['shown']} previews shown), {o['links']} links; {len(c['closedPdfs'])} PDFs in closed months not downloaded")
        if shot:
            data = b.call('Page.captureScreenshot', format='png', captureBeyondViewport=False)['data']
            open(shot, 'wb').write(base64.b64decode(data))
            ok('screenshot ' + shot)
        if phone:
            # Opening a month downloads nothing: there is no preview to show.
            target = b.js('''(() => { const d = [...document.querySelectorAll("details.bmbm__month:not([open])")].find(d => d.querySelector("object[data-bmbm-src]"));
                if (!d) return null; d.id = "bmbm-check"; d.querySelector("summary").click();
                return [...d.querySelectorAll("object[data-bmbm-src]")].map(o => o.getAttribute("data-bmbm-src")); })()''')
            if not target:
                fail('no closed month with PDFs to open')
            b.wait('document.getElementById("bmbm-check").open', 10)
            time.sleep(2)
            early = [u for u in target if u in fetched()]
            if early:
                fail('a phone downloaded hidden previews: ' + str(early))
            visible = b.js('[...document.querySelectorAll("#bmbm-check .wp-block-file a[download]")].filter(a => a.getClientRects().length).length')
            ok(f'opening a month on a phone shows {visible} Download buttons and downloads none of its {len(target)} PDFs')
            return
        # Every document's link and Download button.
        bad = b.js('''(async () => {
            const urls = [...new Set([...document.querySelectorAll(".wp-block-file a")].map(a => a.href))];
            const out = [];
            for (const u of urls) { const r = await fetch(u, { method: "HEAD" }); if (r.status !== 200) out.push(u + " " + r.status); }
            return [urls.length, out];
        })()''')
        if bad[1]:
            fail('documents that do not open: ' + '; '.join(bad[1]))
        ok(f'all {bad[0]} documents answer 200')
        # Open the first closed month that has PDFs.
        target = b.js('''(() => { const d = [...document.querySelectorAll("details.bmbm__month:not([open])")].find(d => d.querySelector("object[data-bmbm-src]"));
            if (!d) return null; d.id = "bmbm-check"; const urls = [...d.querySelectorAll("object[data-bmbm-src]")].map(o => o.getAttribute("data-bmbm-src"));
            d.querySelector("summary").click(); return urls; })()''')
        if not target:
            fail('no closed month with PDFs to open')
        b.wait('document.getElementById("bmbm-check").open && !document.querySelector("#bmbm-check object[data-bmbm-src]")', 10)
        time.sleep(2)
        missing = [u for u in target if u not in fetched()]
        if missing:
            fail('opening a closed month did not load ' + str(missing))
        shown = b.js('[...document.querySelectorAll("#bmbm-check object.wp-block-file__embed")].filter(o => !o.hidden && o.offsetHeight >= 400).length')
        if shown != len(target):
            fail(f'opening a closed month showed {shown} of {len(target)} previews')
        ok(f'opening a closed month loaded and showed its {len(target)} PDF previews')
        if b.errors:
            fail('script errors: ' + '; '.join(b.errors))
    finally:
        b.close()


if __name__ == '__main__':
    main()
