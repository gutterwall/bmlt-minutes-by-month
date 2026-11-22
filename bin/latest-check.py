#!/usr/bin/env python3
"""Browser check of [bmlt_minutes_latest] in headless Chrome (the driver in browser-check.py).

  latest-check.py <PDF page url> <Word page url> [--phone] [--shot <file.png>]

PDF page: PDF.js loads (its script and worker answer and run), every page of the PDF gets its place, the first page
is drawn (its canvas has ink on it), its text is laid over it and can be found, there is no horizontal scrolling and
no script errors. --phone: the same on a phone (mobile user agent, no built-in PDF viewer, 2x pixels), where the
pages are drawn sharp. Word page: the document shows as HTML.
"""
import base64, importlib.util, os, sys, time

spec = importlib.util.spec_from_file_location('browser_check', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'browser-check.py'))
bc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bc)
ok, fail = bc.ok, bc.fail


def main():
    args = sys.argv[1:]
    if len(args) < 2 or args[0].startswith('-'):
        sys.exit(__doc__)
    pdf_page, word_page, args = args[0], args[1], args[2:]
    phone, shot = '--phone' in args, ''
    if '--shot' in args:
        shot = args[args.index('--shot') + 1]
    b = bc.Browser()
    try:
        if phone:
            b.call('Emulation.setDeviceMetricsOverride', width=412, height=915, deviceScaleFactor=2, mobile=True)
            b.call('Emulation.setUserAgentOverride', userAgent=bc.PHONE_UA)
            b.call('Page.addScriptToEvaluateOnNewDocument',
                   source='Object.defineProperty(Navigator.prototype, "pdfViewerEnabled", { get: () => false });')
        else:
            b.call('Emulation.setDeviceMetricsOverride', width=1280, height=1600, deviceScaleFactor=1, mobile=False)
        where = ' (phone)' if phone else ''

        b.go(pdf_page)
        try:
            b.wait('!!document.querySelector(".bmbm-latest__page canvas") && !!document.querySelector(".bmbm-latest__page .textLayer span")', 40)
        except TimeoutError as e:
            fail('the PDF was not drawn' + where + ': ' + '; '.join(b.errors) + ' ' + str(e)[:400])
        time.sleep(1)
        s = b.js('''(() => {
            const pages = [...document.querySelectorAll(".bmbm-latest__page")];
            const c = pages[0].querySelector("canvas");
            const px = c.getContext("2d").getImageData(0, 0, c.width, c.height).data;
            let ink = 0;
            for (let i = 0; i < px.length; i += 4) { if (px[i] < 128 && px[i + 3] > 0) ink++; }
            return { pages: pages.length, width: pages[0].clientWidth, canvas: c.width, ink: ink,
                text: pages[0].querySelector(".textLayer").textContent,
                fallback: !!document.querySelector(".bmbm-latest__error"),
                scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                loaded: performance.getEntriesByType("resource").map(e => e.name).filter(n => n.includes("/pdfjs/")).map(n => n.split("/pdfjs/")[1].split("?")[0]) };
        })()''')
        if s['fallback']:
            fail('PDF.js failed and the browser viewer was shown instead' + where)
        if s['ink'] < 50:
            fail(f'the first page is blank ({s["ink"]} dark pixels)' + where)
        if 'Area Service Committee Minutes' not in s['text']:
            fail('the page\'s text is not laid over it: ' + repr(s['text'][:100]))
        if s['scroll']:
            fail('the page scrolls sideways' + where)
        if not {'pdf.min.js', 'pdf.worker.min.js'} <= set(s['loaded']):
            fail('PDF.js files not loaded: ' + str(s['loaded']))
        if phone and s['canvas'] < s['width'] * 2 - 2:
            fail(f'the page is drawn at {s["canvas"]} px for {s["width"]} px on a 2x screen')
        if b.errors:
            fail('script errors' + where + ': ' + '; '.join(b.errors))
        ok(f'PDF drawn with PDF.js{where}: {s["pages"]} page(s), {s["canvas"]} px for {s["width"]} px, its text selectable; loaded {", ".join(sorted(set(s["loaded"])))}')
        if shot:
            b.js('document.querySelector(".bmbm-latest").scrollIntoView()')
            time.sleep(0.5)
            open(shot, 'wb').write(base64.b64decode(b.call('Page.captureScreenshot', format='png')['data']))
            ok('screenshot ' + shot)

        b.go(word_page)
        b.wait('!!document.querySelector(".bmbm-doc")')
        text = b.js('document.querySelector(".bmbm-doc").textContent')
        if 'Treasurer report' not in text:
            fail('the Word document is not shown: ' + repr(text[:200]))
        if b.errors:
            fail('script errors' + where + ': ' + '; '.join(b.errors))
        ok('the Word document shows as HTML' + where)
    finally:
        b.close()


if __name__ == '__main__':
    main()
