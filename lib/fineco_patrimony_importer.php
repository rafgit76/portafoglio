<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/account_tracker.php';

final class FinecoPatrimonyParser
{
    private const VALUE_FIELDS = [
        'patrimonio totale' => 'fineco_total_assets',
        'saldo contabile' => 'accounting_balance',
        'saldo disponibile' => 'available_balance',
        'totale liquidita' => 'liquidity_total',
        'liquidita prenotata' => 'reserved_liquidity',
        'conto credit lombard' => 'credit_lombard_account',
        'totale multicurrency' => 'multicurrency_amount',
        'fido accordato' => 'credit_agreed_amount',
        'fido totale' => 'credit_agreed_amount',
        'fido utilizzato' => 'credit_used_amount',
        'fido disponibile' => 'credit_available_amount',
        'totale portafoglio' => 'fineco_portfolio_total',
    ];

    private const DETAIL_FIELDS = [
        'utilizzo carta di debito',
        'ritenute fiscali',
        'totale operazioni in corso',
    ];

    public function parse(string $path): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException('Il lettore Excel non e disponibile sul server. Carica la cartella vendor completa.');
        }

        $readerType = IOFactory::identify($path);
        if (!in_array($readerType, ['Xls', 'Xlsx'], true)) {
            throw new RuntimeException('Il file Patrimonio Fineco deve essere un file .xls o .xlsx.');
        }
        $reader = IOFactory::createReader($readerType);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        try {
            $values = [];
            $details = [];
            $dossiers = [];
            $rowsSeen = 0;
            $currentDossierIndex = null;
            foreach ($book->getWorksheetIterator() as $sheet) {
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    $cells = [];
                    foreach ($row as $cell) {
                        if ($cell !== null && trim((string) $cell) !== '') {
                            $cells[] = trim((string) $cell);
                        }
                    }
                    if ($cells === []) {
                        continue;
                    }
                    $rowsSeen++;
                    $label = $this->normalizeLabel((string) $cells[0]);
                    $amount = $this->firstNumber(array_slice($cells, 1));
                    if ($amount === null) {
                        continue;
                    }

                    if (isset(self::VALUE_FIELDS[$label]) && !array_key_exists(self::VALUE_FIELDS[$label], $values)) {
                        $values[self::VALUE_FIELDS[$label]] = $amount;
                        $currentDossierIndex = null;
                        continue;
                    }
                    if (in_array($label, self::DETAIL_FIELDS, true)) {
                        $details[$label] = $amount;
                        $currentDossierIndex = null;
                        continue;
                    }
                    if (str_starts_with($label, 'dossier ')) {
                        $dossiers[] = [
                            'source_label' => (string) $cells[0],
                            'amount' => $amount,
                            'code_hint' => $this->dossierCodeHint($label),
                            'categories' => [],
                        ];
                        $currentDossierIndex = array_key_last($dossiers);
                        continue;
                    }
                    if ($currentDossierIndex !== null && !str_starts_with($label, 'totale ')) {
                        $dossiers[$currentDossierIndex]['categories'][(string) $cells[0]] = $amount;
                    }
                }
            }
        } finally {
            $book->disconnectWorksheets();
            unset($book);
        }

        if (!array_key_exists('fineco_total_assets', $values)) {
            throw new RuntimeException('Non trovo il campo "Patrimonio totale" nell\'export Fineco. Verifica di avere caricato il file Patrimonio completo.');
        }

        return [
            'values' => $values,
            'details' => $details,
            'dossiers' => $dossiers,
            'rows_seen' => $rowsSeen,
        ];
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value));
        $value = str_replace(['à', 'è', 'é', 'ì', 'ò', 'ù'], ['a', 'e', 'e', 'i', 'o', 'u'], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function firstNumber(array $values): ?float
    {
        foreach ($values as $value) {
            $parsed = parse_decimal_nullable($value);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    private function dossierCodeHint(string $normalizedLabel): string
    {
        if (str_contains($normalizedLabel, 'pegno') || str_contains($normalizedLabel, 'lombard')) {
            return 'credit_lombard';
        }
        if (str_contains($normalizedLabel, 'principale')) {
            return 'ordinario';
        }
        return 'altro';
    }
}

function fineco_patrimony_effective_values(array $importedValues, array $manualOverrides): array
{
    $effective = $importedValues;
    foreach ($manualOverrides as $field => $value) {
        if ($value !== null && $value !== '') {
            $effective[$field] = $value;
        }
    }
    return $effective;
}

function import_fineco_patrimony_spreadsheet(string $path, string $sourceFileName, string $snapshotDate): array
{
    $parsed = (new FinecoPatrimonyParser())->parse($path);
    $pdo = db();
    if (!account_tracker_schema_ready($pdo)) {
        throw new RuntimeException('Il modulo Conto non e inizializzato. Importa prima la migrazione SQL 2.2.0.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $snapshotDate);
    if (!$date || $date->format('Y-m-d') !== $snapshotDate) {
        throw new RuntimeException('Data dello snapshot patrimoniale non valida.');
    }

    $pdo->beginTransaction();
    try {
        $existingStatement = $pdo->prepare('SELECT * FROM pf_patrimonial_snapshots WHERE snapshot_date=:date LIMIT 1');
        $existingStatement->execute([':date' => $snapshotDate]);
        $existing = $existingStatement->fetch();
        $manualOverrides = [];
        if (is_array($existing) && is_string($existing['manual_overrides_json'] ?? null)) {
            $decoded = json_decode((string) $existing['manual_overrides_json'], true);
            $manualOverrides = is_array($decoded) ? $decoded : [];
        }
        $effective = fineco_patrimony_effective_values($parsed['values'], $manualOverrides);
        $fields = ['fineco_total_assets','fineco_portfolio_total','liquidity_total','reserved_liquidity','accounting_balance','available_balance','credit_lombard_account','multicurrency_amount','credit_agreed_amount','credit_used_amount','credit_available_amount'];
        $adjustments = account_tracker_patrimonial_adjustments($effective);
        $parameters = [':snapshot_date' => $snapshotDate, ':source_file_name' => $sourceFileName, ':imported_values_json' => json_encode($parsed['values'], JSON_UNESCAPED_UNICODE), ':manual_overrides_json' => json_encode($manualOverrides, JSON_UNESCAPED_UNICODE), ':notes' => null];
        foreach ($fields as $field) { $parameters[':' . $field] = $effective[$field] ?? null; }
        $parameters[':net_assets_adjusted'] = $adjustments['net_assets_adjusted'];
        $parameters[':own_liquidity_adjusted'] = $adjustments['own_liquidity_adjusted'];
        $statement = $pdo->prepare('INSERT INTO pf_patrimonial_snapshots (snapshot_date,fineco_total_assets,fineco_portfolio_total,liquidity_total,reserved_liquidity,accounting_balance,available_balance,credit_lombard_account,multicurrency_amount,credit_agreed_amount,credit_used_amount,credit_available_amount,net_assets_adjusted,own_liquidity_adjusted,imported_values_json,manual_overrides_json,source_file_name,notes,data_origin) VALUES (:snapshot_date,:fineco_total_assets,:fineco_portfolio_total,:liquidity_total,:reserved_liquidity,:accounting_balance,:available_balance,:credit_lombard_account,:multicurrency_amount,:credit_agreed_amount,:credit_used_amount,:credit_available_amount,:net_assets_adjusted,:own_liquidity_adjusted,:imported_values_json,:manual_overrides_json,:source_file_name,:notes,"IMPORT_FINECO") ON DUPLICATE KEY UPDATE fineco_total_assets=VALUES(fineco_total_assets), fineco_portfolio_total=VALUES(fineco_portfolio_total), liquidity_total=VALUES(liquidity_total), reserved_liquidity=VALUES(reserved_liquidity), accounting_balance=VALUES(accounting_balance), available_balance=VALUES(available_balance), credit_lombard_account=VALUES(credit_lombard_account), multicurrency_amount=VALUES(multicurrency_amount), credit_agreed_amount=VALUES(credit_agreed_amount), credit_used_amount=VALUES(credit_used_amount), credit_available_amount=VALUES(credit_available_amount), net_assets_adjusted=VALUES(net_assets_adjusted), own_liquidity_adjusted=VALUES(own_liquidity_adjusted), imported_values_json=VALUES(imported_values_json), manual_overrides_json=VALUES(manual_overrides_json), source_file_name=VALUES(source_file_name), data_origin="IMPORT_FINECO"');
        $statement->execute($parameters);
        $patrimonialId = (int) ($existing['id'] ?? $pdo->lastInsertId());
        if ($patrimonialId <= 0) {
            $lookup = $pdo->prepare('SELECT id FROM pf_patrimonial_snapshots WHERE snapshot_date=:date');
            $lookup->execute([':date' => $snapshotDate]);
            $patrimonialId = (int) $lookup->fetchColumn();
        }
        $pdo->prepare('DELETE FROM pf_patrimonial_dossier_values WHERE patrimonial_snapshot_id=:id AND data_origin="IMPORT_FINECO"')->execute([':id' => $patrimonialId]);
        $dossierLookup = $pdo->prepare('SELECT id FROM pf_dossiers WHERE code=:code LIMIT 1');
        $insertDossier = $pdo->prepare('INSERT INTO pf_patrimonial_dossier_values (patrimonial_snapshot_id,dossier_id,source_label,amount,category_breakdown_json,data_origin) VALUES (:snapshot_id,:dossier_id,:source_label,:amount,:categories,"IMPORT_FINECO")');
        foreach ($parsed['dossiers'] as $dossier) {
            $dossierLookup->execute([':code' => $dossier['code_hint']]);
            $dossierId = $dossierLookup->fetchColumn() ?: null;
            $insertDossier->execute([':snapshot_id'=>$patrimonialId, ':dossier_id'=>$dossierId, ':source_label'=>$dossier['source_label'], ':amount'=>$dossier['amount'], ':categories'=>json_encode($dossier['categories'], JSON_UNESCAPED_UNICODE)]);
        }
        $payload = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $job = $pdo->prepare('INSERT INTO pf_patrimonial_import_jobs (patrimonial_snapshot_id,source_file_name,parsed_payload_json) VALUES (:snapshot_id,:file_name,:payload)');
        $job->execute([':snapshot_id'=>$patrimonialId, ':file_name'=>$sourceFileName, ':payload'=>$payload]);
        $pdo->commit();
        return ['patrimonial_snapshot_id'=>$patrimonialId, 'snapshot_date'=>$snapshotDate, 'values'=>$effective, 'dossiers_count'=>count($parsed['dossiers'])];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
