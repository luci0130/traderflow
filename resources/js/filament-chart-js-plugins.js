// Chart.js plugins for Filament chart widgets. Filament reads
// `window.filamentChartJsPlugins` when it constructs each chart, so plugins
// pushed here apply to the supermarket margin scatter chart (and any future
// chart widget).
import zoomPlugin from 'chartjs-plugin-zoom'

// Draws a text label (the supermarket name) above or below each point of any
// dataset that carries a `pointLabels` array, coloured to match the dataset.
const pointLabelsPlugin = {
    id: 'supermarketPointLabels',
    afterDatasetsDraw(chart) {
        const ctx = chart.ctx

        chart.data.datasets.forEach((dataset, datasetIndex) => {
            if (!Array.isArray(dataset.pointLabels)) {
                return
            }

            const meta = chart.getDatasetMeta(datasetIndex)
            if (meta.hidden) {
                return
            }

            const below = dataset.labelPosition === 'below'

            meta.data.forEach((point, index) => {
                const label = dataset.pointLabels[index]
                if (!label) {
                    return
                }

                ctx.save()
                ctx.font =
                    '600 11px ' +
                    (getComputedStyle(chart.canvas).fontFamily || 'sans-serif')
                ctx.fillStyle = dataset.borderColor || '#374151'
                ctx.textAlign = 'center'
                ctx.textBaseline = below ? 'top' : 'bottom'
                ctx.fillText(label, point.x, point.y + (below ? 9 : -9))
                ctx.restore()
            })
        })
    },
}

// Pins the time x-axis to the `xMin`/`xMax` carried on `chart.data` (the first and
// last decision in the selected period). Applied only when that range changes — i.e.
// on a filter/data update — so it never clobbers the user's wheel/drag zoom, which
// updates the chart without changing the data's own range.
const timeRangePlugin = {
    id: 'timeRange',
    beforeUpdate(chart) {
        const xScale = chart.options.scales?.x
        if (!xScale) {
            return
        }

        const min = chart.data.xMin ?? null
        const max = chart.data.xMax ?? null
        const key = `${min}|${max}`

        if (chart.$appliedTimeRangeKey === key) {
            return
        }

        chart.$appliedTimeRangeKey = key
        xScale.min = min ?? undefined
        xScale.max = max ?? undefined
    },
}

// Double-clicking the chart resets any time-axis zoom/pan back to the full range.
const resetZoomOnDoubleClick = {
    id: 'resetZoomOnDoubleClick',
    afterInit(chart) {
        if (typeof chart.resetZoom !== 'function') {
            return
        }

        chart.canvas.addEventListener('dblclick', () => chart.resetZoom())
    },
}

window.filamentChartJsPlugins ??= []
window.filamentChartJsPlugins.push(
    zoomPlugin,
    pointLabelsPlugin,
    timeRangePlugin,
    resetZoomOnDoubleClick,
)
