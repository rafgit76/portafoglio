<?php

return [
    [
        'version' => '1.3.3',
        'date' => '2026-07-02',
        'title' => 'Export dettaglio titolo',
        'changes' => [
            'Aggiunto export Excel nella pagina dettaglio del singolo titolo.',
            'Aggiunto pulsante Stampa PDF nella pagina dettaglio del singolo titolo.',
            'Ottimizzata la resa di stampa dello storico snapshot del titolo.',
            'Inclusi statistiche iniziali e grafici nella stampa PDF del dettaglio titolo.',
        ],
    ],
    [
        'version' => '1.3.2',
        'date' => '2026-06-28',
        'title' => 'Estremi P/L nel dettaglio titolo',
        'changes' => [
            'Evidenziata nella pagina dettaglio titolo la riga con massimo P/L storico del singolo asset.',
            'Evidenziata nella pagina dettaglio titolo la riga con minimo P/L storico del singolo asset.',
            'Aggiunte etichette visive Massimo P/L e Minimo P/L nella tabella storico snapshot del titolo.',
        ],
    ],
    [
        'version' => '1.3.1',
        'date' => '2026-06-28',
        'title' => 'Estremi P/L nello storico snapshot',
        'changes' => [
            'Evidenziata nello storico snapshot la riga con massimo P/L totale.',
            'Evidenziata nello storico snapshot la riga con minimo P/L totale.',
            'Aggiunte etichette visive Massimo P/L e Minimo P/L accanto al valore.',
            'Migliorata la spaziatura dei pulsanti azione nello storico snapshot evitando tagli a bordo tabella.',
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-06-28',
        'title' => 'Dettaglio singolo titolo',
        'changes' => [
            'Aggiunta pagina dettaglio per ogni titolo con storico completo dagli snapshot.',
            'Aggiunti grafici dedicati per valore, P/L, peso nel portafoglio e quantita.',
            'Aggiunti indicatori di massimo/minimo, distanza dal massimo P/L, delta dal primo snapshot e drawdown.',
            'Aggiunti collegamenti al dettaglio titolo da Strumenti, Snapshot e intestazioni della pagina Andamento.',
        ],
    ],
    [
        'version' => '1.2.5',
        'date' => '2026-06-28',
        'title' => 'Deploy piu stabile',
        'changes' => [
            'Spostato Chart.js in assets/vendor per evitare dipendenze CDN a runtime.',
            'Aggiunto script PowerShell per creare una cartella FTP pulita con timestamp fino ai minuti.',
            'Aggiornata documentazione deploy con il flusso di pubblicazione consigliato.',
        ],
    ],
    [
        'version' => '1.2.4',
        'date' => '2026-06-18',
        'title' => 'Andamento piu navigabile',
        'changes' => [
            'Aggiunta paginazione alla tabella Andamento storico per titolo.',
            'Aggiunto selettore per scegliere quante righe di storico visualizzare.',
            'Aggiunti tooltip sui valori P/L con massimo storico e distanza dal massimo.',
        ],
    ],
    [
        'version' => '1.2.3',
        'date' => '2026-06-03',
        'title' => 'Quantita piu compatte nello snapshot',
        'changes' => [
            'Ridotta la visualizzazione della colonna quantita a due decimali nel dettaglio snapshot.',
            'Mantenuti invariati i valori salvati a database e usati nei calcoli.',
        ],
    ],
    [
        'version' => '1.2.2',
        'date' => '2026-05-19',
        'title' => 'Report AI piu approfondito',
        'changes' => [
            'Rafforzato il prompt del Report AI per ottenere un report narrativo piu completo.',
            'Aggiunta richiesta esplicita di analisi per asset rilevanti, controvalori e variazioni di peso percentuale.',
            'Aumentato il limite di output del modello per ridurre risposte troppo sintetiche.',
            'Aggiunta versione del prompt nella cache per rigenerare automaticamente il report dopo il deploy.',
        ],
    ],
    [
        'version' => '1.2.1',
        'date' => '2026-05-19',
        'title' => 'Aggiornamento modello Gemini',
        'changes' => [
            'Aggiornato il modello AI predefinito a gemini-3.1-flash-lite.',
            'Allineata la chiamata REST Gemini tramite header x-goog-api-key per evitare la chiave in URL.',
            'Aggiunta invalidazione automatica della cache Report AI quando cambia modello.',
        ],
    ],
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
