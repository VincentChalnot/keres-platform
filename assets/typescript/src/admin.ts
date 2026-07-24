import {Chart, registerables} from 'chart.js';
import {initOpeningExplorer} from './admin/openingExplorer';

Chart.register(...registerables);

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

    const outcomeCanvas = document.getElementById('admin-outcome-chart') as HTMLCanvasElement | null;
    if (outcomeCanvas && stats.outcomes) {
        new Chart(outcomeCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Wins', 'Losses', 'Draws'],
                datasets: [
                    {
                        data: [stats.outcomes.win, stats.outcomes.lose, stats.outcomes.draw],
                        backgroundColor: ['#48c78e', '#f14668', '#b5b5b5'],
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
                        backgroundColor: '#3e8ed0',
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
