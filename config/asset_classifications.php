<?php

/*
 * Classificazioni aggiornabili indipendentemente dagli snapshot Fineco.
 * I pesi sono espressi come decimali (1 = 100%) e le date sono quelle delle
 * fonti dichiarate. Non sono quotazioni o dati di mercato in tempo reale.
 */
return [
    'updated_at' => '2026-08-03',
    'dimensions' => [
        'geography' => 'Diversificazione territoriale',
        'sector' => 'Esposizione settoriale',
    ],
    'assets' => [
        'US5949181045' => [
            'source_name' => 'Microsoft Investor Relations - Annual report',
            'source_url' => 'https://www.microsoft.com/en-us/annualreports',
            'source_as_of' => '2026-07-31',
            'method' => 'Classificazione diretta dell’emittente.',
            'allocations' => [
                'geography' => ['Stati Uniti' => 1.0],
                'sector' => ['Tecnologia' => 1.0],
            ],
        ],
        'US67066G1040' => [
            'source_name' => 'NVIDIA Investor Relations - Financial reports',
            'source_url' => 'https://investor.nvidia.com/financial-info/financial-reports/default.aspx?languageid=1',
            'source_as_of' => '2026-07-31',
            'method' => 'Classificazione diretta dell’emittente.',
            'allocations' => [
                'geography' => ['Stati Uniti' => 1.0],
                'sector' => ['Tecnologia' => 1.0],
            ],
        ],
        'IE00BK5BQT80' => [
            'source_name' => 'Vanguard FTSE All-World UCITS ETF - portfolio data',
            'source_url' => 'https://fund-docs.vanguard.com/ie00bk5bqt80-en.pdf',
            'source_as_of' => '2026-06-30',
            'method' => 'Ripartizione geografica look-through del fondo; settore mantenuto come ETF azionario globale multi-settore per non stimare pesi non pubblicati nel catalogo.',
            'allocations' => [
                'geography' => [
                    'Stati Uniti' => 0.6170,
                    'Giappone' => 0.0590,
                    'Taiwan' => 0.0338,
                    'Regno Unito' => 0.0318,
                    'Canada' => 0.0291,
                    'Corea del Sud' => 0.0290,
                    'Cina' => 0.0261,
                    'Svizzera' => 0.0204,
                    'Francia' => 0.0202,
                    'Germania' => 0.0182,
                    'India' => 0.0159,
                    'Australia' => 0.0154,
                    'Paesi Bassi' => 0.0126,
                    'Spagna' => 0.0085,
                    'Italia' => 0.0080,
                    'Altri paesi' => 0.0550,
                ],
                'sector' => ['ETF azionario globale multi-settore' => 1.0],
            ],
        ],
        'LU0171310955' => [
            'source_name' => 'BlackRock - BGF World Technology Fund E2 Euro',
            'source_url' => 'https://www.blackrock.com/lu/individual/products/229355/blackrock-world-technology-e2-eur-fund',
            'source_as_of' => '2026-06-30',
            'method' => 'Classificazione tematica del fondo globale. Il prospetto dichiara almeno il 70% in societa tecnologiche; non viene trasformata in una ripartizione geografica di dettaglio.',
            'allocations' => [
                'geography' => ['Globale (fondo tecnologico)' => 1.0],
                'sector' => ['Tecnologia (fondo tematico)' => 1.0],
            ],
        ],
        'LU0115773425' => [
            'source_name' => 'Fidelity International - Global Technology Fund E-ACC-Euro',
            'source_url' => 'https://www.fidelityinternational.com/FILPS/Documents/en/current/mf.en.xx.LU0115773425.pdf',
            'source_as_of' => '2026-06-30',
            'method' => 'Ripartizioni look-through del factsheet mensile. Le piccole differenze di somma dovute agli arrotondamenti della fonte sono normalizzate solo per la visualizzazione.',
            'allocations' => [
                'geography' => [
                    'Stati Uniti' => 0.517,
                    'Taiwan' => 0.120,
                    'Paesi Bassi' => 0.098,
                    'Cina' => 0.053,
                    'Giappone' => 0.044,
                    'Hong Kong' => 0.032,
                    'Corea del Sud' => 0.028,
                    'Svezia' => 0.023,
                    'Regno Unito' => 0.019,
                    'Francia' => 0.015,
                    'Altri paesi' => 0.035,
                    'Liquidita' => 0.016,
                ],
                'sector' => [
                    'Tecnologia informatica' => 0.663,
                    'Comunicazioni' => 0.134,
                    'Consumi discrezionali' => 0.080,
                    'Industriali' => 0.040,
                    'Finanziari' => 0.035,
                    'Immobiliare' => 0.018,
                    'Materiali' => 0.010,
                    'Energia' => 0.006,
                    'Liquidita e altri' => 0.016,
                ],
            ],
        ],
        'IT0005532715' => [
            'source_name' => 'MEF - Titoli di Stato italiani',
            'source_url' => 'https://www.dt.mef.gov.it/it/debito_pubblico/',
            'source_as_of' => '2026-08-03',
            'method' => 'Classificazione diretta del titolo di Stato.',
            'allocations' => ['geography' => ['Italia' => 1.0], 'sector' => ['Titoli di Stato italiani' => 1.0]],
        ],
        'IT0005696320' => [
            'source_name' => 'MEF - Titoli di Stato italiani',
            'source_url' => 'https://www.dt.mef.gov.it/it/debito_pubblico/',
            'source_as_of' => '2026-08-03',
            'method' => 'Classificazione diretta del titolo di Stato.',
            'allocations' => ['geography' => ['Italia' => 1.0], 'sector' => ['Titoli di Stato italiani' => 1.0]],
        ],
        'IT0005547408' => [
            'source_name' => 'MEF - Titoli di Stato italiani',
            'source_url' => 'https://www.dt.mef.gov.it/it/debito_pubblico/',
            'source_as_of' => '2026-08-03',
            'method' => 'Classificazione diretta del titolo di Stato.',
            'allocations' => ['geography' => ['Italia' => 1.0], 'sector' => ['Titoli di Stato italiani' => 1.0]],
        ],
        'IT0005547390' => [
            'source_name' => 'MEF - Titoli di Stato italiani',
            'source_url' => 'https://www.dt.mef.gov.it/it/debito_pubblico/',
            'source_as_of' => '2026-08-03',
            'method' => 'Classificazione diretta del titolo di Stato.',
            'allocations' => ['geography' => ['Italia' => 1.0], 'sector' => ['Titoli di Stato italiani' => 1.0]],
        ],
    ],
];
