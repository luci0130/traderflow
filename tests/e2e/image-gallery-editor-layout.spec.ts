import { expect, test, type Page } from '@playwright/test';

// Filament throttles repeated logins, so authenticate once and share the page
// across this file rather than signing in per test.
test.describe.configure({ mode: 'serial' });

let page: Page;

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.goto('/login');
    await page.getByLabel('Email').fill('mihai@traderflow.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
});

test.afterAll(async () => {
    await page.close();
});

/** Opens the image editor modal on the first product that already has images. */
const openEditor = async () => {
    await page.goto('/products');

    const row = page.locator('.fi-ta-row').first();
    await row.locator('a, button').first().click();
    await page.waitForURL(/\/products\/\d+\/edit/);

    const gallery = page.locator('.fi-fo-image-gallery');
    await expect(gallery).toBeVisible();

    const firstImage = gallery.locator('[data-gallery-item-frame]').first();
    await expect(firstImage).toBeVisible();

    // The card's edit overlay spans the whole tile, but the "Main" badge and the
    // hover action bar are painted above it, so a positional click never reaches
    // it. Dispatch straight at the overlay button instead.
    await firstImage
        .locator('button[aria-label]')
        .first()
        .dispatchEvent('click');

    const modal = page.locator('.fi-image-gallery-editor-modal');
    await expect(modal).toBeVisible();

    const preview = modal.locator('[data-image-gallery-editor-preview]');
    await expect(preview).toBeVisible();

    // The preview's Alpine component is loaded lazily via `x-load`, and it owns
    // the "Crop and transform" click handler. Wait for Alpine to have taken over
    // the element before interacting, otherwise a cold bundle load races the test.
    await preview.evaluate(
        async (el: HTMLElement & { _x_dataStack?: unknown }) => {
            while (!el._x_dataStack) {
                await new Promise((resolve) => requestAnimationFrame(resolve));
            }
        },
    );

    return modal;
};

test('the preview column fits the viewport without its own scrollbar', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const modal = await openEditor();

    const preview = modal.locator('[data-image-gallery-editor-preview]');
    const previewBox = await preview.boundingBox();
    const viewport = page.viewportSize()!;

    expect(previewBox).not.toBeNull();
    expect(previewBox!.height).toBeLessThanOrEqual(viewport.height);

    // The image scales down inside the stage rather than overflowing it.
    const stage = preview.locator('[data-image-gallery-preview-image]');
    const stageBox = await stage.boundingBox();
    expect(stageBox!.height).toBeLessThanOrEqual(previewBox!.height);
});

test('the main badge and edit button stay under the sticky modal header when scrolling', async () => {
    await page.setViewportSize({ width: 1440, height: 700 });
    const modal = await openEditor();

    const header = modal.locator('.fi-modal-header');
    const editButton = modal
        .locator('[data-image-gallery-editor-preview] button[aria-label]')
        .first();

    await expect(header).toBeVisible();
    await expect(editButton).toBeVisible();

    // Scroll the modal (the class sits on `.fi-modal-window`, which is also the
    // scroll container) until the edit button sits exactly behind the sticky
    // header - the position where the stacking bug shows up.
    const headerBox = (await header.boundingBox())!;
    const buttonBox = (await editButton.boundingBox())!;
    const delta =
        buttonBox.y +
        buttonBox.height / 2 -
        (headerBox.y + headerBox.height / 2);

    expect(delta).toBeGreaterThan(0);
    await modal.evaluate((el, by) => el.scrollBy(0, by), delta);
    await page.waitForTimeout(300);

    // Whatever is painted where the button now sits must be the header, never
    // the button or the "Main" badge bleeding over it.
    const painted = await page.evaluate(
        ({ x, y }) => {
            const el = document.elementFromPoint(x, y);

            return {
                inHeader: Boolean(el?.closest('.fi-modal-header')),
                inPreview: Boolean(
                    el?.closest('[data-image-gallery-editor-preview]'),
                ),
            };
        },
        {
            x: buttonBox.x + buttonBox.width / 2,
            y: headerBox.y + headerBox.height / 2,
        },
    );

    expect(painted.inPreview).toBe(false);
    expect(painted.inHeader).toBe(true);
});

test('expanding one tool section collapses the others', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const modal = await openEditor();

    const tools = modal.locator('.fi-image-gallery-editor-tools');
    const openSections = tools.locator(
        '.fi-section.fi-collapsible:not(.fi-collapsed)',
    );

    await expect(openSections).toHaveCount(1);

    await tools
        .locator('.fi-section.fi-collapsible')
        .filter({ hasText: 'Replace' })
        .locator('.fi-section-header')
        .click();

    await expect(openSections).toHaveCount(1);
    await expect(
        tools
            .locator('.fi-section.fi-collapsible')
            .filter({ hasText: 'Replace' }),
    ).not.toHaveClass(/fi-collapsed/);
});

/** Whether the still preview or the FilePond panel is currently showing. */
const previewState = () =>
    page.evaluate(() => ({
        preview: document.querySelector<HTMLElement>(
            '[data-image-gallery-preview-image]',
        )!.dataset.visible,
        pond: document.querySelector<HTMLElement>(
            '[data-image-gallery-file-editor]',
        )!.dataset.visible,
    }));

const openCropEditor = async (
    modal: ReturnType<Page['locator']>,
    via: 'pencil' | 'crop-section',
) => {
    if (via === 'pencil') {
        await modal
            .locator('[data-image-gallery-editor-preview] button[aria-label]')
            .first()
            .click();
    } else {
        await modal
            .locator('[data-image-gallery-crop-trigger] .fi-section-header')
            .click();
    }

    const cropEditor = page.locator('.fi-fo-file-upload-editor');
    await expect(cropEditor).toBeVisible({ timeout: 15_000 });

    return cropEditor;
};

for (const via of ['pencil', 'crop-section'] as const) {
    test(`cancelling the crop editor restores the preview (opened via ${via})`, async () => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const modal = await openEditor();

        const cropEditor = await openCropEditor(modal, via);
        expect(await previewState()).toEqual({
            preview: 'false',
            pond: 'true',
        });

        await cropEditor.getByRole('button', { name: /^cancel$/i }).click();
        await expect
            .poll(previewState)
            .toEqual({ preview: 'true', pond: 'false' });
    });
}

test('confirming a crop keeps the edited file on screen', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const modal = await openEditor();

    const cropEditor = await openCropEditor(modal, 'pencil');

    // Change the crop so FilePond produces a new file, then save. The still
    // preview would show the pre-crop image, so the FilePond panel must stay.
    await cropEditor.getByRole('button', { name: '16:9' }).click();
    await cropEditor.getByRole('button', { name: /^save$/i }).click();
    await expect(cropEditor).toBeHidden();

    expect(await previewState()).toEqual({ preview: 'false', pond: 'true' });
});

test('the crop editor still opens above the modal', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const modal = await openEditor();

    await modal
        .locator('[data-image-gallery-editor-preview] button[aria-label]')
        .first()
        .click();

    const cropEditor = page.locator('.fi-fo-file-upload-editor');
    await expect(cropEditor).toBeVisible({ timeout: 15_000 });

    const box = (await cropEditor.boundingBox())!;
    const painted = await page.evaluate(
        ({ x, y }) =>
            Boolean(
                document
                    .elementFromPoint(x, y)
                    ?.closest('.fi-fo-file-upload-editor'),
            ),
        { x: box.x + box.width / 2, y: box.y + 20 },
    );

    expect(painted).toBe(true);
});
