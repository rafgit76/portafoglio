<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';
require_once __DIR__ . '/lib/credit_lombard_control.php';

$pageTitle = 'Gestione fidi';
$pdo = db();

if (!account_tracker_schema_ready($pdo)) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state"><h2>Modulo conto da inizializzare</h2><p>Se il modulo non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; quindi applica in ordine le migrazioni <code>2_4_0</code>, <code>2_5_0</code> e <code>2_6_0</code> dopo un export completo da phpMyAdmin.</p></section>
    <?php include __DIR__ . '/partials/footer.php'; exit;
}

$profileSchemaReady = account_tracker_credit_profile_schema_ready($pdo);
$controlSchemaReady = credit_lombard_control_schema_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        $action = (string) ($_POST['action'] ?? 'save_credit_line');
        if ($action === 'check_credit_lombard') {
            if (!$profileSchemaReady || !$controlSchemaReady) {
                throw new RuntimeException('Importa prima la migrazione SQL 2.7.0 per attivare il controllo Credit Lombard.');
            }
            $creditLineId = (int) ($_POST['credit_line_id'] ?? 0);
            $lineStatement = $pdo->prepare('SELECT * FROM pf_credit_lines WHERE id = :id AND credit_type = "CREDIT_LOMBARD" LIMIT 1');
            $lineStatement->execute([':id' => $creditLineId]);
            $creditLine = $lineStatement->fetch();
            if (!is_array($creditLine)) {
                throw new RuntimeException('Rapporto Credit Lombard non trovato.');
            }
            $profile = account_tracker_credit_line_profile($creditLine);
            $expectedDossierIdentifier = (string) ($profile['pledge_dossier_id'] ?? '');

            $file = $_FILES['lombard_patrimony_file'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleziona l export patrimonio del rapporto Credit Lombard.');
            }
            $originalName = (string) ($file['name'] ?? '');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xls', 'xlsx'], true)) {
                throw new RuntimeException('Il controllo accetta soltanto file Fineco .xls o .xlsx.');
            }
            $temporaryPath = $file['tmp_name'] ?? null;
            if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
                throw new RuntimeException('Upload di controllo non valido.');
            }
            $fileSize = (int) ($file['size'] ?? 0);
            if ($fileSize <= 0 || $fileSize > app_upload_max_bytes()) {
                throw new RuntimeException('File di controllo troppo grande o vuoto.');
            }
            if (uploaded_spreadsheet_type($temporaryPath, $originalName) === null) {
                throw new RuntimeException('Il file non sembra un export Excel Fineco valido.');
            }

            $parsed = (new FinecoPatrimonyParser())->parse($temporaryPath);
            $control = credit_lombard_control_extract(
                $parsed,
                $expectedDossierIdentifier,
                credit_lombard_control_date_from_filename($originalName)
            );
            $expected = credit_lombard_control_expected_portfolio($pdo, $creditLine, (string) $control['check_date']);
            $result = credit_lombard_control_compare($control, $expected);
            credit_lombard_control_save($pdo, $creditLineId, $originalName, $parsed, $result);

            $message = match ($result['check_status']) {
                'COHERENT' => 'Controllo Credit Lombard completato: il dossier a pegno coincide al centesimo con valore di mercato e rateo importati.',
                'DIFFERENCE' => 'Controllo Credit Lombard completato: rilevata una differenza di EUR ' . number_format((float) $result['difference_amount'], 2, ',', '.') . '.',
                default => 'Controllo Credit Lombard archiviato. Non esiste un portafoglio Lombard della stessa data con cui confrontarlo.',
            };
            set_flash($result['check_status'] === 'DIFFERENCE' ? 'error' : 'success', $message . ' Nessuno snapshot e stato modificato.');
            redirect('/credit_lines.php#lombard-control');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new RuntimeException('Inserisci un nome del fido valido.');
        }
        $agreed = account_tracker_optional_decimal($_POST['agreed_amount'] ?? null, 'importo accordato');
        $used = account_tracker_optional_decimal($_POST['used_amount'] ?? null, 'importo utilizzato');
        $agreed ??= 0.0;
        $used ??= 0.0;
        if ($agreed < 0 || $used < 0) {
            throw new RuntimeException('Gli importi del fido non possono essere negativi.');
        }
        if ($used > $agreed && ($_POST['allow_over_agreement'] ?? '') !== '1') {
            throw new RuntimeException('L\'utilizzato supera l\'accordato. Spunta l\'override soltanto se il dato e corretto.');
        }

        $type = (string) ($_POST['credit_type'] ?? 'ALTRO');
        if (!in_array($type, ['CREDIT_LOMBARD', 'MINIFIDO', 'FIDO_ORDINARIO', 'ALTRO'], true)) {
            throw new RuntimeException('Tipo di fido non valido.');
        }
        $status = (string) ($_POST['status'] ?? 'ACTIVE');
        if (!in_array($status, ['ACTIVE', 'PAUSED', 'CLOSED'], true)) {
            throw new RuntimeException('Stato del fido non valido.');
        }
        $dossierId = (int) ($_POST['dossier_id'] ?? 0);
        if ($dossierId <= 0) {
            $dossierId = null;
        }
        $rate = account_tracker_optional_decimal($_POST['annual_rate'] ?? null, 'tasso');
        $activationDate = trim((string) ($_POST['activation_date'] ?? ''));
        if ($activationDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate) || strtotime($activationDate) === false)) {
            throw new RuntimeException('Data di attivazione non valida.');
        }
        $date = trim((string) ($_POST['usage_snapshot_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Data rilevazione fido non valida.');
        }
        $id = (int) ($_POST['credit_line_id'] ?? 0);
        $data = [
            ':name' => $name,
            ':bank_name' => trim((string) ($_POST['bank_name'] ?? '')) ?: null,
            ':credit_type' => $type,
            ':agreed_amount' => $agreed,
            ':used_amount' => $used,
            ':annual_rate' => $rate,
            ':rate_formula' => trim((string) ($_POST['rate_formula'] ?? '')) ?: null,
            ':activation_date' => $activationDate !== '' ? $activationDate : null,
            ':status' => $status,
            ':dossier_id' => $dossierId,
            ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        if ($profileSchemaReady) {
            $validityType = strtoupper(trim((string) ($_POST['validity_type'] ?? '')));
            if ($validityType !== '' && !in_array($validityType, ['REVOCABLE', 'FIXED_TERM', 'OTHER'], true)) {
                throw new RuntimeException('Tipo di validita non valido.');
            }
            $identifier = static function (mixed $raw, string $label): ?string {
                $value = trim((string) $raw);
                if ($value === '') {
                    return null;
                }
                if (strlen($value) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
                    throw new RuntimeException($label . ' non valido.');
                }
                return $value;
            };
            $dataOrigin = strtolower(trim((string) ($_POST['data_origin'] ?? 'manual')));
            if (!in_array($dataOrigin, ['manual', 'fineco_acceptance_letter'], true)) {
                throw new RuntimeException('Provenienza della linea di credito non valida.');
            }
            $sourceDocumentName = trim((string) ($_POST['source_document_name'] ?? ''));
            if (mb_strlen($sourceDocumentName) > 255) {
                throw new RuntimeException('Nome del documento sorgente troppo lungo.');
            }
            if ($dataOrigin === 'fineco_acceptance_letter' && $sourceDocumentName === '') {
                throw new RuntimeException('Indica il documento Fineco che certifica il rapporto.');
            }
            $data += [
                ':validity_type' => $validityType !== '' ? $validityType : null,
                ':linked_main_account_id' => $identifier($_POST['linked_main_account_id'] ?? null, 'Conto collegato'),
                ':pledge_account_id' => $identifier($_POST['pledge_account_id'] ?? null, 'Conto a pegno'),
                ':pledge_dossier_id' => $identifier($_POST['pledge_dossier_id'] ?? null, 'Dossier a garanzia'),
                ':data_origin' => $dataOrigin,
                ':source_document_name' => $sourceDocumentName !== '' ? basename($sourceDocumentName) : null,
            ];
        }
        $pdo->beginTransaction();
        if ($id > 0) {
            $data[':id'] = $id;
            $statement = $pdo->prepare($profileSchemaReady
                ? 'UPDATE pf_credit_lines SET name=:name, bank_name=:bank_name, credit_type=:credit_type, agreed_amount=:agreed_amount, used_amount=:used_amount, annual_rate=:annual_rate, rate_formula=:rate_formula, activation_date=:activation_date, status=:status, validity_type=:validity_type, linked_main_account_id=:linked_main_account_id, pledge_account_id=:pledge_account_id, pledge_dossier_id=:pledge_dossier_id, data_origin=:data_origin, source_document_name=:source_document_name, dossier_id=:dossier_id, notes=:notes WHERE id=:id'
                : 'UPDATE pf_credit_lines SET name=:name, bank_name=:bank_name, credit_type=:credit_type, agreed_amount=:agreed_amount, used_amount=:used_amount, annual_rate=:annual_rate, rate_formula=:rate_formula, activation_date=:activation_date, status=:status, dossier_id=:dossier_id, notes=:notes WHERE id=:id');
            $statement->execute($data);
        } else {
            $statement = $pdo->prepare($profileSchemaReady
                ? 'INSERT INTO pf_credit_lines (name, bank_name, credit_type, agreed_amount, used_amount, annual_rate, rate_formula, activation_date, status, validity_type, linked_main_account_id, pledge_account_id, pledge_dossier_id, data_origin, source_document_name, dossier_id, notes) VALUES (:name,:bank_name,:credit_type,:agreed_amount,:used_amount,:annual_rate,:rate_formula,:activation_date,:status,:validity_type,:linked_main_account_id,:pledge_account_id,:pledge_dossier_id,:data_origin,:source_document_name,:dossier_id,:notes)'
                : 'INSERT INTO pf_credit_lines (name, bank_name, credit_type, agreed_amount, used_amount, annual_rate, rate_formula, activation_date, status, dossier_id, notes) VALUES (:name,:bank_name,:credit_type,:agreed_amount,:used_amount,:annual_rate,:rate_formula,:activation_date,:status,:dossier_id,:notes)');
            $statement->execute($data);
            $id = (int) $pdo->lastInsertId();
        }
        $snapshot = $pdo->prepare('INSERT INTO pf_credit_line_snapshots (credit_line_id, snapshot_date, agreed_amount, used_amount, data_origin) VALUES (:id,:snapshot_date,:agreed_amount,:used_amount,"MANUALE") ON DUPLICATE KEY UPDATE agreed_amount=VALUES(agreed_amount), used_amount=VALUES(used_amount), data_origin="MANUALE"');
        $snapshot->execute([':id' => $id, ':snapshot_date' => $date, ':agreed_amount' => $agreed, ':used_amount' => $used]);
        $pdo->commit();
        set_flash('success', 'Linea di credito salvata. Il credito disponibile resta separato dal patrimonio.');
        redirect('/credit_lines.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('error', $e->getMessage());
        redirect('/credit_lines.php');
    }
}

$dossiers = account_tracker_dossiers($pdo);
$lines = $pdo->query('SELECT c.*, d.name AS dossier_name, c.agreed_amount - c.used_amount AS available_amount FROM pf_credit_lines c LEFT JOIN pf_dossiers d ON d.id=c.dossier_id ORDER BY FIELD(c.status,"ACTIVE","PAUSED","CLOSED"), c.name')->fetchAll();
$registeredLines = account_tracker_registered_credit_lines($lines, account_tracker_latest_patrimonial_snapshot($pdo));
$creditLombardLines = array_values(array_filter($lines, static fn(array $line): bool => ($line['credit_type'] ?? '') === 'CREDIT_LOMBARD'));
$controlHistory = [];
if ($controlSchemaReady) {
    foreach ($creditLombardLines as $creditLombardLine) {
        foreach (credit_lombard_control_history($pdo, (int) $creditLombardLine['id']) as $check) {
            $check['credit_line_name'] = (string) $creditLombardLine['name'];
            $controlHistory[] = $check;
        }
    }
    usort($controlHistory, static fn(array $a, array $b): int => [$b['check_date'], $b['id']] <=> [$a['check_date'], $a['id']]);
    $controlHistory = array_slice($controlHistory, 0, 12);
}
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
foreach ($lines as $line) { if ((int) $line['id'] === $editId) { $editing = $line; break; } }
$value = static fn(string $key, string $fallback = ''): string => e((string) ($editing[$key] ?? $fallback));

include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero"><div class="panel-head"><div><p class="eyebrow">GESTIONE</p><h2>Fidi e linee di credito</h2><p>Accordato, utilizzato e disponibile sono sempre separati dal patrimonio.</p></div><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Conto e patrimonio</a></div></section>
<?php if (!$profileSchemaReady): ?><section class="panel warning-note"><strong>Profilo Credit Lombard da inizializzare.</strong> Dopo un backup completo del database, importa una volta <code>sql/migration_2_6_0_credit_lombard_profile.sql</code>. Le funzioni gia esistenti continuano a funzionare.</section><?php endif; ?>
<section class="panel"><div class="panel-head"><h2><?= $editing ? 'Modifica linea di credito' : 'Nuova linea di credito' ?></h2><span class="tiny-note">L'importo disponibile e accordato meno utilizzato.</span></div>
<form method="post" class="form-grid account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="credit_line_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
<label>Nome<input name="name" required value="<?= $value('name') ?>" placeholder="Es. Credit Lombard"></label><label>Banca<input name="bank_name" value="<?= $value('bank_name') ?>" placeholder="Es. Fineco"></label>
<label>Tipo<select name="credit_type"><?php foreach (['CREDIT_LOMBARD'=>'Credit Lombard','MINIFIDO'=>'Minifido','FIDO_ORDINARIO'=>'Fido ordinario','ALTRO'=>'Altro'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editing['credit_type'] ?? 'ALTRO') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Dossier collegato<select name="dossier_id"><option value="">Nessuno</option><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= (int) ($editing['dossier_id'] ?? 0) === (int) $dossier['id'] ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
<label>Importo accordato<input name="agreed_amount" inputmode="decimal" value="<?= $value('agreed_amount','0') ?>"></label><label>Importo utilizzato<input name="used_amount" inputmode="decimal" value="<?= $value('used_amount','0') ?>"></label>
<label>Tasso annuo %<input name="annual_rate" inputmode="decimal" value="<?= $value('annual_rate') ?>"></label><label>Formula tasso<input name="rate_formula" value="<?= $value('rate_formula') ?>" placeholder="Es. Euribor 3M + spread"></label>
<label>Data attivazione<input type="date" name="activation_date" value="<?= $value('activation_date') ?>"></label><label>Data rilevazione utilizzo<input type="date" name="usage_snapshot_date" value="<?= e(date('Y-m-d')) ?>"></label>
<label>Stato<select name="status"><?php foreach (['ACTIVE'=>'Attivo','PAUSED'=>'Sospeso','CLOSED'=>'Chiuso'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editing['status'] ?? 'ACTIVE') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<?php if ($profileSchemaReady): ?>
<label>Validita<select name="validity_type"><option value="">Non specificata</option><?php foreach (['REVOCABLE'=>'A revoca','FIXED_TERM'=>'A scadenza','OTHER'=>'Altra'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editing['validity_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Conto collegato<input name="linked_main_account_id" value="<?= $value('linked_main_account_id') ?>" maxlength="64"></label>
<label>Conto a pegno<input name="pledge_account_id" value="<?= $value('pledge_account_id') ?>" maxlength="64"></label>
<label>Dossier a garanzia<input name="pledge_dossier_id" value="<?= $value('pledge_dossier_id') ?>" maxlength="64"></label>
<label>Provenienza<select name="data_origin"><option value="manual" <?= ($editing['data_origin'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Manuale</option><option value="fineco_acceptance_letter" <?= ($editing['data_origin'] ?? '') === 'fineco_acceptance_letter' ? 'selected' : '' ?>>Lettera di accettazione Fineco</option></select></label>
<label>Documento sorgente<input name="source_document_name" value="<?= $value('source_document_name') ?>" maxlength="255" placeholder="Es. lettera-accettazione.pdf"></label>
<?php endif; ?>
<label class="checkbox-label"><input type="checkbox" name="allow_over_agreement" value="1"> Confermo un utilizzato superiore all'accordato</label>
<label class="form-full">Note<textarea name="notes" rows="3"><?= $value('notes') ?></textarea></label><div class="form-full"><button type="submit">Salva linea di credito</button></div></form></section>
<section class="panel" id="registered-credit-lines">
    <h2>Linee registrate</h2>
    <?php if (!$registeredLines): ?>
        <p>Nessuna linea di credito configurata.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Nome</th><th>Tipo</th><th>Accordato</th><th>Utilizzato</th><th>Disponibile</th><th>Stato</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($registeredLines as $line):
                $lineProfile = account_tracker_credit_line_profile($line);
                $currentCredit = $line['current_credit'];
            ?>
                <tr>
                    <td><strong><?= e((string) $line['name']) ?></strong><br><span class="tiny-note"><?= e((string) ($line['dossier_name'] ?? '')) ?><?= $profileSchemaReady ? ' - ' . e($lineProfile['data_origin_label']) : '' ?></span></td>
                    <td><?= e($lineProfile['credit_line_type']) ?></td>
                    <td>EUR <?= number_format((float) $line['agreed_amount'], 2, ',', '.') ?></td>
                    <?php foreach (['used' => 'used_amount', 'available' => 'available_amount'] as $kind => $field):
                        $source = $currentCredit[$kind . '_source'];
                        $sourceLabel = match ($source) {
                            'patrimonial_snapshot' => 'Snapshot patrimonio del ',
                            'calculated_snapshot' => 'Calcolato da snapshot del ',
                            default => 'Dato registrato',
                        };
                        if ($source !== 'registered') {
                            $sourceLabel .= date('d/m/Y', strtotime($currentCredit['snapshot_date']));
                        }
                    ?>
                    <td class="<?= $kind === 'used' && $currentCredit[$field] > 0 ? 'neg' : '' ?>">EUR <?= number_format($currentCredit[$field], 2, ',', '.') ?><br><span class="tiny-note"><?= e($sourceLabel) ?></span></td>
                    <?php endforeach; ?>
                    <td><?= e($lineProfile['status']) ?></td>
                    <td><a class="btn-link" href="<?= e(app_base_path()) ?>/credit_lines.php?edit=<?= (int) $line['id'] ?>">Modifica</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php if ($creditLombardLines): ?>
<section class="panel" id="lombard-control">
    <div class="panel-head">
        <div><p class="eyebrow">VERIFICA FACOLTATIVA</p><h2>Controllo rapporto Credit Lombard</h2><p>Carica l'export Patrimonio del solo rapporto Lombard. Il file viene confrontato con il portafoglio Lombard della stessa data senza modificare alcun dato.</p></div>
    </div>
    <?php if (!$controlSchemaReady): ?>
        <div class="warning-note"><strong>Controllo da aggiornare.</strong> Dopo un backup del database, importa una volta <code>sql/migration_2_7_1_lombard_accrued_interest.sql</code>.</div>
    <?php else: ?>
        <div class="lombard-control-grid">
            <?php foreach ($creditLombardLines as $line): $profile = account_tracker_credit_line_profile($line); ?>
                <form method="post" enctype="multipart/form-data" class="lombard-control-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="check_credit_lombard">
                    <input type="hidden" name="credit_line_id" value="<?= (int) $line['id'] ?>">
                    <div><strong><?= e((string) $line['name']) ?></strong><br><span class="tiny-note">Dossier atteso: <?= e((string) ($profile['pledge_dossier_id'] ?? 'non configurato')) ?></span></div>
                    <label>Export patrimonio Credit Lombard<input type="file" name="lombard_patrimony_file" accept=".xls,.xlsx" required></label>
                    <button type="submit">Controlla</button>
                </form>
            <?php endforeach; ?>
        </div>
        <p class="tiny-note">Il file non viene conservato. Sono archiviati soltanto valori, provenienza ed esito del controllo; patrimonio e credito non vengono sommati.</p>
        <?php if ($controlHistory): ?>
            <div class="table-wrap lombard-control-history"><table><thead><tr><th>Data</th><th>Rapporto</th><th>Dossier rilevato</th><th>Portafoglio file</th><th>Mercato importato</th><th>Rateo</th><th>Totale confrontato</th><th>Differenza</th><th>Conto tecnico</th><th>Esito</th></tr></thead><tbody>
            <?php foreach ($controlHistory as $check): $status = (string) $check['check_status']; ?>
                <tr><td><?= e(date('d/m/Y', strtotime((string) $check['check_date']))) ?><br><span class="tiny-note"><?= e((string) $check['source_file_name']) ?></span></td><td><?= e((string) $check['credit_line_name']) ?></td><td><?= e((string) $check['detected_dossier_identifier']) ?></td><td>EUR <?= number_format((float) $check['portfolio_total'], 2, ',', '.') ?></td><td><?= $check['expected_market_value'] !== null ? 'EUR ' . number_format((float) $check['expected_market_value'], 2, ',', '.') : 'Non disponibile' ?></td><td><?= $check['expected_accrued_interest'] !== null ? 'EUR ' . number_format((float) $check['expected_accrued_interest'], 2, ',', '.') : 'Non disponibile' ?></td><td><?= $check['expected_portfolio_total'] !== null ? 'EUR ' . number_format((float) $check['expected_portfolio_total'], 2, ',', '.') : 'Non disponibile' ?></td><td><?= $check['difference_amount'] !== null ? 'EUR ' . number_format((float) $check['difference_amount'], 2, ',', '.') : '-' ?></td><td>EUR <?= number_format((float) ($check['accounting_balance'] ?? 0), 2, ',', '.') ?></td><td><span class="status-pill <?= $status === 'COHERENT' ? 'status-pill-success' : ($status === 'DIFFERENCE' ? 'status-pill-error' : '') ?>"><?= e(credit_lombard_control_status_label($status)) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><div class="empty-inline">Nessun controllo ancora archiviato.</div><?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
