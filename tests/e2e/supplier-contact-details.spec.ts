import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function expandSection(page: Page, heading: string) {
    const section = page.locator('section.fi-section').filter({
        has: page.getByRole('heading', { name: heading, exact: true }),
    });
    const content = section.locator(':scope > .fi-section-content-ctn');
    await expect(content).toHaveAttribute('aria-expanded', /true|false/);

    if ((await content.getAttribute('aria-expanded')) === 'false') {
        await section.locator('.fi-section-header').click();
    }

    await expect(content).toHaveAttribute('aria-expanded', 'true');
}

function selectField(page: Page, name: string) {
    return page
        .locator('.fi-fo-field')
        .filter({
            has: page.locator(
                `[id="form.${name}-label"], label[for="form.${name}"]`,
            ),
        })
        .locator('.fi-select-input-btn');
}

test.skip(
    process.env.PLAYWRIGHT_SUPPLIER_CONTACT_FIXTURES !== '1',
    'Requires CustomerOfferEditingTestSeeder in an isolated database.',
);

for (const width of [1440, 390]) {
    test(`supplier country search and phone validation work at ${width}px`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('/login');
        await page.getByLabel('Email').fill('mihai@traderflow.test');
        await page.locator('input[type="password"]').fill('password');
        await page
            .getByRole('button', { name: /sign in|autentificare/i })
            .click();
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await page.goto('/suppliers/1/edit');
        await expandSection(page, 'Address and terms');
        await selectField(page, 'country').click();
        const search = page.locator('input[type="search"]:visible').last();
        await search.fill('Romania');
        const option = page
            .getByRole('option')
            .filter({ hasText: 'Romania (RO)' });
        await expect(option).toHaveCount(1);
        await expect(option.locator('img')).toHaveAttribute(
            'src',
            'https://flagcdn.com/ro.svg',
        );
        await option.click();
        await expandSection(page, 'Supplier');
        const phone = page.getByLabel('Phone', { exact: true });
        await phone.fill('0722');
        await page
            .getByRole('button', { name: 'Save changes', exact: true })
            .click();
        await expect(
            page.getByText(
                'Enter a valid phone number for the selected country calling code.',
            ),
        ).toBeVisible();
        await phone.fill('0722 123 456');
        await page
            .getByRole('button', { name: 'Save changes', exact: true })
            .click();
        await expect(
            page.getByText('Saved', { exact: true }).first(),
        ).toBeVisible();
        await page.reload();
        await expandSection(page, 'Supplier');
        await expect(phone).toHaveValue('722123456');
        await expect(selectField(page, 'phone_country')).toContainText('+40');
        const prefixBounds = await selectField(
            page,
            'phone_country',
        ).boundingBox();
        expect(prefixBounds!.height).toBeLessThan(60);
        expect(prefixBounds!.width).toBeGreaterThan(160);
        await page.screenshot({
            path: `test-results/supplier-contact-${width}.png`,
            fullPage: true,
        });
        expect(errors).toEqual([]);
    });
}
