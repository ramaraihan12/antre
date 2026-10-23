import { expect, test } from '@playwright/test';
import { BRANCH, waitForLive } from './helpers';

test('a customer books a slot and pays the deposit with the local fake driver', async ({ page }) => {
    await page.goto(`/book/${BRANCH}`);

    // The last day in the strip is always in the future, so every slot on it is open.
    await page.locator('[data-testid^="day-"]').last().click();
    await expect(page.getByTestId('slots')).toBeVisible();
    await page.getByTestId('slots').locator('button:not([disabled])').first().click();

    await page.getByLabel('Nama').fill('Wulan Sari');
    await page.getByLabel('Nomor HP').fill('0812 3456 7890');
    await page.getByTestId('book-submit').click();

    await page.waitForURL(/\/b\//);
    await expect(page.getByTestId('booking-status')).toHaveAttribute('data-status', 'pending_payment');
    await expect(page.getByTestId('countdown')).toBeVisible();
    await expect(page.getByText('Pembayaran simulasi, hanya untuk lokal')).toBeVisible();
    await waitForLive(page);

    await page.getByTestId('fake-pay').click();

    await expect(page.getByTestId('booking-status')).toHaveAttribute('data-status', 'paid');
    await expect(page.getByText('Deposit diterima')).toBeVisible();
    await expect(page.getByTestId('order-id')).toHaveText(/^ANT-[A-Z0-9]{10}$/);
});
