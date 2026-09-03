(() => {
    'use strict';
    const button = document.getElementById('print-report');
    const status = document.getElementById('print-status');
    const charts = [];
    const date = new Intl.DateTimeFormat('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone: 'UTC' });
    const money = new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 });
    window.accountHistoryPrintReady = (async () => {
        try {
            await document.fonts.ready;
            const data = JSON.parse(document.getElementById('report-chart-data').textContent);
            if (Object.keys(data).length && typeof Chart === 'undefined') throw new Error('Grafici non caricati');
            for (const [id, datasets] of Object.entries(data)) {
                const firstDate = datasets[0].data[0].x;
                const lastDate = datasets[0].data.at(-1).x;
                const singleDate = firstDate === lastDate;
                const chart = new Chart(document.getElementById(`chart-${id}`), {
                    type: 'line',
                    data: { datasets: datasets.map(series => ({ ...series, borderWidth: 2, pointRadius: series.data.length > 90 ? 0 : 2, pointHoverRadius: 4, spanGaps: false, tension: 0, fill: false })) },
                    options: {
                        responsive: true, maintainAspectRatio: false, animation: false,
                        normalized: true,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 16, boxHeight: 3, font: { size: 10 }, color: '#173044' } } },
                        scales: {
                            x: {
                                type: 'linear', min: singleDate ? firstDate - 43200000 : firstDate, max: singleDate ? lastDate + 43200000 : lastDate,
                                afterBuildTicks: scale => { if (singleDate) scale.ticks = [{ value: firstDate }]; },
                                grid: { display: false }, ticks: { maxTicksLimit: 6, maxRotation: 0, font: { size: 9 }, callback: value => date.format(new Date(value)) }
                            },
                            y: { ticks: { maxTicksLimit: 6, font: { size: 9 }, callback: value => money.format(value) }, grid: { color: '#e1e8ee' } }
                        }
                    }
                });
                charts.push(chart);
            }
            button.disabled = false;
            status.textContent = 'Grafici pronti per la stampa.';
            document.body.dataset.printReady = 'true';
            return true;
        } catch (error) {
            status.textContent = 'Grafici non disponibili. Ricarica la pagina prima di stampare.';
            document.body.dataset.printReady = 'error';
            return false;
        }
    })();
    const resize = () => charts.forEach(chart => chart.resize());
    window.addEventListener('beforeprint', resize);
    window.addEventListener('afterprint', resize);
    button.addEventListener('click', async () => {
        if (await window.accountHistoryPrintReady) window.print();
    });
})();
