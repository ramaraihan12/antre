import { expect, test } from '@playwright/test';
import { BRANCH, login, waitForLive } from './helpers';

/*
 * Takes the pictures used in the README from a real run:
 *   SCREENSHOTS=1 npx playwright test e2e/screenshots.spec.ts
 * The global setup then also seeds a demo morning so the dashboard has numbers.
 */
test.skip(!process.env.SCREENSHOTS, 'Set SCREENSHOTS=1 to regenerate docs/*.png');

test('owner dashboard', async ({ page }) => {
    await login(page, 'owner@senyum.test');
    await page.goto('/dashboard');
    await expect(page.getByTestId('stat-served')).toBeVisible();
    await page.screenshot({ path: 'docs/dashboard.png', fullPage: true });
});

test('booking page on a phone', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 430, height: 900 }, deviceScaleFactor: 2, locale: 'id-ID' });
    const page = await context.newPage();

    await page.goto(`/book/${BRANCH}`);
    await page.locator('[data-testid^="day-"]').nth(1).click();
    await expect(page.getByTestId('slots')).toBeVisible();
    await page.getByTestId('slot-10:30').click();
    await page.getByLabel('Nama').fill('Wulan Sari');
    await page.getByLabel('Nomor HP').fill('0812 3456 7890');
    await expect(page.getByTestId('book-submit')).toBeEnabled();
    await page.screenshot({ path: 'docs/booking.png', fullPage: true });

    await context.close();
});

test('staff console and tv display', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'id-ID' });

    // Customers take numbers on their phones.
    const customer = await context.newPage();
    for (const code of ['A', 'B', 'C', 'A', 'D', 'B', 'A']) {
        await customer.goto(`/q/${BRANCH}`);
        await customer.getByTestId(`take-${code}`).click();
        await customer.waitForURL(/\/t\//);
    }

    // The TV display is open on the wall.
    const display = await context.newPage();
    await display.setViewportSize({ width: 1280, height: 720 });
    await display.goto(`/display/${BRANCH}`);
    await waitForLive(display);
    await display.getByTestId('sound-toggle').click();

    // Staff work three counters.
    const staff = await context.newPage();
    await login(staff, 'dimas@senyum.test');
    await staff.goto('/console');

    const counterLinks = staff.locator('[data-testid^="pick-counter-"]');
    const hrefs = await counterLinks.evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).getAttribute('href')!));
    expect(hrefs.length).toBe(3);

    for (const href of hrefs) {
        await staff.goto(href);
        await waitForLive(staff);
        await staff.getByTestId('call-next').click();
        await expect(staff.getByTestId('current-code')).toBeVisible();
    }

    // Back on the first counter, the one the screenshot shows.
    await staff.goto(hrefs[0]);
    await waitForLive(staff);
    await expect(staff.getByTestId('current-code')).toBeVisible();
    await staff.screenshot({ path: 'docs/console.png', fullPage: true });

    // Let the highlight on the display settle before taking its picture.
    await expect(display.getByTestId('counter-3-code')).not.toHaveText('---');
    await display.waitForTimeout(9000);
    await display.screenshot({ path: 'docs/display.png' });

    await context.close();
});
