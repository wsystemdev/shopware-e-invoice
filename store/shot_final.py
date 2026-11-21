import asyncio
from playwright.async_api import async_playwright
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch(args=['--disable-gpu','--no-sandbox']); pg=await b.new_page(viewport={'width':1600,'height':1200})
        await pg.goto('http://127.0.0.1:8000/admin',wait_until='load')
        await pg.fill('#sw-field--username','admin'); await pg.fill('#sw-field--password','shopware')
        await pg.press('#sw-field--password','Enter'); await pg.wait_for_selector('.sw-admin-menu',timeout=30000); await pg.wait_for_timeout(3000)
        async def closeall():
            for el in await pg.query_selector_all('.sw-notifications .sw-alert__close, .sw-notification__close, button[aria-label=Close]'):
                try: await el.click(timeout=1000)
                except Exception: pass
        await pg.evaluate("window.location.hash='#/sw/order/index'"); await pg.wait_for_timeout(6000)
        await pg.locator('.sw-data-grid__cell-content a').first.click(); await pg.wait_for_timeout(9000)
        await pg.evaluate("document.querySelectorAll('.sw-notifications').forEach(e=>e.remove())")
        card=pg.locator('.wsystem-einvoice-card'); await card.scroll_into_view_if_needed(); await pg.wait_for_timeout(1500)
        await pg.screenshot(path='screenshots/01-order-detail.png')
        txt=await card.inner_text(); print(txt[:300].replace('\n',' | '))
        async with pg.expect_download() as dl:
            await card.get_by_text('Download XML').click()
        d=await dl.value; await d.save_as('/tmp/ui-download.xml'); print('downloaded', d.suggested_filename)
        await pg.evaluate("window.location.hash='#/sw/extension/config/WSystemEInvoice'"); await pg.wait_for_timeout(8000)
        await pg.evaluate("document.querySelectorAll('.sw-notifications').forEach(e=>e.remove())")
        await pg.screenshot(path='screenshots/02-settings.png')
        await pg.mouse.wheel(0,900); await pg.wait_for_timeout(800)
        await pg.screenshot(path='screenshots/03-settings-seller.png')
        await b.close()
asyncio.run(main())
