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
 * Chart tokens per theme (validated: series blue clears 3:1 on both card surfaces).
 * Text uses ink tokens, never the series color.
 */
const THEMES = {
    light: { series: '#2a78d6', wash: 'rgba(42, 120, 214, 0.10)', surface: '#ffffff', grid: '#e1e0d9', axis: '#c3c2b7', muted: '#898781', ink: '#0b0b0b' },
    dark: { series: '#3987e5', wash: 'rgba(57, 135, 229, 0.14)', surface: '#1d293d', grid: '#334155', axis: '#475569', muted: '#94a3b8', ink: '#f8fafc' },
};

const fullNumber = new Intl.NumberFormat('en-US');
const compactNumber = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

const currentTheme = () => (document.documentElement.dataset.theme === 'dark' ? THEMES.dark : THEMES.light);

function baseOptions(theme, { beginAtZero }) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: theme.ink,
                titleColor: theme.surface,
                bodyColor: theme.surface,
                displayColors: false,
                padding: 10,
                callbacks: { label: (item) => `${fullNumber.format(item.parsed.y)} pcs` },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { color: theme.axis },
                ticks: { color: theme.muted, maxRotation: 0, autoSkipPadding: 12 },
            },
            y: {
                beginAtZero,
                grid: { color: theme.grid, lineWidth: 1 },
                border: { display: false },
                ticks: { color: theme.muted, maxTicksLimit: 5, callback: (value) => compactNumber.format(value) },
            },
        },
    };
}

/**
 * Alpine component: one line chart for total stock and one line chart per store (small multiples).
 *
 * @param {{labels: string[], total: number[], stores: Object<string, (number|null)[]>}} payload
 */
window.stockHistoryCharts = (payload) => ({
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
        const showPoints = payload.labels.length <= 40;

        const totalCanvas = this.$refs.total;
        if (totalCanvas) {
            this.charts.push(new Chart(totalCanvas, {
                type: 'line',
                data: {
                    labels: payload.labels,
                    datasets: [{
                        data: payload.total,
                        borderColor: theme.series,
                        backgroundColor: theme.wash,
                        borderWidth: 2,
                        fill: true,
                        tension: 0,
                        borderJoinStyle: 'round',
                        borderCapStyle: 'round',
                        pointRadius: showPoints ? 4 : 0,
                        pointHoverRadius: 6,
                        pointBackgroundColor: theme.series,
                        pointBorderColor: theme.surface,
                        pointBorderWidth: 2,
                        pointHitRadius: 12,
                    }],
                },
                options: baseOptions(theme, { beginAtZero: false }),
            }));
        }

        this.$root.querySelectorAll('canvas[data-store]').forEach((canvas) => {
            const values = payload.stores[canvas.dataset.store] ?? [];

            this.charts.push(new Chart(canvas, {
                type: 'line',
                data: {
                    labels: payload.labels,
                    datasets: [{
                        data: values,
                        borderColor: theme.series,
                        backgroundColor: theme.wash,
                        borderWidth: 2,
                        fill: true,
                        tension: 0,
                        spanGaps: true,
                        borderJoinStyle: 'round',
                        borderCapStyle: 'round',
                        pointRadius: showPoints ? 3 : 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: theme.series,
                        pointBorderColor: theme.surface,
                        pointBorderWidth: 2,
                        pointHitRadius: 12,
                    }],
                },
                options: baseOptions(theme, { beginAtZero: false }),
            }));
        });
    },
});
