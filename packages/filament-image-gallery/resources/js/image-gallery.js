export function imageGalleryEditorPreview() {
    return {
        cropTriggerHandler: null,
        editorOpenFrame: null,
        editorModal: null,
        cropOverlayObserver: null,
        isEditing: false,

        init() {
            this.editorModal = this.$el.closest('[data-fi-modal-id]');
            this.cropTriggerHandler = (event) =>
                this.handleCropTriggerClick(event);
            this.editorModal?.addEventListener(
                'click',
                this.cropTriggerHandler,
                true,
            );
        },

        handleCropTriggerClick(event) {
            const cropHeader =
                event.target instanceof Element
                    ? event.target.closest(
                          '[data-image-gallery-crop-trigger] .fi-section-header',
                      )
                    : null;

            if (!cropHeader || !this.editorModal?.contains(cropHeader)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.openFileEditor();
        },

        toggleFileEditor() {
            if (this.isEditing) {
                this.isEditing = false;
                this.cancelEditorOpenFrame();

                return;
            }

            this.openFileEditor();
        },

        openFileEditor() {
            this.isEditing = true;
            this.cancelEditorOpenFrame();
            this.$nextTick(() => this.openImageEditor());
        },

        openImageEditor(attempt = 0) {
            const editButton = this.$refs.fileEditor?.querySelector(
                '.filepond--action-edit-item:not([disabled])',
            );

            if (editButton?.getClientRects().length) {
                editButton.click();
                this.editorOpenFrame = null;
                this.watchCropOverlay();

                return;
            }

            if (attempt >= 60) {
                this.editorOpenFrame = null;

                return;
            }

            this.editorOpenFrame = requestAnimationFrame(() =>
                this.openImageEditor(attempt + 1),
            );
        },

        cancelEditorOpenFrame() {
            if (this.editorOpenFrame === null) {
                return;
            }

            cancelAnimationFrame(this.editorOpenFrame);
            this.editorOpenFrame = null;
        },

        /**
         * Filament's crop overlay closes on both cancel and confirm without
         * dispatching an event, so watch the `x-show` inline style it toggles.
         *
         * Cancelling leaves FilePond's item untouched: drop back to the still
         * preview, which is what the user was looking at before. Confirming
         * replaces the item with the edited file (the swap lands before the
         * overlay hides), and the preview `<img>` still points at the old stored
         * file until the next save, so keep the FilePond panel up instead - it is
         * the only thing showing the edit that was just made.
         */
        watchCropOverlay() {
            const overlay = this.$refs.fileEditor?.querySelector(
                '.fi-fo-file-upload-editor',
            );

            if (!overlay) {
                return;
            }

            this.disconnectCropOverlayObserver();

            const editedFileId = () =>
                this.$refs.fileEditor?.querySelector('.filepond--item')?.id ??
                null;
            const fileIdOnOpen = editedFileId();
            let hasOpened = false;

            this.cropOverlayObserver = new MutationObserver(() => {
                if (overlay.style.display !== 'none') {
                    hasOpened = true;

                    return;
                }

                if (!hasOpened) {
                    return;
                }

                this.disconnectCropOverlayObserver();

                if (editedFileId() === fileIdOnOpen) {
                    this.isEditing = false;
                }
            });

            this.cropOverlayObserver.observe(overlay, {
                attributeFilter: ['style'],
            });
        },

        disconnectCropOverlayObserver() {
            this.cropOverlayObserver?.disconnect();
            this.cropOverlayObserver = null;
        },

        destroy() {
            this.editorModal?.removeEventListener(
                'click',
                this.cropTriggerHandler,
                true,
            );
            this.cropTriggerHandler = null;
            this.editorModal = null;
            this.cancelEditorOpenFrame();
            this.disconnectCropOverlayObserver();
        },
    };
}

const positiveInteger = (value) => {
    const parsed = Number.parseInt(String(value), 10);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
};

export function imageGalleryResize({
    currentHeight,
    currentWidth,
    maxHeight,
    maxWidth,
    pendingHeight,
    pendingWidth,
}) {
    const sourceHeight = positiveInteger(currentHeight);
    const sourceWidth = positiveInteger(currentWidth);
    const isAvailable = sourceHeight !== null && sourceWidth !== null;
    const maximumHeight = isAvailable
        ? Math.min(sourceHeight, positiveInteger(maxHeight) ?? sourceHeight)
        : 0;
    const maximumWidth = isAvailable
        ? Math.min(sourceWidth, positiveInteger(maxWidth) ?? sourceWidth)
        : 0;
    const stagedWidth = positiveInteger(pendingWidth);
    const stagedHeight =
        stagedWidth === null ? positiveInteger(pendingHeight) : null;

    const dimensionsFromWidth = (value) => {
        let width = Math.min(Math.max(value, 1), maximumWidth);
        let height = Math.max(
            1,
            Math.floor((sourceHeight * width) / sourceWidth),
        );

        if (height > maximumHeight) {
            height = maximumHeight;
            width = Math.max(
                1,
                Math.floor((sourceWidth * height) / sourceHeight),
            );
        }

        return { height, width };
    };
    const dimensionsFromHeight = (value) => {
        let height = Math.min(Math.max(value, 1), maximumHeight);
        let width = Math.max(
            1,
            Math.floor((sourceWidth * height) / sourceHeight),
        );

        if (width > maximumWidth) {
            width = maximumWidth;
            height = Math.max(
                1,
                Math.floor((sourceHeight * width) / sourceWidth),
            );
        }

        return { height, width };
    };
    const initialDimensions = !isAvailable
        ? { height: '', width: '' }
        : stagedWidth !== null
          ? dimensionsFromWidth(stagedWidth)
          : stagedHeight !== null
            ? dimensionsFromHeight(stagedHeight)
            : { height: sourceHeight, width: sourceWidth };

    return {
        appliedHeight: initialDimensions.height,
        appliedWidth: initialDimensions.width,
        height: initialDimensions.height,
        isApplied: false,
        isAvailable,
        lastAxis:
            stagedWidth !== null
                ? 'width'
                : stagedHeight !== null
                  ? 'height'
                  : null,
        maximumHeight,
        maximumWidth,
        resizeHeight: stagedHeight ?? '',
        resizeWidth: stagedWidth ?? '',
        width: initialDimensions.width,

        get canApply() {
            return (
                this.isAvailable &&
                positiveInteger(this.width) !== null &&
                positiveInteger(this.height) !== null &&
                (positiveInteger(this.width) !==
                    positiveInteger(this.appliedWidth) ||
                    positiveInteger(this.height) !==
                        positiveInteger(this.appliedHeight))
            );
        },

        updateFromWidth(value) {
            queueMicrotask(() => {
                const width = positiveInteger(value);

                this.lastAxis = 'width';
                this.isApplied = false;

                if (width === null) {
                    this.width = '';

                    return;
                }

                const dimensions = dimensionsFromWidth(width);

                this.width = dimensions.width;
                this.height = dimensions.height;
            });
        },

        updateFromHeight(value) {
            queueMicrotask(() => {
                const height = positiveInteger(value);

                this.lastAxis = 'height';
                this.isApplied = false;

                if (height === null) {
                    this.height = '';

                    return;
                }

                const dimensions = dimensionsFromHeight(height);

                this.width = dimensions.width;
                this.height = dimensions.height;
            });
        },

        apply() {
            if (!this.canApply) {
                return;
            }

            const isOriginalSize =
                this.width === sourceWidth && this.height === sourceHeight;
            const axis = isOriginalSize ? null : (this.lastAxis ?? 'width');

            this.setStateInput(
                'width',
                axis === 'width' ? positiveInteger(this.width) : null,
            );
            this.setStateInput(
                'height',
                axis === 'height' ? positiveInteger(this.height) : null,
            );

            this.resizeWidth =
                axis === 'width' ? positiveInteger(this.width) : '';
            this.resizeHeight =
                axis === 'height' ? positiveInteger(this.height) : '';

            this.appliedWidth = this.width;
            this.appliedHeight = this.height;
            this.isApplied = true;
        },

        setStateInput(axis, value) {
            const input = this.$el.querySelector(
                `[data-image-gallery-resize-state="${axis}"]`,
            );

            if (!input) {
                return;
            }

            input.value = value ?? '';
            input._x_model?.set(value);
        },
    };
}

/**
 * Turns the editor's tool sections into an accordion: expanding one collapses
 * the others. Expansion is detected from the `fi-collapsed` class that Filament's
 * section binds to its own Alpine state, so it works for header clicks, the
 * chevron button, and programmatic changes alike. Collapsing a sibling only ever
 * adds `fi-collapsed`, so the observer cannot feed back into itself.
 */
export function imageGalleryToolAccordion() {
    return {
        sectionObserver: null,

        init() {
            this.sectionObserver = new MutationObserver((mutations) => {
                const expanded = mutations
                    .map((mutation) => mutation.target)
                    .find(
                        (target) =>
                            target instanceof Element &&
                            target.matches(
                                '.fi-section.fi-collapsible:not(.fi-collapsed)',
                            ),
                    );

                if (expanded) {
                    this.collapseSiblingsOf(expanded);
                }
            });

            this.sectionObserver.observe(this.$el, {
                attributeFilter: ['class'],
                subtree: true,
            });
        },

        destroy() {
            this.sectionObserver?.disconnect();
            this.sectionObserver = null;
        },

        collapseSiblingsOf(expandedSection) {
            for (const section of this.$el.querySelectorAll(
                '.fi-section.fi-collapsible:not(.fi-collapsed)',
            )) {
                const id = section.closest('.fi-sc-section')?.id;

                if (section === expandedSection || !id) {
                    continue;
                }

                window.dispatchEvent(
                    new CustomEvent('collapse-section', { detail: { id } }),
                );
            }
        },
    };
}

let editorComponentsRegistered = false;

const registerEditorComponents = () => {
    if (editorComponentsRegistered || !globalThis.Alpine) {
        return;
    }

    globalThis.Alpine.data(
        'imageGalleryEditorPreview',
        imageGalleryEditorPreview,
    );
    globalThis.Alpine.data('imageGalleryResize', imageGalleryResize);
    globalThis.Alpine.data(
        'imageGalleryToolAccordion',
        imageGalleryToolAccordion,
    );
    editorComponentsRegistered = true;
};

if (globalThis.Alpine) {
    registerEditorComponents();
} else {
    document.addEventListener('alpine:init', registerEditorComponents, {
        once: true,
    });
}

export default function imageGallery({
    clearMountedActions,
    configuredRoles,
    editableMetadataFields,
    getMountedAction,
    initialItems,
    processingMessage,
    promotePendingUploads,
    setDeferredState,
    statePath,
    submitMountedAction,
}) {
    return {
        form: null,
        editorInitialData: null,
        hasDirectUpload: false,
        items: initialItems,
        isPromoting: false,
        handleProcessingStarted: null,
        handleProcessingFinished: null,

        init() {
            this.form = this.$el.closest('form');

            this.handleProcessingStarted = () => {
                if (this.isPromoting) {
                    return;
                }

                this.hasDirectUpload = Boolean(
                    this.$el.querySelector(
                        '.fi-fo-image-gallery-uploader .filepond--item',
                    ),
                );
            };

            this.handleProcessingFinished = async () => {
                if (!this.hasDirectUpload) {
                    return;
                }

                this.hasDirectUpload = false;
                this.isPromoting = true;
                this.dispatchFormEvent('form-processing-started', {
                    message: processingMessage,
                });

                try {
                    await promotePendingUploads();
                } finally {
                    this.dispatchFormEvent('form-processing-finished');
                    this.isPromoting = false;
                }
            };

            this.form?.addEventListener(
                'form-processing-started',
                this.handleProcessingStarted,
            );
            this.form?.addEventListener(
                'form-processing-finished',
                this.handleProcessingFinished,
            );
        },

        destroy() {
            this.form?.removeEventListener(
                'form-processing-started',
                this.handleProcessingStarted,
            );
            this.form?.removeEventListener(
                'form-processing-finished',
                this.handleProcessingFinished,
            );
        },

        dispatchFormEvent(name, detail = {}) {
            this.form?.dispatchEvent(
                new CustomEvent(name, {
                    composed: true,
                    cancelable: true,
                    detail,
                }),
            );
        },

        captureEditorState() {
            queueMicrotask(() => {
                const action = getMountedAction();

                if (action?.name !== 'editImage') {
                    return;
                }

                this.editorInitialData = Object.fromEntries(
                    editableMetadataFields.map((field) => [
                        field,
                        action.data[field] ?? null,
                    ]),
                );
            });
        },

        applyEditor({
            data,
            initialPathIdentity,
            itemKey,
            modalId,
            statePath: eventStatePath,
        }) {
            if (eventStatePath !== statePath || !this.items[itemKey]) {
                return;
            }

            const modal = modalId ? document.getElementById(modalId) : null;

            const resize = modal?.querySelector('[data-image-gallery-resize]');

            if (resize) {
                data.resize_height = positiveInteger(
                    resize.dataset.resizeHeight,
                );
                data.resize_width = positiveInteger(resize.dataset.resizeWidth);
            }

            if (
                this.hasValue(data.replacement_upload) ||
                this.hasValue(data.replacement_url)
            ) {
                submitMountedAction();

                return;
            }

            if (this.fileIdentity(data.path) !== initialPathIdentity) {
                submitMountedAction();

                return;
            }

            if (!this.editorInitialData) {
                submitMountedAction();

                return;
            }

            const title = String(data.title ?? '').trim();

            if (!title) {
                document
                    .getElementById(modalId)
                    ?.querySelector('[wire\\:model$=".title"]')
                    ?.focus();

                return;
            }

            const roleNames = Object.keys(configuredRoles);
            const selectedRoles = [
                ...new Set(
                    (Array.isArray(data.roles) ? data.roles : []).filter(
                        (role) => roleNames.includes(role),
                    ),
                ),
            ];
            const nextItems = Object.fromEntries(
                Object.entries(this.items).map(([key, item]) => [
                    key,
                    {
                        ...item,
                        roles:
                            key === itemKey
                                ? selectedRoles
                                : (item.roles ?? []).filter(
                                      (role) => !selectedRoles.includes(role),
                                  ),
                    },
                ]),
            );

            const metadataUpdates = {};
            const isTitleChanged =
                title !== String(this.editorInitialData.title ?? '').trim();
            const altText = String(data.alt_text ?? '').trim();
            const isAltTextChanged =
                altText !==
                String(this.editorInitialData.alt_text ?? '').trim();

            if (isTitleChanged) {
                metadataUpdates.title = title;
            }

            if (isTitleChanged || isAltTextChanged) {
                metadataUpdates.alt_text = altText || title;
            }

            for (const field of editableMetadataFields.filter(
                (field) => !['alt_text', 'title'].includes(field),
            )) {
                const value = data[field] ?? null;

                if (value !== this.editorInitialData[field]) {
                    metadataUpdates[field] = value;
                }
            }

            nextItems[itemKey] = {
                ...nextItems[itemKey],
                ...metadataUpdates,
            };

            this.items = this.normalizeRoles(nextItems);

            for (const [key, item] of Object.entries(this.items)) {
                setDeferredState(`${statePath}.${key}.roles`, item.roles);
            }

            for (const [field, value] of Object.entries(metadataUpdates)) {
                setDeferredState(`${statePath}.${itemKey}.${field}`, value);
            }

            this.editorInitialData = null;
            clearMountedActions();

            if (modalId) {
                window.dispatchEvent(
                    new CustomEvent('close-modal-quietly', {
                        detail: { id: modalId },
                    }),
                );
            }
        },

        hasRole(itemKey, role) {
            return this.items[itemKey]?.roles?.includes(role) ?? false;
        },

        hasValue(state) {
            if (Array.isArray(state)) {
                return state.length > 0;
            }

            if (state && typeof state === 'object') {
                return Object.keys(state).length > 0;
            }

            return typeof state === 'string' && state.trim() !== '';
        },

        fileIdentity(state) {
            const value = Array.isArray(state)
                ? state[0]
                : state && typeof state === 'object'
                  ? Object.values(state)[0]
                  : state;

            return typeof value === 'string' ? value : null;
        },

        normalizeRoles(items) {
            const claimedRoles = new Set();
            const keys = Object.keys(items);

            for (const key of [...keys].reverse()) {
                items[key].roles = (items[key].roles ?? []).filter((role) => {
                    if (
                        !Object.hasOwn(configuredRoles, role) ||
                        claimedRoles.has(role)
                    ) {
                        return false;
                    }

                    claimedRoles.add(role);

                    return true;
                });
            }

            if (keys.length && !claimedRoles.has('main')) {
                items[keys[0]].roles.push('main');
            }

            return items;
        },

        roleSummary(itemKey) {
            return (this.items[itemKey]?.roles ?? [])
                .map((role) => configuredRoles[role] ?? role)
                .join(' · ');
        },
    };
}
