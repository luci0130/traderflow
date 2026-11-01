import { expect, test } from '@playwright/test';

test.use({ colorScheme: 'dark', viewport: { width: 1440, height: 1000 } });

test('v2 keeps collapsed suppliers and weeks closed after loading another group', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in|autentificare/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.goto('/supplier-products-v2');

    const table = page.locator('[data-supplier-products-grouped]');
    const suppliers = table.locator('[data-supplier-group]');
    const firstSupplier = suppliers.nth(0);
    const secondSupplier = suppliers.nth(1);
    const firstSupplierId = await firstSupplier.getAttribute(
        'data-supplier-group',
    );
    const secondSupplierId = await secondSupplier.getAttribute(
        'data-supplier-group',
    );
    const firstButton = firstSupplier.getByRole('button');
    const secondButton = secondSupplier.getByRole('button');
    const firstWeeks = table.locator(
        `[data-supplier-week^="supplier:${firstSupplierId}:week:"]`,
    );
    const firstProducts = table.locator(
        `[data-supplier-owner="${firstSupplierId}"]`,
    );

    await firstButton.click();
    await expect(firstProducts.first()).toBeVisible();
    await firstButton.click();
    await expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    await expect(firstProducts.first()).toBeHidden();

    await secondButton.click();
    await expect(
        table.locator(`[data-supplier-owner="${secondSupplierId}"]`).first(),
    ).toBeVisible();
    await expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    await expect(firstWeeks.first()).toBeHidden();
    await expect(firstProducts.first()).toBeHidden();

    await firstButton.click();
    await expect(firstProducts.first()).toBeVisible();
    const firstWeekButton = firstWeeks.first().getByRole('button');
    await firstWeekButton.click();
    await expect(firstProducts.first()).toBeHidden();

    await firstWeeks.nth(1).getByRole('button').click();
    await expect(firstWeeks.nth(1).getByRole('button')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
    await expect(firstWeekButton).toHaveAttribute('aria-expanded', 'false');
    const firstWeekKey = await firstWeeks
        .first()
        .getAttribute('data-supplier-week');
    await expect(
        table.locator(`[data-product-week="${firstWeekKey}"]`).first(),
    ).toBeHidden();

    await firstButton.click();
    await table
        .locator('thead')
        .getByRole('button', { name: /^Product$|^Produs$/ })
        .click();
    await expect(table.locator('th[aria-sort="ascending"]')).toHaveCount(1);
    await expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    await expect(secondButton).toHaveAttribute('aria-expanded', 'true');
    expect(errors).toEqual([]);
});

test('collapses suppliers and their creation weeks independently', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in|autentificare/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.goto('/supplier-products');

    const table = page.locator('[data-supplier-products-grouped]');
    await expect(table).toBeVisible();
    await page
        .locator(
            '.fi-pagination-records-per-page-select:not(.fi-compact) select',
        )
        .selectOption('50');
    await expect
        .poll(() => table.locator('[data-supplier-group]').count())
        .toBeGreaterThan(1);
    const supplier = table.locator('[data-supplier-group]').first();
    const supplierId = await supplier.getAttribute('data-supplier-group');
    const weeks = table.locator(
        `[data-supplier-week^="supplier:${supplierId}:week:"]`,
    );
    const products = table.locator(`[data-supplier-owner="${supplierId}"]`);
    const week = weeks.first();
    const weekKey = await week.getAttribute('data-supplier-week');
    const weekProducts = table.locator(`[data-product-week="${weekKey}"]`);
    const otherWeek = table
        .locator(
            `[data-supplier-week]:not([data-supplier-week^="supplier:${supplierId}:week:"])`,
        )
        .first();

    await expect(products.first()).toBeVisible();
    await expect(week.getByRole('button')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
    await week.getByRole('button').click();
    await expect(weekProducts.first()).toBeHidden();
    await expect(supplier).toBeVisible();
    await expect(otherWeek).toBeVisible();
    await expect(week.getByRole('button')).toHaveAttribute(
        'aria-expanded',
        'false',
    );
    await week.getByRole('button').press('Enter');
    await expect(weekProducts.first()).toBeVisible();

    const checkbox = products.first().locator('input[type="checkbox"]');
    await checkbox.check();
    await expect(products.first()).toHaveClass(/fi-selected/);
    await checkbox.uncheck();

    await supplier.getByRole('button').click();
    await expect(weeks.first()).toBeHidden();
    await expect(products.first()).toBeHidden();
    await expect(otherWeek).toBeVisible();
    await expect(supplier.getByRole('button')).toHaveAttribute(
        'aria-expanded',
        'false',
    );
    await supplier.getByRole('button').press('Enter');
    await expect(weeks.first()).toBeVisible();
    await expect(products.first()).toBeVisible();

    await supplier.getByRole('button').blur();

    await table
        .locator('thead')
        .getByRole('button', { name: /^Product$|^Produs$/ })
        .click();
    await expect(table.locator('th[aria-sort="ascending"]')).toHaveCount(1);
    await expect(supplier).toBeVisible();
    await expect(otherWeek).toBeVisible();

    await table.screenshot({
        path: test.info().outputPath('supplier-week-groups.png'),
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(supplier.getByRole('button')).toBeVisible();
    await supplier.getByRole('button').click();
    await expect(weeks.first()).toBeHidden();
    await supplier.getByRole('button').click();
    await expect(weeks.first()).toBeVisible();

    expect(errors).toEqual([]);
});
