// Guided, multi-page tutorials (Driver.js) for the Filament admin panel.
//
// Registered as a Filament asset in AppServiceProvider. The backend exposes the
// user's completed tutorials and the persistence endpoint via Filament's script
// data (`window.filamentData.tutorials`). The list of tutorials a user may
// start (and the restart menu) is built server-side from App\Support\
// TutorialCatalog — the tour keys here must match the keys there.
//
// Each tour is an ordered list of steps. A step is tied to a page via `path`;
// `advanceByClick` marks the step whose highlighted element (e.g. a "New"
// button) navigates to the next page. Progress is kept in sessionStorage so a
// tour resumes seamlessly across Livewire SPA navigations.
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

const config = () =>
    window.filamentData?.tutorials ?? {
        completed: [],
        completeUrl: '',
        csrf: '',
    };

// Tours already seen this session, seeded from the server-rendered list. Used to
// avoid re-running the auto-start onboarding tour after it has been completed.
const seen = new Set(config().completed);

/** Build the standard "list → New → fill form → save" flow for a resource. */
function crudSteps({
    listPath,
    navTitle,
    navDesc,
    newDesc,
    formDesc,
    saveDesc,
}) {
    return [
        {
            path: listPath,
            element: `.fi-sidebar a[href$="${listPath}"]`,
            popover: {
                title: navTitle,
                description: navDesc,
                side: 'right',
                align: 'start',
            },
        },
        {
            path: listPath,
            element: `a[href$="${listPath}/create"]`,
            advanceByClick: true,
            popover: {
                title: 'Creează o înregistrare nouă',
                description: newDesc,
                side: 'bottom',
                align: 'start',
            },
        },
        {
            path: `${listPath}/create`,
            element: '.fi-sc-form',
            popover: {
                title: 'Completează formularul',
                description: formDesc,
                side: 'top',
                align: 'start',
            },
        },
        {
            path: `${listPath}/create`,
            element: '.fi-sc-actions',
            popover: {
                title: 'Salvează',
                description: saveDesc,
                side: 'top',
                align: 'start',
            },
        },
    ];
}

const TOURS = {
    admin_welcome: [
        {
            path: '/',
            element: '.fi-sidebar',
            popover: {
                title: 'Meniul principal',
                description:
                    'De aici navighezi între module: catalog, entități, vânzări, achiziții și rapoarte.',
                side: 'right',
                align: 'start',
            },
        },
        {
            path: '/',
            element: '.fi-topbar',
            popover: {
                title: 'Bara de sus',
                description:
                    'Aici găsești căutarea globală, comutarea de tenant și meniul contului tău.',
                side: 'bottom',
                align: 'center',
            },
        },
        {
            path: '/',
            element: '.fi-main',
            popover: {
                title: 'Zona de lucru',
                description:
                    'Conținutul paginii curente apare aici. Reia oricând tururile din butonul de ajutor (semnul întrebării) din bara de sus.',
                side: 'top',
                align: 'center',
            },
        },
    ],
    add_customer: crudSteps({
        listPath: '/customers',
        navTitle: 'Secțiunea Clienți',
        navDesc: 'Aici găsești și gestionezi toți clienții.',
        newDesc: 'Apasă „Nou" pentru a deschide formularul de client nou.',
        formDesc:
            'Completează numele, persoana de contact, adresa și datele de facturare.',
        saveDesc: 'Apasă „Creează" pentru a salva clientul.',
    }),
    create_customer_offer: crudSteps({
        listPath: '/customer-offers',
        navTitle: 'Oferte pentru clienți',
        navDesc: 'Aici creezi și urmărești ofertele trimise clienților.',
        newDesc: 'Apasă „Nou" pentru a începe o ofertă nouă.',
        formDesc:
            'Alege clientul, adaugă produsele și cantitățile, apoi completează prețurile.',
        saveDesc: 'Apasă „Creează" pentru a salva oferta.',
    }),
    add_supplier: crudSteps({
        listPath: '/suppliers',
        navTitle: 'Secțiunea Furnizori',
        navDesc: 'Aici găsești și gestionezi furnizorii.',
        newDesc: 'Apasă „Nou" pentru a adăuga un furnizor.',
        formDesc:
            'Completează denumirea, datele de contact și condițiile comerciale.',
        saveDesc: 'Apasă „Creează" pentru a salva furnizorul.',
    }),
    create_supplier_offer: crudSteps({
        listPath: '/supplier-offers',
        navTitle: 'Oferte de la furnizori',
        navDesc: 'Aici înregistrezi ofertele primite de la furnizori.',
        newDesc: 'Apasă „Nou" pentru a adăuga o ofertă de furnizor.',
        formDesc: 'Alege furnizorul și adaugă produsele cu prețurile oferite.',
        saveDesc: 'Apasă „Creează" pentru a salva oferta.',
    }),
    add_supplier_product: crudSteps({
        listPath: '/supplier-products',
        navTitle: 'Produse furnizori',
        navDesc: 'Aici gestionezi produsele oferite de furnizori.',
        newDesc: 'Apasă „Nou" pentru a adăuga un produs de furnizor.',
        formDesc:
            'Alege furnizorul și produsul din catalog, apoi completează prețul și ambalarea.',
        saveDesc: 'Apasă „Creează" pentru a salva produsul.',
    }),
    add_product: crudSteps({
        listPath: '/products',
        navTitle: 'Catalog produse',
        navDesc: 'Catalogul central de produse al aplicației.',
        newDesc: 'Apasă „Nou" pentru a adăuga un produs în catalog.',
        formDesc:
            'Completează denumirea, categoria, unitatea de măsură și detaliile produsului.',
        saveDesc: 'Apasă „Creează" pentru a salva produsul.',
    }),
};

