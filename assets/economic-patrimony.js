(() => {
    const euro = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
    const exact = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });

    for (const source of document.querySelectorAll('[data-economic-chart]')) {
        const model = JSON.parse(source.textContent);
        const canvas = document.getElementById(source.dataset.economicChart);
        if (!canvas || !window.Chart) continue;
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: model.dates,
                datasets: model.series.map((series) => ({ pointRadius: 2, borderWidth: 2, tension: .18, spanGaps: false, ...series })),
            },
            options: {
                responsive: true,
                animation: false,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { tooltip: { callbacks: { label: (item) => `${item.dataset.label}: ${exact.format(item.parsed.y)}` } } },
                scales: { x: { ticks: { maxTicksLimit: 9 } }, y: { ticks: { callback: (value) => euro.format(value) } } },
            },
        });
    }
})();
