import { expect, test } from '@playwright/test';

for (const width of [1440, 390]) {
    for (const theme of ['light', 'dark'] as const) {
        test(`Malcara registration switches language at ${width}px in ${theme} mode`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 1000 });
            await page.emulateMedia({ colorScheme: theme });
            await page.addInitScript(
                (value) => localStorage.setItem('theme', value),
                theme,
            );
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.goto(
                process.env.PLAYWRIGHT_REGISTRATION_PATH ??
                    '/producer/register',
            );
            await expect(
                page.getByRole('heading', {
                    name: 'Create your Malcara account',
                }),
            ).toBeVisible();

            for (const logo of await page
                .locator('img[alt="Malcara"]:visible')
                .all()) {
                await expect(logo).toBeVisible();
                expect(
                    await logo.evaluate(
                        (element) => (element as HTMLImageElement).naturalWidth,
                    ),
                ).toBeGreaterThan(0);
            }

            await expect(
                page.locator('a[href="mailto:contact@malcara.com"]:visible'),
            ).toBeVisible();
            await expect(
                page.locator('a[href="tel:+40748894514"]:visible'),
            ).toBeVisible();
            await page.getByLabel('Company name').fill('Test partner');
            await page.getByLabel('Your name').fill('Ana Pop');
            await page.getByLabel('Email address').fill('ana@example.test');
            await page
                .locator('input[type="password"]')
                .nth(0)
                .fill('sample-password-123');
            await page
                .locator('input[type="password"]')
                .nth(1)
                .fill('sample-password-123');
            await page
                .getByRole('button', { name: 'Română', exact: true })
                .click();
            await expect(
                page.getByRole('heading', {
                    name: 'Creează contul tău Malcara',
                }),
            ).toBeVisible();
            await expect(page.getByLabel('Denumire companie')).toHaveValue(
                'Test partner',
            );
            await expect(page.getByLabel('Numele tău')).toHaveValue('Ana Pop');
            await expect(
                page.locator('input[type="password"]').nth(0),
            ).toHaveValue('sample-password-123');
            await expect(
                page.locator('input[type="password"]').nth(1),
            ).toHaveValue('sample-password-123');
            await expect(
                page.getByRole('button', {
                    name: 'Creează contul Malcara',
                    exact: true,
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('heading', {
                    name: 'Partenerul tău în comerțul internațional.',
                    includeHidden: true,
                }),
            ).toHaveCount(1);
            await expect(page.locator('html')).toHaveAttribute('lang', 'ro');
            await expect(page).toHaveTitle(/Malcara/);
            expect(await page.locator('body').innerText()).not.toContain(
                'TradeFlow',
            );
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth),
            ).toBeLessThanOrEqual(width);
            await page.screenshot({
                path: `test-results/malcara-registration-${width}-${theme}.png`,
                fullPage: true,
            });
            await page
                .getByRole('button', { name: 'English', exact: true })
                .click();
            await expect(page.getByLabel('Company name')).toHaveValue(
                'Test partner',
            );
            await expect(
                page.getByRole('heading', {
                    name: 'Your partner in international trade.',
                    includeHidden: true,
                }),
            ).toHaveCount(1);
            await page.reload();
            await expect(
                page.getByRole('heading', {
                    name: 'Create your Malcara account',
                }),
            ).toBeVisible();
            expect(errors).toEqual([]);
        });
    }
}
