import { expect, test } from '@playwright/test';

for (const width of [1440, 390]) {
    test(`revenue shows selected supplier profit at ${width}px`, async ({
        page,
    }) => {
        test.skip(
            process.env.PLAYWRIGHT_REVENUE_FIXTURES !== '1',
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
        await page.goto('/revenues');
        await expect(
            page.getByRole('heading', { name: 'Revenue', exact: true }),
        ).toBeVisible();
        const realized = page
            .getByRole('row')
            .filter({ hasText: 'REV-REALIZED' });
        await expect(realized).toContainText('Realized profit');

        for (const amount of [
            '€1,000.00',
            '€750.00',
            '€250.00',
            '€100.00',
            '€150.00',
        ]) {
            await expect(
                realized.getByRole('cell', { name: amount, exact: true }),
            ).toHaveCount(1);
        }

        await expect(
            page.getByRole('row').filter({ hasText: 'REV-ESTIMATE' }),
        ).toContainText('Estimated profit');
        const search = page.locator('.fi-ta input[type="search"]');
        await search.fill('REV-REALIZED');
        await expect(
            page.getByRole('row').filter({ hasText: 'REV-ESTIMATE' }),
        ).toHaveCount(0);
        await expect(realized).toContainText('€150.00');

        if (width === 1440) {
            const profit = await realized
                .locator('.fi-ta-cell-net-profit')
                .boundingBox();
            expect(profit!.x + profit!.width).toBeLessThanOrEqual(width);
        }

        await page.screenshot({
            path: `test-results/revenues-${width}.png`,
            fullPage: true,
        });
        expect(errors).toEqual([]);
    });
}
