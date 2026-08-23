import path from 'node:path';

import { expect, test } from '@playwright/test';

test('uploads an image without native input flicker and saves the product', async ({
    page,
}) => {
    const consoleErrors: string[] = [];
    const failedResponses: string[] = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            failedResponses.push(`${response.status()} ${response.url()}`);
        }
    });

    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));

    await page.route(
        '**/js/filament/forms/components/file-upload.js*',
        async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 750));
            await route.continue();
        },
        { times: 1 },
    );
    await page.goto('/products/create');

    const gallery = page.locator('.fi-fo-image-gallery');
    await expect(gallery).toBeVisible();
    await expect(
        gallery.locator('.fi-fo-file-upload-input-ctn > input'),
    ).toBeHidden();
    await expect(
        gallery.locator('.fi-fo-file-upload-input-ctn > input'),
    ).toHaveAttribute('data-allow-minimum-upload-duration', 'false');
    await expect(
        gallery.getByText('Choose File', { exact: false }),
    ).toHaveCount(0);
    await expect(gallery.locator('.filepond--root')).toBeVisible();

    await gallery
        .locator('input.filepond--browser')
        .setInputFiles(path.resolve('public/apple-touch-icon.png'));

    await expect(gallery.locator('[x-sortable-item]')).toHaveCount(1, {
        timeout: 15_000,
    });
    await expect(page.getByText(/uploading file/i)).toHaveCount(0);

    const productName = `Playwright gallery ${Date.now()}`;
    await page.getByLabel('Name').fill(productName);
    await page.getByRole('button', { name: /^create$/i }).click();
    await page.waitForURL(/\/products\/\d+\/edit$/);
    await expect(page.getByLabel('Name')).toHaveValue(productName);
    await expect(
        page.locator('.fi-fo-image-gallery [x-sortable-item]'),
    ).toHaveCount(1);

    expect(consoleErrors).toEqual([]);
    expect(failedResponses).toEqual([]);
});

