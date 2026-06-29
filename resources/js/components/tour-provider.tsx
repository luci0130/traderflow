import { router, usePage } from '@inertiajs/react';
import { driver } from 'driver.js';
import { createContext, useCallback, useContext, useEffect } from 'react';
import type { ReactNode } from 'react';
import { tours } from '@/tutorials/tours';

type TourContextValue = {
    /** Start a tour by key, regardless of whether it was completed before. */
    startTour: (key: string) => void;
};

const TourContext = createContext<TourContextValue | null>(null);

export function TourProvider({ children }: { children: ReactNode }) {
    const startTour = useCallback((key: string) => {
        const tour = tours[key];

        if (!tour) {
            return;
        }

        const driverObj = driver({
            showProgress: true,
            nextBtnText: 'Pasul următor',
            prevBtnText: 'Înapoi',
            doneBtnText: 'Gata',
            steps: tour.steps,
            // Fires both when the user finishes and when they skip/close, so
            // either way the tour is marked as seen and won't auto-start again.
            onDestroyed: () => {
                router.post(
                    '/tutorials/complete',
                    { key },
                    { preserveScroll: true, preserveState: true },
                );
            },
        });

        driverObj.drive();
    }, []);

    return (
        <TourContext.Provider value={{ startTour }}>
            {children}
        </TourContext.Provider>
    );
}

export function useTour(): TourContextValue {
    const context = useContext(TourContext);

    if (!context) {
        throw new Error('useTour must be used within a TourProvider');
    }

    return context;
}

/**
 * Auto-start a tour the first time the user lands on a page, unless they have
 * already completed (or skipped) it. Call this once from a page component.
 */
export function useAutoTour(key: string): void {
    const { startTour } = useTour();
    const { completedTutorials } = usePage().props;

    useEffect(() => {
        if (completedTutorials.includes(key)) {
            return;
        }

        // Delay so the target elements are mounted before highlighting.
        const timeout = window.setTimeout(() => startTour(key), 400);

        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
}
