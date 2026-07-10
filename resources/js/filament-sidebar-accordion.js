// Turns the admin sidebar navigation groups into an accordion: opening one
// group collapses every other group, so only a single group is expanded at a
// time. Filament collapses groups independently by default, so we wrap the
// sidebar store's `toggleCollapsedGroup` to enforce the single-open behaviour.
//
// Only the main sidebar groups take part in the accordion. Any other collapsed
// group (e.g. a page's sub-navigation) keeps Filament's default toggle so its
// state is left untouched.

const mainSidebarGroupLabels = () =>
    Array.from(
        document.querySelectorAll(
            '.fi-main-sidebar .fi-sidebar-group[data-group-label]',
        ),
    ).map((element) => element.dataset.groupLabel)

const enforceAccordion = (store) => {
    if (!store || store.__accordionPatched) {
        return
    }

    store.__accordionPatched = true

    store.toggleCollapsedGroup = function (group) {
        const groupLabels = mainSidebarGroupLabels()
        const isCurrentlyCollapsed = this.collapsedGroups.includes(group)

        // Closing a group, or toggling something outside the main sidebar,
        // keeps Filament's default independent behaviour.
        if (!groupLabels.includes(group) || !isCurrentlyCollapsed) {
            this.collapsedGroups = isCurrentlyCollapsed
                ? this.collapsedGroups.filter((label) => label !== group)
                : this.collapsedGroups.concat(group)

            return
        }

        // Opening a main sidebar group: collapse every other main group while
        // preserving any unrelated collapsed groups.
        this.collapsedGroups = [
            ...this.collapsedGroups.filter(
                (label) => !groupLabels.includes(label),
            ),
            ...groupLabels.filter((label) => label !== group),
        ]
    }
}

document.addEventListener('alpine:initialized', () => {
    enforceAccordion(window.Alpine?.store('sidebar'))
})
