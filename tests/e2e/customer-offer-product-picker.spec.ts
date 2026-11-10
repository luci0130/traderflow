import { expect, test } from '@playwright/test';

for (const width of [1440, 390]) {
    test(`offer product picker displays the complete comparison table at ${width}px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 640 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('/login');
        await page.getByLabel('Email').fill('mihai@traderflow.test');
        await page.locator('input[type="password"]').fill('password');
        await page
            .getByRole('button', { name: /sign in|autentificare/i })
            .click();
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await page.goto(
            process.env.PLAYWRIGHT_OFFER_PATH ?? '/customer-offers/3/edit',
        );
        await page
            .getByRole('button', { name: /^(Add product|Adaugă produs)$/ })
            .click();

        const modal = page.locator('.fi-modal-window:visible').last();
        await expect(
            modal.getByRole('button', {
                name: /Add selected products|Adaugă produsele selectate/,
            }),
        ).toBeVisible();
        const comparison = modal.locator('.fi-ta');
        await expect(comparison).toBeVisible();
        const bounds = await modal.boundingBox();
        expect(bounds!.width).toBeGreaterThan(width * 0.9);
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width + 1);
        const tableBounds = await comparison.boundingBox();
        expect(tableBounds!.width).toBeGreaterThanOrEqual(1200);

        await expect
            .poll(() =>
                modal.evaluate(
                    (element) => element.scrollHeight - element.clientHeight,
                ),
            )
            .toBeGreaterThan(100);
        await page.mouse.move(width / 2, 320);
        await page.mouse.wheel(0, 600);
        await expect
            .poll(() => modal.evaluate((element) => element.scrollTop))
            .toBeGreaterThan(100);
        await expect(modal.locator('.fi-modal-header')).toBeInViewport();
        await expect(
            modal.getByRole('button', { name: /^(Close|Închide)$/ }).last(),
        ).toBeInViewport();
        await page.mouse.wheel(0, -1200);
        await expect
            .poll(() => modal.evaluate((element) => element.scrollTop))
            .toBe(0);

        const search = comparison.locator('input[type="search"]').first();
        await search.fill('No matching product e2e');
        await expect(
            comparison
                .getByText(
                    /No canonical products|No products|No records|Niciun|Nu există/i,
                )
                .first(),
        ).toBeVisible();
        await search.fill('');
        await comparison
            .getByRole('button', { name: 'Toggle prices' })
            .first()
            .click();
        await comparison
            .getByTitle('Add to the offer at the next priority')
            .first()
            .click();
        await expect(
            modal.getByRole('button', {
                name: /Add selected products.*1|Adaugă produsele selectate.*1/,
            }),
        ).toBeEnabled();
        await page.screenshot({
            path: `test-results/offer-product-picker-${width}.png`,
            fullPage: true,
        });
        await modal
            .getByRole('button', { name: /^(Close|Închide)$/ })
            .last()
            .click();
        await expect(modal).toBeHidden();
        expect(errors).toEqual([]);
    });
}

test('adding selected products refreshes Items and removing the last supplier removes the product', async ({
    page,
}) => {
    test.skip(
        process.env.PLAYWRIGHT_OFFER_FIXTURES !== '1',
        'Requires CustomerOfferEditingTestSeeder in an isolated database.',
    );
    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in|autentificare/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.goto(
        process.env.PLAYWRIGHT_OFFER_PATH ?? '/customer-offers/1/edit',
    );
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page
        .getByRole('button', { name: 'Add product', exact: true })
        .click();
    const modal = page.getByRole('dialog').last();
    await modal.getByRole('button', { name: 'Toggle prices' }).first().click();
    await modal
        .getByTitle('Add to the offer at the next priority')
        .first()
        .click();
    await modal
        .getByRole('button', { name: 'Add selected products (1)', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText('Browser tomatoes', { exact: true }).first(),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Remove supplier', exact: true })
        .first()
        .click();
    await page
        .getByRole('dialog')
        .last()
        .getByRole('button', { name: 'Confirm', exact: true })
        .click();
    await expect(
        page.getByText('This offer has no product lines yet.', { exact: true }),
    ).toBeVisible();
    expect(errors).toEqual([]);
});

test('transport costs are saved per supplier and totals recalculate automatically', async ({
    page,
}) => {
    test.skip(
        process.env.PLAYWRIGHT_OFFER_FIXTURES !== '1',
        'Requires CustomerOfferEditingTestSeeder in an isolated database.',
    );
    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in|autentificare/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.goto(
        process.env.PLAYWRIGHT_OFFER_PATH ?? '/customer-offers/1/edit',
    );
    await page
        .getByRole('button', { name: 'Add product', exact: true })
        .click();
    const modal = page.getByRole('dialog').last();
    await modal.getByRole('button', { name: 'Toggle prices' }).first().click();
    await modal
        .getByTitle('Add to the offer at the next priority')
        .first()
        .click();
    await expect(
        modal.getByTitle('Priority 1 — click to remove'),
    ).toBeVisible();
    await modal
        .getByTitle('Add to the offer at the next priority')
        .first()
        .click();
    await expect(
        modal.getByTitle('Priority 2 — click to remove'),
    ).toBeVisible();
    await modal
        .getByRole('button', { name: 'Add selected products (1)', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    const rows = page.locator('tr:visible').filter({
        has: page.getByRole('spinbutton', {
            name: 'Transport cost (RON)',
            exact: true,
        }),
    });
    await expect(rows).toHaveCount(2);
    await rows
        .first()
        .getByRole('spinbutton', { name: 'Secured qty', exact: true })
        .fill('20');
    await rows
        .first()
        .getByRole('spinbutton', { name: 'Transport cost (RON)', exact: true })
        .fill('10');
    await expect(rows.first().locator('[data-sourcing-total]')).toHaveText(
        '110.00 RON',
    );
    await expect(
        rows.last().getByRole('spinbutton', {
            name: 'Transport cost (RON)',
            exact: true,
        }),
    ).toHaveValue('0.0000');
    await rows
        .last()
        .getByRole('spinbutton', { name: 'Secured qty', exact: true })
        .fill('10');
    await rows
        .last()
        .getByRole('spinbutton', { name: 'Transport cost (RON)', exact: true })
        .fill('7');
    await expect(rows.last().locator('[data-sourcing-total]')).toHaveText(
        '57.00 RON',
    );

    await page.reload();
    await expect(
        rows.first().getByRole('spinbutton', {
            name: 'Transport cost (RON)',
            exact: true,
        }),
    ).toHaveValue('10.0000');
    await expect(
        rows.last().getByRole('spinbutton', {
            name: 'Transport cost (RON)',
            exact: true,
        }),
    ).toHaveValue('7.0000');
    await expect(rows.first().locator('[data-sourcing-total]')).toHaveText(
        '110.00 RON',
    );
    await expect(rows.last().locator('[data-sourcing-total]')).toHaveText(
        '57.00 RON',
    );

    await page.getByRole('button', { name: 'Suppliers', exact: true }).click();
    await expect(page.locator('[data-sourcing-total]:visible')).toHaveText([
        '110.00 RON',
        '57.00 RON',
    ]);
});
