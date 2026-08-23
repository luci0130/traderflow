export function imageGalleryFocalPointPreview({ focalX = 50, focalY = 50 }) {
    return {
        boundFocalSliders: new WeakSet(),
        focalSliderObserver: null,
        focalX: Number(focalX ?? 50),
        focalY: Number(focalY ?? 50),
        isEditing: false,
        isFocalPreviewActive: false,

        init() {
            const modal = this.$el.closest('[data-fi-modal-id]');

            if (!modal) {
                return;
            }

            const bindSliders = () => {
                modal
                    .querySelectorAll('[data-image-gallery-focal-axis]')
                    .forEach((sliderElement) => {
                        if (
                            !sliderElement.noUiSlider ||
                            this.boundFocalSliders.has(sliderElement)
                        ) {
                            return;
                        }

                        this.boundFocalSliders.add(sliderElement);
                        sliderElement.noUiSlider.on('slide', (values) => {
                            this.updateFocalPoint(
                                sliderElement.dataset.imageGalleryFocalAxis,
                                values[0],
                            );
                        });
                    });
            };

            this.focalSliderObserver = new MutationObserver(bindSliders);
            this.focalSliderObserver.observe(modal, {
                childList: true,
                subtree: true,
            });
            queueMicrotask(bindSliders);
        },

        destroy() {
            this.focalSliderObserver?.disconnect();
            this.focalSliderObserver = null;
        },

        focalMarkerStyle() {
            return {
                left: `${this.focalX}%`,
                top: `${this.focalY}%`,
            };
        },

        previewImageStyle() {
            return {
                objectPosition: `${this.focalX}% ${this.focalY}%`,
            };
        },

        toggleFileEditor() {
            this.isEditing = !this.isEditing;
        },

        updateFocalPoint(axis, value) {
            const percentage = Math.min(100, Math.max(0, Number(value)));

            if (!Number.isFinite(percentage)) {
                return;
            }

            if (axis === 'x') {
                this.focalX = percentage;
            } else if (axis === 'y') {
                this.focalY = percentage;
            } else {
                return;
            }

            this.isFocalPreviewActive = true;
        },
    };
}

globalThis.Alpine?.data(
    'imageGalleryFocalPointPreview',
    imageGalleryFocalPointPreview,
);
