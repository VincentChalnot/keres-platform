import {Chart, registerables} from 'chart.js';
import {initOpeningExplorer} from './admin/openingExplorer';
import {initBoardEditor} from './admin/boardEditor';

Chart.register(...registerables);

const probe = document.createElement('canvas').getContext('2d', {willReadFrequently: true});

/**
 * A design-system colour token (`--k-*`, a base RGB triplet or a derived CSS colour, see the design system README)
 * as an `rgb()` string Chart.js understands: the token is resolved by the browser and read back from one canvas pixel.
 */
function token(name: string): string {
    const value = getComputedStyle(document.documentElement).getPropertyValue(`--k-${name}`).trim();
    const element = document.createElement('span');
    // Base tokens are RGB triplets ("196 147 74"), derived ones full colours.
    element.style.color = /^\d+ \d+ \d+$/.test(value) ? `rgb(${value})` : value;
    document.body.appendChild(element);
    const resolved = getComputedStyle(element).color;
    element.remove();

    if (null === probe) {
        return resolved;
    }
    probe.clearRect(0, 0, 1, 1);
    probe.fillStyle = '#000';
    probe.fillStyle = resolved;
    probe.fillRect(0, 0, 1, 1);
    const [r, g, b] = probe.getImageData(0, 0, 1, 1).data;

    return `rgb(${r}, ${g}, ${b})`;
}

interface AdminStats {
    outcomes?: {win: number; lose: number; draw: number};
    moveCountDistribution?: Record<string, number>;
}

function initDashboardCharts(): void {
    const statsEl = document.getElementById('admin-stats');
    if (!statsEl) {
        return; // not on the dashboard page
    }
    const stats = JSON.parse(statsEl.textContent ?? '{}') as AdminStats;
    // Dark surfaces: Chart.js defaults (grey text, light grid) are made for white ones.
    Chart.defaults.color = token('muted');
    Chart.defaults.borderColor = token('edge');

    const outcomeCanvas = document.getElementById('admin-outcome-chart') as HTMLCanvasElement | null;
    if (outcomeCanvas && stats.outcomes) {
        new Chart(outcomeCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Wins', 'Losses', 'Draws'],
                datasets: [
                    {
                        data: [stats.outcomes.win, stats.outcomes.lose, stats.outcomes.draw],
                        backgroundColor: [token('success'), token('danger-text'), token('muted')],
                        borderColor: token('surface'),
                    },
                ],
            },
        });
    }

    const moveCountCanvas = document.getElementById('admin-move-count-chart') as HTMLCanvasElement | null;
    if (moveCountCanvas && stats.moveCountDistribution) {
        new Chart(moveCountCanvas, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.moveCountDistribution),
                datasets: [
                    {
                        label: 'Games',
                        data: Object.values(stats.moveCountDistribution),
                        backgroundColor: token('info'),
                    },
                ],
            },
            options: {
                scales: {y: {beginAtZero: true, ticks: {precision: 0}}},
            },
        });
    }
}

initDashboardCharts();
void initOpeningExplorer();
void initBoardEditor();
