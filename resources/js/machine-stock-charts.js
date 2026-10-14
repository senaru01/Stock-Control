import {
    CategoryScale,
    Chart,
    Filler,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip);

/**
 * Chart tokens per theme. Stock (slot 1 blue) and Stock Prod (slot 2 orange) are the only colored series
 * (validated pair); Min / Max are neutral dashed reference lines, labeled directly, with the band between washed.
 */
const THEMES = {
    light: { stock: '#2a78d6', prod: '#eb6834', limit: '#52514e', band: 'rgba(27, 175, 122, 0.10)', surface: '#ffffff', grid: '#e1e0d9', axis: '#c3c2b7', muted: '#898781', ink: '#0b0b0b' },
    dark: { stock: '#3987e5', prod: '#d95926', limit: '#c3c2b7', band: 'rgba(25, 158, 112, 0.16)', surface: '#1d293d', grid: '#334155', axis: '#475569', muted: '#94a3b8', ink: '#f8fafc' },
};

const fullNumber = new Intl.NumberFormat('en-US');
const compactNumber = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

const currentTheme = () => (document.documentElement.dataset.theme === 'dark' ? THEMES.dark : THEMES.light);

/**
 * Writes "Min" / "Max" at the right end of the reference lines so they are never identified by color alone.
 */
const limitLabels = {
    id: 'limitLabels',
    afterDatasetsDraw(chart, args, options) {
        const { ctx, chartArea } = chart;

        chart.data.datasets.forEach((dataset, index) => {
            if (!dataset.limitLabel || !chart.isDatasetVisible(index)) {
                return;
            }

            const points = chart.getDatasetMeta(index).data;
            const last = [...points].reverse().find((point) => !point.skip);

            if (!last) {
                return;
            }

            ctx.save();
            ctx.font = '600 11px ui-sans-serif, system-ui, sans-serif';
            ctx.fillStyle = options.color;
            ctx.textBaseline = 'middle';
            ctx.fillText(dataset.limitLabel, chartArea.right + 6, last.y);
            ctx.restore();
        });
    },
};

function lineDataset(label, data, color, { showPoints }) {
    return {
        label,
        data,
        borderColor: color,
        backgroundColor: color,
        borderWidth: 2,
        tension: 0,
        spanGaps: false,
        borderJoinStyle: 'round',
        borderCapStyle: 'round',
        // A lone value between empty slots has no line segment, so it always gets a marker.
        pointRadius: (context) => {
            const values = context.dataset.data;
            const i = context.dataIndex;
            const isolated = values[i] !== null && (values[i - 1] ?? null) === null && (values[i + 1] ?? null) === null;

            return showPoints || isolated ? 4 : 0;
        },
        pointHoverRadius: 5,
        pointBorderColor: currentTheme().surface,
        pointBorderWidth: 2,
        pointHitRadius: 10,
    };
}

function limitDataset(label, data, theme, { fillToNext = false, dash }) {
    return {
        label,
        limitLabel: label,
        data,
        borderColor: theme.limit,
        backgroundColor: theme.band,
        borderWidth: 1.5,
        borderDash: dash,
        pointRadius: 0,
        pointHoverRadius: 0,
        pointHitRadius: 0,
        stepped: true,
        fill: fillToNext ? '+1' : false,
    };
}

/**
 * @param {{stock: (number|null)[], stock_prod: (number|null)[], min: (number|null)[], max: (number|null)[]}} series
 */
function buildChart(canvas, payload, series, theme) {
    const showPoints = payload.labels.length <= 12;
    const hasMax = series.max.some((value) => value !== null);

    const datasets = [
        ...(hasMax ? [limitDataset('Max', series.max, theme, { fillToNext: true, dash: [6, 4] })] : []),
        limitDataset('Min', series.min, theme, { dash: [2, 3] }),
        lineDataset('Stock', series.stock, theme.stock, { showPoints }),
        lineDataset('Stock Prod', series.stock_prod, theme.prod, { showPoints }),
    ];

    return new Chart(canvas, {
        type: 'line',
        data: { labels: payload.labels, datasets },
        plugins: [limitLabels],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            layout: { padding: { right: 34 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                limitLabels: { color: theme.muted },
                tooltip: {
                    backgroundColor: theme.ink,
                    titleColor: theme.surface,
                    bodyColor: theme.surface,
                    padding: 10,
                    boxWidth: 10,
                    boxHeight: 2,
                    filter: (item) => item.parsed.y !== null,
                    callbacks: {
                        label: (item) => ` ${item.dataset.label}: ${fullNumber.format(item.parsed.y)} pcs`,
                        labelColor: (item) => ({ borderColor: item.dataset.borderColor, backgroundColor: item.dataset.borderColor }),
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: theme.axis },
                    ticks: {
                        color: theme.muted,
                        autoSkip: false,
                        maxRotation: 0,
                        font: { size: 11 },
                        callback(value, index) {
                            const tick = payload.ticks[index];

                            if (!tick) {
                                return null;
                            }

                            // Month view on a narrow chart: keep every n-th day label so they never collide.
                            const dayTicks = payload.ticks.filter(Boolean).length;
                            const room = Math.max(1, Math.floor(this.chart.width / 30));
                            const step = dayTicks > room ? Math.ceil(dayTicks / room) : 1;
                            const dayIndex = payload.ticks.slice(0, index + 1).filter(Boolean).length - 1;

                            return step === 1 || dayIndex % step === 0 ? tick : null;
                        },
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: theme.grid, lineWidth: 1 },
                    border: { display: false },
                    ticks: { color: theme.muted, maxTicksLimit: 5, callback: (value) => compactNumber.format(value) },
                },
            },
        },
    });
}

/**
 * Alpine component: the machine total chart and one small-multiple chart per part,
 * each showing stock and stock prod against the min / max reference lines.
 *
 * @param {{labels: string[], ticks: string[], total: Object, parts: Array<Object>}} payload
 */
window.machineStockCharts = (payload) => ({
    charts: [],
    observer: null,

    init() {
        this.render();
        this.observer = new MutationObserver(() => this.render());
        this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    },

    destroy() {
        this.observer?.disconnect();
        this.charts.forEach((chart) => chart.destroy());
        this.charts = [];
    },

    render() {
        this.charts.forEach((chart) => chart.destroy());
        this.charts = [];

        const theme = currentTheme();

        if (this.$refs.total) {
            this.charts.push(buildChart(this.$refs.total, payload, payload.total, theme));
        }

        this.$root.querySelectorAll('canvas[data-part]').forEach((canvas) => {
            const part = payload.parts[Number(canvas.dataset.part)];

            if (part) {
                this.charts.push(buildChart(canvas, payload, part, theme));
            }
        });
    },
});
