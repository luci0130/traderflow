import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';

for (const width of [1440, 390]) {
    test(`offer PDF template modal downloads each template at ${width}px`, async ({
        page,
    }) => {
        test.skip(
            process.env.PLAYWRIGHT_OFFER_PDF_FIXTURES !== '1',
            'Requires RevenueBrowserTestSeeder in an isolated database.',
        );
        await page.setViewportSize({ width, height: 1000 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('/login');
        await page.getByLabel('Email').fill('mihai@traderflow.test');
        await page.locator('input[type="password"]').fill('password');
        await page
            .getByRole('button', { name: 'Sign in', exact: true })
            .click();
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await page.goto('/customer-offers/2/edit');

        for (const [key, label] of [
            ['simple-en', 'EN - Simple Offer'],
            ['simple-ro', 'RO - Simple Offer'],
            ['complex', 'Complex Offer'],
        ]) {
            await page
                .getByRole('button', {
                    name: 'Generate PDF offer',
                    exact: true,
                })
                .click();
            const modal = page.getByRole('dialog').last();
            await expect(
                modal.getByRole('heading', { name: 'Choose offer template' }),
            ).toBeVisible();
            await expect(
                modal.getByRole('radio', {
                    name: 'Complex Offer',
                    exact: true,
                }),
            ).toBeChecked();
            await expect(
                modal.getByRole('radio', {
                    name: 'EN - Simple Offer',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                modal.getByRole('radio', {
                    name: 'RO - Simple Offer',
                    exact: true,
                }),
            ).toBeVisible();
            await modal
                .getByRole('radio', { name: label, exact: true })
                .check();
            await page.screenshot({
                path: `test-results/offer-pdf-modal-${key}-${width}.png`,
                fullPage: true,
            });
            const pending = page.waitForEvent('download');
            await modal
                .getByRole('button', { name: 'Generate PDF', exact: true })
                .click();
            const download = await pending;
            const path = `test-results/offer-pdf-${key}-${width}.pdf`;
            await download.saveAs(path);
            const pdf = await readFile(path);
            expect(pdf.subarray(0, 4).toString()).toBe('%PDF');
            expect(pdf.length).toBeGreaterThan(1000);
        }

        expect(errors).toEqual([]);
    });
}
