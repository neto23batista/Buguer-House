<div class="admin-page-heading"><h1>Caixa</h1></div>
<form class="admin-panel report-filter" method="get">
    <input type="hidden" name="secao" value="caixa">
    <label class="admin-editor-field">Dia do recebimento <input type="date" name="dia" required max="<?= cashToday() ?>" value="<?= escape($cashDate) ?>"></label>
    <button class="admin-button admin-button-secondary">Consultar dia</button>
</form>
<dl class="report-metrics">
    <?php foreach (paymentMethods() as $key => $label): ?>
        <div><dt><?= $label ?></dt><dd><?= price($cashReport['totals'][$key]) ?></dd></div>
    <?php endforeach; ?>
    <div><dt>Total recebido</dt><dd><?= price(array_sum($cashReport['totals'])) ?></dd></div>
</dl>
<form class="admin-panel report-filter" method="post">
    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
    <input type="hidden" name="business_date" value="<?= escape($cashDate) ?>">
    <input type="hidden" name="snapshot" value="<?= escape($cashReport['snapshot']) ?>">
    <?php if ($cashReport['day']['closed_at'] ?? null): ?>
        <p>Fechado por <?= escape($cashReport['day']['closed_by']) ?> · Contado <?= price((int) $cashReport['day']['counted_cents']) ?>
        · Diferença <?= $cashReport['difference'] < 0 ? '−' : '+' ?><?= price(abs($cashReport['difference'])) ?></p>
        <button name="action" value="cash_reopen" class="admin-button admin-button-secondary">Reabrir caixa</button>
    <?php else: ?>
        <label class="admin-editor-field">Dinheiro das vendas contado, sem o fundo de troco (R$)
            <input name="counted" required inputmode="decimal" placeholder="0,00">
        </label>
        <button name="action" value="cash_close" class="admin-button admin-button-primary">Fechar caixa</button>
    <?php endif; ?>
</form>
<?php if ($cashReport['events']): ?>
    <section class="admin-panel admin-detail"><h2>Histórico do dia</h2><ul>
        <?php foreach ($cashReport['events'] as $event): ?>
            <li><?= $event['action'] === 'close' ? 'Fechamento' : 'Reabertura' ?> · <?= escape($event['actor']) ?>
            às <?= adminTime($event['created_at'])->format('H:i') ?> · Contado <?= price((int) $event['counted_cents']) ?> · Esperado <?= price((int) $event['expected_cents']) ?></li>
        <?php endforeach; ?>
    </ul></section>
<?php endif; ?>
