import type { DriveStep } from 'driver.js';

/**
 * A guided tutorial: an ordered list of Driver.js steps. Each step targets an
 * element through a stable `[data-tour="..."]` selector (never a Tailwind class,
 * which can change) and shows a popover with a title and description.
 */
export type Tour = {
    steps: DriveStep[];
};

/**
 * All React/Inertia tours, keyed by a stable tour key. The same key is the
 * value persisted in `users.tutorial_preferences` so a tour only auto-starts
 * the first time a user sees it.
 */
export const tours: Record<string, Tour> = {
    dashboard_intro: {
        steps: [
            {
                element: '[data-tour="dashboard-stats"]',
                popover: {
                    title: 'Statistici rapide',
                    description:
                        'Aici vezi dintr-o privire indicatorii principali ai activității tale.',
                    side: 'bottom',
                    align: 'start',
                },
            },
            {
                element: '[data-tour="dashboard-main"]',
                popover: {
                    title: 'Zona de lucru',
                    description:
                        'Conținutul principal al paginii apare aici. Aici vei petrece cea mai mare parte a timpului.',
                    side: 'top',
                    align: 'start',
                },
            },
            {
                element: '[data-tour="help-button"]',
                popover: {
                    title: 'Reia turul oricând',
                    description:
                        'Apasă acest buton ca să pornești din nou tutorialul când ai nevoie.',
                    side: 'left',
                    align: 'start',
                },
            },
        ],
    },
};

export type TourKey = keyof typeof tours;