// --- Persistence ---------------------------------------------------------

function markComplete(key) {
    seen.add(key);

    const { completeUrl, csrf } = config();

    if (!completeUrl) {
        return;
    }

    fetch(completeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ key }),
    });
}

// --- Active-tour state (survives SPA navigation) -------------------------

const ACTIVE_KEY = 'tf_tour_active';

function getActive() {
    try {
        return JSON.parse(sessionStorage.getItem(ACTIVE_KEY));
    } catch {
        return null;
    }
}

function setActive(key, index) {
    sessionStorage.setItem(ACTIVE_KEY, JSON.stringify({ key, index }));
}

function clearActive() {
    sessionStorage.removeItem(ACTIVE_KEY);
}

// --- Navigation helpers --------------------------------------------------

const norm = (path) => {
    const stripped = (path || '').replace(/\/+$/, '');

    return stripped === '' ? '/' : stripped;
};

const currentPath = () => norm(window.location.pathname);

function navigate(path) {
    const url = new URL(path, window.location.origin).href;

    if (window.Livewire && typeof window.Livewire.navigate === 'function') {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
}

function waitForElement(selector, callback, tries = 20) {
    if (!selector || document.querySelector(selector)) {
        callback(true);

        return;
    }

    if (tries <= 0) {
        callback(false);

        return;
    }

    window.setTimeout(() => waitForElement(selector, callback, tries - 1), 150);
}

// --- Tour engine ---------------------------------------------------------

let currentDriver = null;
// Set while we tear the driver down ourselves (navigation/advance) so the
// onDestroyed handler does not treat it as the user abandoning the tour.
let suppressDestroyHandling = false;

function driveBlock(key, startIndex) {
    const steps = TOURS[key];
    const path = currentPath();

    // The contiguous run of steps that live on the current page.
    let end = startIndex;

    while (end + 1 < steps.length && norm(steps[end + 1].path) === path) {
        end += 1;
    }

    const hasMore = end < steps.length - 1;
    const lastStep = steps[end];

    const blockSteps = steps
        .slice(startIndex, end + 1)
        .filter((step) => !step.element || document.querySelector(step.element))
        .map((step) => ({ element: step.element, popover: step.popover }));

    if (blockSteps.length === 0) {
        // Nothing on this page (e.g. the user lacks the button). Skip ahead.
        if (hasMore) {
            setActive(key, end + 1);
            navigate(steps[end + 1].path);
        } else {
            markComplete(key);
            clearActive();
        }

        return;
    }

    let advancing = false;
    let finished = false;

    const advance = (element, step, { driver: api }) => {
        if (!api.isLastStep()) {
            api.moveNext();

            return;
        }

        if (!hasMore) {
            finished = true;
            api.destroy();

            return;
        }

        advancing = true;
        setActive(key, end + 1);
        api.destroy();

        const target = document.querySelector(lastStep.element);

        if (lastStep.advanceByClick && target) {
            target.click();
        } else {
            navigate(steps[end + 1].path);
        }
    };

    currentDriver = driver({
        showProgress: true,
        allowClose: true,
        nextBtnText: 'Pasul următor',
        prevBtnText: 'Înapoi',
        doneBtnText: hasMore ? 'Continuă' : 'Gata',
        steps: blockSteps,
        onNextClick: advance,
        onDoneClick: advance,
        onHighlighted: (element, step, { driver: api }) => {
            // If the last step on this page navigates onward, let a direct click
            // on the highlighted element advance the tour too (not just "Continuă").
            if (
                hasMore &&
                lastStep.advanceByClick &&
                api.isLastStep() &&
                element
            ) {
                element.addEventListener(
                    'click',
                    () => {
                        if (advancing) {
                            return;
                        }

                        advancing = true;
                        setActive(key, end + 1);
                        api.destroy();
                    },
                    { once: true },
                );
            }
        },
        onDestroyed: () => {
            currentDriver = null;

            if (suppressDestroyHandling || advancing) {
                return;
            }

            if (finished) {
                markComplete(key);
            }

            clearActive();
        },
    });

    currentDriver.drive();
}

function resumeActive() {
    const active = getActive();

    if (!active) {
        return;
    }

    const steps = TOURS[active.key];
    const step = steps?.[active.index];

    if (!step) {
        clearActive();

        return;
    }

    // Only drive once the user is actually on the step's page; otherwise wait
    // (resume fires again after the next navigation) instead of hijacking them.
    if (norm(step.path) !== currentPath()) {
        return;
    }

    waitForElement(step.element, () => driveBlock(active.key, active.index));
}

export function startTour(key) {
    const steps = TOURS[key];

    if (!steps) {
        return;
    }

    setActive(key, 0);

    if (norm(steps[0].path) !== currentPath()) {
        navigate(steps[0].path);

        return;
    }

    resumeActive();
}

function maybeAutoStart() {
    const key = 'admin_welcome';

    if (seen.has(key) || norm(TOURS[key][0].path) !== currentPath()) {
        return;
    }

    window.setTimeout(() => startTour(key), 600);
}

function onPageReady() {
    if (getActive()) {
        resumeActive();

        return;
    }

    maybeAutoStart();
}

document.addEventListener('DOMContentLoaded', onPageReady);
document.addEventListener('livewire:navigated', onPageReady);

// Tear down a running tour before an SPA navigation we did not initiate, so the
// overlay never leaks onto the next page. The tour stays "active" and resumes
// if the user returns to its page.
document.addEventListener('livewire:navigating', () => {
    if (currentDriver && currentDriver.isActive()) {
        suppressDestroyHandling = true;
        currentDriver.destroy();
        suppressDestroyHandling = false;
    }
});

// Exposed for the restart menu (resources/views/filament/tutorials-menu.blade.php).
window.startTutorial = startTour;
