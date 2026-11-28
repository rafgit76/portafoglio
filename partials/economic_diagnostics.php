<?php
$pneFormat = static fn(?float $value): string => $value === null ? 'N/D' : 'EUR ' . number_format($value, 2, ',', '.');
?>
<div class="pne-diagnostics">
    <?php if (!$economicDetail['reliable'] && $economicDetail['candidate'] !== null): ?>
        <p><strong>Ipotesi, non validata:</strong> <?= e($pneFormat($economicDetail['candidate'])) ?>. Esclusa dai confronti.</p>
    <?php endif; ?>
    <?php if ($economicDetail['issues'] !== []): ?><ul>
        <?php foreach ($economicDetail['issues'] as $issue): ?>
            <li><?= e($issue) ?><?= isset($economicDetail['checks'][$issue]) ? ': ' . e($pneFormat($economicDetail['checks'][$issue])) : '' ?></li>
        <?php endforeach; ?>
    </ul><?php endif; ?>
    <p>Fido incluso: <?= e($pneFormat($economicDetail['credit_included'])) ?> · Controllo analitico: <?= e($pneFormat($economicDetail['pne_reconstructed'])) ?> · Operazioni in corso: <?= e($pneFormat($economicDetail['pending_operations'] ?? null)) ?></p>
    <?php if (!empty($economicDetail['portfolio_source'])): ?><p>Origine portafoglio: <?= e($economicDetail['portfolio_source']) ?>.</p><?php endif; ?>
    <?php if (!empty($economicDetail['credit_used_source'])): ?><p>Utilizzo del fido: <?= e($economicDetail['credit_used_source']) ?>.</p><?php endif; ?>
    <?php if (!empty($economicDetail['manual_fields'])): ?><p>Alcuni campi dello snapshot contengono rettifiche manuali.</p><?php endif; ?>
</div>