test('moves the main frame immediately after transferring the role', async ({
    page,
}) => {
    const consoleErrors: string[] = [];
    const failedResponses: string[] = [];
    const deferredEditorRequests: string[] = [];
    const focalPointAssetRequests: string[] = [];
    let isTrackingEditorRequests = false;

    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            failedResponses.push(`${response.status()} ${response.url()}`);
        }
    });
    page.on('request', (request) => {
        if (request.url().includes('image-gallery-focal-point')) {
            focalPointAssetRequests.push(request.url());
        }

        if (
            isTrackingEditorRequests &&
            request.url().includes('/livewire-') &&
            request.url().endsWith('/update')
        ) {
            deferredEditorRequests.push(request.url());
        }
    });

    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));

    await page.goto('/products/create');

    const gallery = page.locator('.fi-fo-image-gallery');

    await gallery.getByRole('button', { name: /add images/i }).click();

    const uploadModal = page.getByRole('dialog');

    const uploadInput = uploadModal.locator('input.filepond--browser');

    await expect(uploadInput).toHaveCount(1);
    await uploadInput.setInputFiles([
        path.resolve('public/apple-touch-icon.png'),
        path.resolve('public/images/offer-pdf/banner.jpg'),
    ]);

    await expect(
        uploadModal.getByText('Upload complete', { exact: true }),
    ).toHaveCount(2, { timeout: 30_000 });

    await uploadModal.getByRole('button', { name: /submit/i }).click();

    const items = gallery.locator('[x-sortable-item]');
    const frames = gallery.locator('[data-gallery-item-frame]');

    await expect(items).toHaveCount(2, { timeout: 30_000 });
    await expect(frames.nth(0)).toHaveAttribute('data-main', 'true');
    await expect(frames.nth(1)).toHaveAttribute('data-main', 'false');

    await items
        .nth(1)
        .getByRole('button', { name: /edit image/i })
        .click({ position: { x: 20, y: 20 } });

    const detailsModal = page.getByRole('dialog');

    await expect(
        detailsModal.locator('[data-image-gallery-editor-preview]'),
    ).toBeVisible();
    await expect(
        detailsModal.locator('[data-image-gallery-preview-image] img'),
    ).toBeVisible();
    await expect(
        detailsModal.locator('[data-image-gallery-editor-file-details]'),
    ).toBeVisible();
    await expect(
        detailsModal.locator('[data-image-gallery-editor-thumbnails] img'),
    ).toHaveCount(2);
    await expect(
        detailsModal
            .locator('[data-image-gallery-editor-thumbnails] img')
            .first()
            .locator('..'),
    ).toHaveCSS('width', '96px');
    await expect(
        detailsModal.locator('[data-image-gallery-file-editor]'),
    ).toHaveAttribute('data-visible', 'false');
    await expect(
        detailsModal.getByText('Focal point', { exact: true }),
    ).toHaveCount(0);
    await expect(
        detailsModal.locator(
            '[data-image-gallery-focal-axis], [data-image-gallery-focal-marker]',
        ),
    ).toHaveCount(0);

    isTrackingEditorRequests = true;
    await detailsModal
        .getByRole('button', { name: /edit image/i, exact: true })
        .click();
    await expect(
        detailsModal.locator('[data-image-gallery-file-editor]'),
    ).toHaveAttribute('data-visible', 'true');

    const cropEditor = detailsModal.locator('.fi-fo-file-upload-editor');

    await expect(cropEditor).toBeVisible();
    await expect(
        cropEditor.locator('.fi-fo-file-upload-editor-control-panel'),
    ).toBeVisible();
    await expect(
        cropEditor.getByRole('button', { name: /^save$/i }),
    ).toBeVisible();
    await cropEditor.getByRole('button', { name: /^cancel$/i }).click();
    await expect(cropEditor).toBeHidden();

    const cropSection = detailsModal.locator(
        '[data-image-gallery-crop-trigger]',
    );

    await cropSection.locator('.fi-section-header').click();
    await expect(cropEditor).toBeVisible();
    await expect(
        cropEditor.locator('.fi-fo-file-upload-editor-control-panel'),
    ).toBeVisible();
    await cropEditor.getByRole('button', { name: /^cancel$/i }).click();
    await expect(cropEditor).toBeHidden();
    await expect(cropSection.locator('.fi-section')).toHaveClass(
        /fi-collapsed/,
    );

    await detailsModal.locator('label[for$="roles-main"]').click();
    await detailsModal.getByRole('button', { name: /apply changes/i }).click();

    await expect(detailsModal).toBeHidden();
    await expect(frames.nth(0)).toHaveAttribute('data-main', 'false');
    await expect(frames.nth(1)).toHaveAttribute('data-main', 'true');

    await page.waitForTimeout(300);

    expect(deferredEditorRequests).toEqual([]);
    isTrackingEditorRequests = false;

    const productName = `Playwright deferred gallery ${Date.now()}`;

    await page.getByLabel('Name').fill(productName);
    await page.getByRole('button', { name: /^create$/i }).click();
    await page.waitForURL(/\/products\/\d+\/edit$/);

    const persistedFrames = page.locator(
        '.fi-fo-image-gallery [data-gallery-item-frame]',
    );

    await expect(persistedFrames).toHaveCount(2);
    await expect(persistedFrames.nth(0)).toHaveAttribute('data-main', 'false');
    await expect(persistedFrames.nth(1)).toHaveAttribute('data-main', 'true');

    await persistedFrames
        .nth(1)
        .getByRole('button', { name: /edit image/i })
        .evaluate((button: HTMLButtonElement) => button.click());

    const persistedDetailsModal = page.getByRole('dialog');

    await expect(
        persistedDetailsModal.getByText('Focal point', { exact: true }),
    ).toHaveCount(0);
    await expect(
        persistedDetailsModal.locator(
            '[data-image-gallery-focal-axis], [data-image-gallery-focal-marker]',
        ),
    ).toHaveCount(0);
    await persistedDetailsModal.getByText('Resize', { exact: true }).click();

    const resizeFields = persistedDetailsModal.locator(
        '[data-image-gallery-resize-fields]',
    );

    await expect(resizeFields).toHaveCSS('display', 'flex');

    const widthBounds = await persistedDetailsModal
        .getByLabel('Maximum width')
        .boundingBox();
    const heightBounds = await persistedDetailsModal
        .getByLabel('Maximum height')
        .boundingBox();

    expect(widthBounds).not.toBeNull();
    expect(heightBounds).not.toBeNull();
    expect(Math.abs(widthBounds!.y - heightBounds!.y)).toBeLessThan(2);

    isTrackingEditorRequests = true;
    await persistedDetailsModal.getByLabel('Maximum width').fill('260');
    await expect(
        persistedDetailsModal.getByLabel('Maximum height'),
    ).toHaveValue('150');
    await persistedDetailsModal
        .getByRole('button', { name: /^apply$/i })
        .click();

    await expect(
        persistedDetailsModal.locator('[data-image-gallery-resize]'),
    ).toBeVisible();
    await expect(
        persistedDetailsModal.locator('[data-image-gallery-resize]'),
    ).toHaveAttribute('data-applied', 'true');
    await page.waitForTimeout(300);
    expect(deferredEditorRequests).toEqual([]);
    isTrackingEditorRequests = false;

    await persistedDetailsModal
        .getByRole('button', { name: /apply changes/i })
        .click();

    await expect(persistedDetailsModal).toBeHidden();

    await persistedFrames
        .nth(1)
        .getByRole('button', { name: /edit image/i })
        .evaluate((button: HTMLButtonElement) => button.click());

    await expect(
        page.getByRole('dialog').getByLabel('Maximum width'),
    ).toHaveValue('260');
    await expect(
        page.getByRole('dialog').getByLabel('Maximum height'),
    ).toHaveValue('150');
    await expect(
        page.getByRole('dialog').getByRole('button', { name: /^apply$/i }),
    ).toBeDisabled();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: /apply changes/i })
        .click();

    await page.getByRole('button', { name: /save changes/i }).click();
    await expect(page.getByText(/saved/i).last()).toBeVisible();

    await persistedFrames
        .nth(1)
        .getByRole('button', { name: /edit image/i })
        .evaluate((button: HTMLButtonElement) => button.click());

    const resizedDetailsModal = page.getByRole('dialog');

    await expect(resizedDetailsModal).toContainText('260 × 150 px');

    await resizedDetailsModal.getByText('Replace', { exact: true }).click();

    const replacementUpload = resizedDetailsModal.locator(
        '[data-image-gallery-replacement-upload]',
    );

    await expect(replacementUpload.locator('.filepond--root')).toBeVisible();
    await expect(
        resizedDetailsModal.locator('[data-image-gallery-replacement-url]'),
    ).toBeVisible();
    await replacementUpload
        .locator('input.filepond--browser')
        .setInputFiles(path.resolve('public/apple-touch-icon.png'));
    await expect(
        replacementUpload.getByText('Upload complete', { exact: true }),
    ).toBeVisible({ timeout: 15_000 });
    await resizedDetailsModal
        .getByRole('button', { name: /apply changes/i })
        .click();
    await expect(resizedDetailsModal).toBeHidden();

    await page.getByRole('button', { name: /save changes/i }).click();
    await expect(page.getByText(/saved/i).last()).toBeVisible();

    await persistedFrames
        .nth(1)
        .getByRole('button', { name: /edit image/i })
        .evaluate((button: HTMLButtonElement) => button.click());
    await expect(page.getByRole('dialog')).toContainText(
        'apple-touch-icon.png',
    );

    expect(focalPointAssetRequests).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(failedResponses).toEqual([]);
});
