import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement, Tooltip);

/**
 * Categorical palette in fixed order (validated for adjacent stacked segments in both themes),
 * a neutral for the folded "Lainnya" series, and ink for the total line and its labels.
 */
const THEMES = {
    light: {
        slots: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
        other: '#b5b4ad', total: '#52514e', surface: '#ffffff', grid: '#e1e0d9', axis: '#c3c2b7', muted: '#898781', ink: '#0b0b0b',
    },
    dark: {
        slots: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
        other: '#64748b', total: '#c3c2b7', surface: '#1d293d', grid: '#334155', axis: '#475569', muted: '#94a3b8', ink: '#f8fafc',
    },
};

const fullNumber = new Intl.NumberFormat('en-US');

const currentTheme = () => (document.documentElement.dataset.theme === 'dark' ? THEMES.dark : THEMES.light);

/**
 * Writes each day's total above its point, like the data labels of the Excel chart.
 */
const totalLabels = {
    id: 'totalLabels',
    afterDatasetsDraw(chart, args, options) {
        const index = chart.data.datasets.findIndex((dataset) => dataset.isTotal);

        if (index < 0 || !chart.isDatasetVisible(index)) {
            return;
        }

        const { ctx } = chart;
        ctx.save();
        ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
        ctx.fillStyle = options.color;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';

        chart.getDatasetMeta(index).data.forEach((point, i) => {
            const value = chart.data.datasets[index].data[i];

            if (value !== null && value !== undefined && !point.skip) {
                ctx.fillText(fullNumber.format(value), point.x, point.y - 6);
            }
        });

        ctx.restore();
    },
};

function buildChart(canvas, chartData, theme) {
    const colorOf = (series) => (series.slot === null ? theme.other : theme.slots[series.slot % theme.slots.length]);

    const bars = chartData.series.map((series) => ({
        type: 'bar',
        label: series.label,
        data: series.data,
        backgroundColor: colorOf(series),
        borderColor: theme.surface,
        borderWidth: { top: 1 },
        borderSkipped: 'bottom',
        stack: 'resume',
        maxBarThickness: 28,
        order: 2,
    }));

    const total = {
        type: 'line',
        label: 'TOTAL',
        isTotal: true,
        data: chartData.total,
        borderColor: theme.total,
        backgroundColor: theme.total,
        borderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBorderColor: theme.surface,
        pointBorderWidth: 1.5,
        // Days without a recorded stock (holidays) are bridged so the total line never breaks.
        spanGaps: true,
        tension: 0,
        order: 1,
    };

    return new Chart(canvas, {
        data: { labels: chartData.labels, datasets: [...bars, total] },
        plugins: [totalLabels],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            layout: { padding: { top: 18 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                totalLabels: { color: theme.ink },
                tooltip: {
                    backgroundColor: theme.ink,
                    titleColor: theme.surface,
                    bodyColor: theme.surface,
                    padding: 10,
                    boxWidth: 10,
                    boxHeight: 10,
                    filter: (item) => item.parsed.y !== null && (item.dataset.isTotal || item.parsed.y > 0),
                    itemSort: (a, b) => (a.dataset.isTotal ? -1 : b.dataset.isTotal ? 1 : b.parsed.y - a.parsed.y),
                    callbacks: {
                        title: (items) => `Tanggal ${items[0].label}`,
                        label: (item) => ` ${item.dataset.label}: ${fullNumber.format(item.parsed.y)} ${chartData.unit.toLowerCase()}`,
                        labelColor: (item) => ({ borderColor: item.dataset.backgroundColor, backgroundColor: item.dataset.backgroundColor }),
                    },
                },
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    border: { color: theme.axis },
                    ticks: { color: theme.muted, autoSkip: false, maxRotation: 0, font: { size: 11 } },
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    title: { display: true, text: chartData.unit, color: theme.muted },
                    grid: { color: theme.grid, lineWidth: 1 },
                    border: { display: false },
                    ticks: { color: theme.muted, maxTicksLimit: 6, precision: 0 },
                },
            },
        },
    });
}

/**
 * Alpine component: the monthly resume charts of the Daily Stock dashboard (one per coverage threshold),
 * stacked per last process (or PG machine) with the total as a labelled line.
 *
 * @param {Array<{threshold: string, labels: string[], unit: string, series: Array<{label: string, slot: ?number, data: (?number)[]}>, total: (?number)[]}>} charts
 */
window.dailyStockResumeCharts = (charts) => ({
    instances: [],
    observer: null,

    init() {
        this.render();
        this.observer = new MutationObserver(() => this.render());
        this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    },

    destroy() {
        this.observer?.disconnect();
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
    },

    render() {
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];

        const theme = currentTheme();

        this.$root.querySelectorAll('canvas[data-threshold]').forEach((canvas) => {
            const chartData = charts.find((chart) => chart.threshold === canvas.dataset.threshold);

            if (chartData) {
                this.instances.push(buildChart(canvas, chartData, theme));
            }
        });

        // Legend swatches follow the same theme colours as the bars.
        this.$root.querySelectorAll('[data-swatch]').forEach((swatch) => {
            const slot = swatch.dataset.swatch;
            swatch.style.backgroundColor = slot === 'total' ? theme.total : slot === 'other' ? theme.other : theme.slots[Number(slot) % theme.slots.length];
        });
    },
});
