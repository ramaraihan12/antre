import { expect, test } from '@playwright/test';
import { BRANCH, captureSpeech, login, spoken, waitForLive } from './helpers';

test('staff calls the next ticket and the display and the customer update without a reload', async ({ browser }) => {
    const context = await browser.newContext();

    // 1. The TV display is open and connected, with sound switched on.
    const display = await context.newPage();
    await captureSpeech(display);
    await display.goto(`/display/${BRANCH}`);
    await waitForLive(display);
    await display.getByTestId('sound-toggle').click();
    await expect(display.getByTestId('sound-toggle')).toContainText('Suara aktif');
    await expect(display.getByTestId('counter-1-code')).toHaveText('---');
    // A marker that only survives if the page is never reloaded.
    await display.evaluate(() => ((window as unknown as { __marker: number }).__marker = 1));

    // 2. A customer takes a number on their phone.
    const customer = await context.newPage();
    await customer.goto(`/q/${BRANCH}`);
    await customer.getByTestId('take-A').click();
    await expect(customer.getByTestId('ticket-code')).toHaveText('A-001');
    await expect(customer.getByTestId('ticket-position')).toHaveText('1');
    await waitForLive(customer);
    await customer.evaluate(() => ((window as unknown as { __marker: number }).__marker = 1));

    // 3. A second customer joins; the first one's position must not change.
    const second = await context.newPage();
    await second.goto(`/q/${BRANCH}`);
    await second.getByTestId('take-A').click();
    await expect(second.getByTestId('ticket-code')).toHaveText('A-002');
    await expect(second.getByTestId('ticket-position')).toHaveText('2');

    // 4. Staff at loket 1 clicks "Panggil berikutnya".
    const staff = await context.newPage();
    await login(staff, 'dimas@senyum.test');
    await staff.goto('/console');
    await staff.getByRole('link', { name: /Loket 1/ }).click();
    await waitForLive(staff);
    await expect(staff.getByTestId('call-next')).toBeEnabled();
    await staff.getByTestId('call-next').click();
    await expect(staff.getByTestId('current-code')).toHaveText('A-001');

    // 5. The display shows the number, was not reloaded, and read the sentence aloud.
    await expect(display.getByTestId('counter-1-code')).toHaveText('A-001');
    await expect(display.getByTestId('recent-calls')).toContainText('A-001');
    expect(await display.evaluate(() => (window as unknown as { __marker?: number }).__marker)).toBe(1);
    await expect.poll(() => spoken(display), { timeout: 8000 }).toContain('id-ID|Nomor antrean A satu, silakan ke loket satu');

    // 6. The customer's own page flipped to "your turn" and the second customer moved up.
    await expect(customer.getByTestId('ticket-called')).toContainText('Silakan ke Loket 1');
    expect(await customer.evaluate(() => (window as unknown as { __marker?: number }).__marker)).toBe(1);
    await expect(second.getByTestId('ticket-position')).toHaveText('1');

    // 7. Finishing the ticket clears the counter on the display and lets staff call the next one.
    await staff.getByTestId('done').click();
    await expect(display.getByTestId('counter-1-code')).toHaveText('---');
    await staff.getByTestId('call-next').click();
    await expect(display.getByTestId('counter-1-code')).toHaveText('A-002');
    await expect(display.getByTestId('recent-calls').locator('li').first()).toContainText('A-002');

    await context.close();
});
