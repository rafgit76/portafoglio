<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Gestione fidi';
$pdo = db();

if (!account_tracker_schema_ready($pdo)) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state"><h2>Modulo conto da inizializzare</h2><p>Se il modulo non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; poi importa <code>sql/migration_2_4_0_credit_lombard_semantics.sql</code> dopo un export completo da phpMyAdmin.</p></section>
    <?php include __DIR__ . '/partials/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
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
            ':activation_date' => trim((string) ($_POST['activation_date'] ?? '')) ?: null,
            ':status' => $status,
            ':dossier_id' => $dossierId,
            ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        $pdo->beginTransaction();
        if ($id > 0) {
            $data[':id'] = $id;
            $statement = $pdo->prepare('UPDATE pf_credit_lines SET name=:name, bank_name=:bank_name, credit_type=:credit_type, agreed_amount=:agreed_amount, used_amount=:used_amount, annual_rate=:annual_rate, rate_formula=:rate_formula, activation_date=:activation_date, status=:status, dossier_id=:dossier_id, notes=:notes WHERE id=:id');
            $statement->execute($data);
        } else {
            $statement = $pdo->prepare('INSERT INTO pf_credit_lines (name, bank_name, credit_type, agreed_amount, used_amount, annual_rate, rate_formula, activation_date, status, dossier_id, notes) VALUES (:name,:bank_name,:credit_type,:agreed_amount,:used_amount,:annual_rate,:rate_formula,:activation_date,:status,:dossier_id,:notes)');
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
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
foreach ($lines as $line) { if ((int) $line['id'] === $editId) { $editing = $line; break; } }
$value = static fn(string $key, string $fallback = ''): string => e((string) ($editing[$key] ?? $fallback));

include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero"><div class="panel-head"><div><p class="eyebrow">GESTIONE</p><h2>Fidi e linee di credito</h2><p>Accordato, utilizzato e disponibile sono sempre separati dal patrimonio.</p></div><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Conto e patrimonio</a></div></section>
<section class="panel"><div class="panel-head"><h2><?= $editing ? 'Modifica linea di credito' : 'Nuova linea di credito' ?></h2><span class="tiny-note">L\'importo disponibile e accordato meno utilizzato.</span></div>
<form method="post" class="form-grid account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="credit_line_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
<label>Nome<input name="name" required value="<?= $value('name') ?>" placeholder="Es. Credit Lombard"></label><label>Banca<input name="bank_name" value="<?= $value('bank_name') ?>" placeholder="Es. Fineco"></label>
<label>Tipo<select name="credit_type"><?php foreach (['CREDIT_LOMBARD'=>'Credit Lombard','MINIFIDO'=>'Minifido','FIDO_ORDINARIO'=>'Fido ordinario','ALTRO'=>'Altro'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editing['credit_type'] ?? 'ALTRO') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Dossier collegato<select name="dossier_id"><option value="">Nessuno</option><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= (int) ($editing['dossier_id'] ?? 0) === (int) $dossier['id'] ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
<label>Importo accordato<input name="agreed_amount" inputmode="decimal" value="<?= $value('agreed_amount','0') ?>"></label><label>Importo utilizzato<input name="used_amount" inputmode="decimal" value="<?= $value('used_amount','0') ?>"></label>
<label>Tasso annuo %<input name="annual_rate" inputmode="decimal" value="<?= $value('annual_rate') ?>"></label><label>Formula tasso<input name="rate_formula" value="<?= $value('rate_formula') ?>" placeholder="Es. Euribor 3M + spread"></label>
<label>Data attivazione<input type="date" name="activation_date" value="<?= $value('activation_date') ?>"></label><label>Data rilevazione utilizzo<input type="date" name="usage_snapshot_date" value="<?= e(date('Y-m-d')) ?>"></label>
<label>Stato<select name="status"><?php foreach (['ACTIVE'=>'Attivo','PAUSED'=>'Sospeso','CLOSED'=>'Chiuso'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editing['status'] ?? 'ACTIVE') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label class="checkbox-label"><input type="checkbox" name="allow_over_agreement" value="1"> Confermo un utilizzato superiore all'accordato</label>
<label class="form-full">Note<textarea name="notes" rows="3"><?= $value('notes') ?></textarea></label><div class="form-full"><button type="submit">Salva linea di credito</button></div></form></section>
<section class="panel"><h2>Linee registrate</h2><?php if (!$lines): ?><p>Nessuna linea di credito configurata.</p><?php else: ?><table><thead><tr><th>Nome</th><th>Tipo</th><th>Accordato</th><th>Utilizzato</th><th>Disponibile</th><th>Stato</th><th></th></tr></thead><tbody><?php foreach ($lines as $line): ?><tr><td><strong><?= e((string) $line['name']) ?></strong><br><span class="tiny-note"><?= e((string) ($line['dossier_name'] ?? '')) ?></span></td><td><?= e(str_replace('_',' ',(string) $line['credit_type'])) ?></td><td>EUR <?= number_format((float) $line['agreed_amount'],2,',','.') ?></td><td class="<?= (float) $line['used_amount'] > 0 ? 'neg' : '' ?>">EUR <?= number_format((float) $line['used_amount'],2,',','.') ?></td><td>EUR <?= number_format((float) $line['available_amount'],2,',','.') ?></td><td><?= e((string) $line['status']) ?></td><td><a class="btn-link" href="<?= e(app_base_path()) ?>/credit_lines.php?edit=<?= (int) $line['id'] ?>">Modifica</a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php include __DIR__ . '/partials/footer.php'; ?>
