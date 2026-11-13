(() => {
    'use strict';
    const canvas = document.getElementById('lombard-chart');
    const source = document.getElementById('lombard-chart-data');
    if (!canvas || !source || typeof Chart === 'undefined') return;
    let rows;
    try { rows = JSON.parse(source.textContent); } catch (_) { return; }
    const value = (key) => rows.map((row) => row[key]);
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: value('date'),
            datasets: [
                { label: 'Utilizzo stimato (EUR)', data: value('usage'), yAxisID: 'y', borderColor: '#087f8c', backgroundColor: 'rgba(8,127,140,.12)', fill: true, tension: 0, spanGaps: false, pointRadius: 1.5 },
                { label: 'Plafond (EUR)', data: value('limit'), yAxisID: 'y', borderColor: '#bc3c36', borderDash: [6, 5], pointRadius: 0, tension: 0, spanGaps: false },
                { label: 'Interessi cumulati osservati (EUR)', data: value('interest'), yAxisID: 'interest', borderColor: '#b66b16', backgroundColor: '#b66b16', pointRadius: 1, tension: 0, spanGaps: false },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Utilizzo / plafond EUR' } },
                interest: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Interessi osservati EUR' } },
            },
        },
    });
})();
