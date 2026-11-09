import { expect, test } from '@playwright/test';

for (const viewport of [
    { width: 1440, height: 1000 },
    { width: 390, height: 844 },
]) {
    test(`topbar preferences work at ${viewport.width}px`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));

        await page.goto('/login');
        await page.getByLabel('Email').fill('mihai@traderflow.test');
        await page.locator('input[type="password"]').fill('password');
        await page
            .getByRole('button', { name: /sign in|autentificare/i })
            .click();
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await page.goto('/market-comparison');

        const topbar = page.locator('.fi-topbar');
        const currencyButton = topbar.getByRole('button', {
            name: /^(Display currency|Monedă de afișare):/,
        });
        await expect(currencyButton).toBeVisible();
        const originalCurrency = (await currencyButton.getAttribute(
            'aria-label',
        ))!.split(': ')[1];
        const nextCurrency = originalCurrency === 'RON' ? 'EUR' : 'RON';

        try {
            await currencyButton.click();
            await page
                .locator(
                    `form[action$="/preferences/currency/${nextCurrency}"] button`,
                )
                .click();
            await expect(currencyButton).toHaveAttribute(
                'aria-label',
                new RegExp(`: ${nextCurrency}$`),
            );
            await page.reload();
            await expect(currencyButton).toHaveAttribute(
                'aria-label',
                new RegExp(`: ${nextCurrency}$`),
            );

            await topbar
                .getByRole('button', { name: /^(Language|Limbă):/ })
                .click();
            await page
                .locator('.fi-dropdown-panel:visible a[href$="/locale/ro"]')
                .click();
            await expect(
                topbar.getByRole('button', { name: 'Limbă: Romana' }),
            ).toBeVisible();
            await expect(currencyButton).toHaveAttribute(
                'aria-label',
                `Monedă de afișare: ${nextCurrency}`,
            );

            await topbar.getByRole('button', { name: 'Limbă: Romana' }).click();
            await page
                .locator('.fi-dropdown-panel:visible a[href$="/locale/en"]')
                .click();
            await expect(
                topbar.getByRole('button', { name: 'Language: English' }),
            ).toBeVisible();
            await page.screenshot({
                path: `test-results/currency-preferences-${viewport.width}.png`,
            });
            await page.goto('/currency-exchange-rates');
            await expect(
                page.getByRole('heading', { name: 'Exchange rates' }),
            ).toBeVisible();
            await expect(
                page.getByRole('cell', { name: 'BNR', exact: true }).first(),
            ).toBeVisible();
            expect(errors).toEqual([]);
        } finally {
            await currencyButton.click();
            await page
                .locator(
                    `form[action$="/preferences/currency/${originalCurrency}"] button`,
                )
                .click();
            await expect(currencyButton).toHaveAttribute(
                'aria-label',
                new RegExp(`: ${originalCurrency}$`),
            );
        }
    });
}
