import { expect, test } from '@playwright/test';

test('validates a CSV in the modal before importing supplier products', async ({
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
    await page.getByRole('button', { name: /Import CSV|Importă CSV/ }).click();

    const modal = page.getByRole('dialog').locator('.fi-modal-window');
    const importButton = modal.getByRole('button', {
        name: /Import products|Importă produse$/,
    });
    await expect(modal).toBeVisible();
    await expect(importButton).toBeDisabled();
    const download = page.waitForEvent('download');
    await modal
        .getByRole('button', { name: /Download template|Descarcă model/ })
        .click();
    expect((await download).suggestedFilename()).toBe(
        'supplier-products-template.csv',
    );
    await expect(modal).toBeVisible();

    await modal
        .getByRole('button', { name: /Select an option|Selectează o opțiune/ })
        .click();
    await page.getByRole('option').first().click();
    const name = `CSV browser ${Date.now()}`;
    const header =
        'name,unit_price,min_quantity_value,min_quantity_unit,currency\n';
    const fileInput = modal.locator('input.filepond--browser');
    await fileInput.setInputFiles({
        name: 'invalid.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`${header}${name},-2,10,kg,EUR\n`),
    });
    await expect(modal.locator('.filepond--item')).toHaveCount(1);
    await expect(
        modal.locator(
            '.filepond--item[data-filepond-item-state="processing-complete"]',
        ),
    ).toHaveCount(1);
    await modal
        .getByRole('button', { name: /Validate CSV|Validează CSV/ })
        .click();
    const report = modal.getByRole('textbox', {
        name: /CSV validation result|Rezultatul validării CSV/,
    });
    await expect(report).toHaveValue(/(?:CSV row|Rând CSV) 2:/);
    expect(errors).toEqual([]);
    await expect(modal).toBeVisible();
    await expect(importButton).toBeDisabled();

    await modal
        .getByRole('button', { name: /Remove|Elimină/, exact: true })
        .click();
    await expect(modal.locator('.filepond--item')).toHaveCount(0);
    await fileInput.setInputFiles({
        name: 'valid.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`${header}${name},2.50,10,kg,EUR\n`),
    });
    await expect(
        modal.locator(
            '.filepond--item[data-filepond-item-state="processing-complete"]',
        ),
    ).toHaveCount(1);
    await modal
        .getByRole('button', { name: /Validate CSV|Validează CSV/ })
        .click();
    await expect(report).toHaveValue(/CSV valid: 1/);
    await expect(importButton).toBeEnabled();
    await expect(page.locator('.fi-ta')).not.toContainText(name);
    await importButton.click();
    await expect(modal).toBeHidden();
    await page.locator('input[type="search"]').last().fill(name);
    await expect(page.locator('.fi-ta')).toContainText(name);
    expect(errors).toEqual([]);
});
