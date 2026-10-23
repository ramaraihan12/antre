import { expect, type Page } from '@playwright/test';

export const BRANCH = 'senyum-salatiga-kota';

/** Signs in with one of the seeded demo accounts (password is always "password"). */
export async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/(console|dashboard)/);
}

/** Waits until the page has an open websocket to Reverb. */
export async function waitForLive(page: Page) {
    await expect(page.getByTestId('live-badge').first()).toHaveAttribute('data-status', 'connected', { timeout: 15_000 });
}

/** Records what the page asks the browser to say, so a test can check the announcement. */
export async function captureSpeech(page: Page) {
    await page.addInitScript(() => {
        const spoken: string[] = [];
        (window as unknown as { __spoken: string[] }).__spoken = spoken;
        const synth = window.speechSynthesis;
        synth.speak = (utterance: SpeechSynthesisUtterance) => {
            spoken.push(`${utterance.lang}|${utterance.text}`);
            setTimeout(() => utterance.onend?.(new Event('end') as SpeechSynthesisEvent), 10);
        };
    });
}

export async function spoken(page: Page): Promise<string[]> {
    return page.evaluate(() => (window as unknown as { __spoken: string[] }).__spoken);
}
