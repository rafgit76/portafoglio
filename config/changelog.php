<?php

return [
    [
        'version' => '1.2.0',
        'date' => '2026-05-11',
        'title' => 'Versioning, changelog ed export andamento',
        'changes' => [
            'Aggiunta pagina Changelog interna con storico release.',
            'Aggiunta versione applicativa centralizzata e visibile nel footer.',
            'Aggiunti link Changelog nel footer e nel menu principale.',
            'Aggiunti export Excel e stampa PDF elegante nella pagina Andamento.',
        ],
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-05-05',
        'title' => 'Report AI',
        'changes' => [
            'Aggiunta pagina Report AI basata su Gemini 2.5 Flash.',
            'Aggiunta rigenerazione del report in base agli snapshot archiviati.',
            'Aggiunta versione stampabile del report AI.',
            'Aggiunta cache del report AI in storage per evitare chiamate inutili.',
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-05-05',
        'title' => 'Versione stabile iniziale',
        'changes' => [
            'Import snapshot da file Excel Fineco con parser basato su PhpSpreadsheet.',
            'Dashboard con valori principali, andamento storico e separazione dei grafici.',
            'Storico snapshot, dettaglio snapshot e confronto tra snapshot.',
            'Pagina Andamento per titolo con evidenziazione minimi e massimi storici.',
            'Anagrafica strumenti e gestione dati principali di portafoglio.',
            'Configurazione locale esclusa da Git per proteggere credenziali DB e API key.',
            'Stile grafico chiaro, leggibile e ottimizzato per uso personale.',
        ],
    ],
];
