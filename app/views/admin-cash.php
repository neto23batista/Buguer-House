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
<section class="admin-panel admin-detail" aria-labelledby="cash-payments">
    <h2 id="cash-payments">Recebimentos do dia</h2>
    <?php if (!$cashReport['payments']): ?><p>Nenhum recebimento registrado neste dia.</p><?php endif; ?>
    <?php $methods = paymentMethods(); ?>
    <?php foreach ($cashReport['payments'] as $payment):
        $id = (int) $payment['order_id'];
        $history = $cashReport['changes'][$id] ?? [];
        $method = $history[0]['new_method'] ?? $payment['method'];
        ?>
        <details class="cash-payment">
            <summary>
                <strong>#<?= escape($payment['public_code']) ?></strong> · <?= adminTime($payment['received_at'])->format('H:i') ?>
                · <?= price((int) $payment['amount_cents']) ?> · <?= escape($methods[$method]) ?>
            </summary>
            <p>Recebido por <?= escape($payment['received_by']) ?> · <a href="<?= escape(adminUrl(null, 1, $payment['public_code'])) ?>#pedido-detalhe">Ver pedido</a></p>
            <?php if ($history): ?>
                <h3>Correções</h3><ol>
                    <?php foreach ($history as $change): ?>
                        <li><?= escape($methods[$change['previous_method']]) ?> → <?= escape($methods[$change['new_method']]) ?>
                            · <?= escape($change['actor']) ?> · <?= adminTime($change['created_at'])->format('d/m/Y H:i') ?>
                            <p><?= escape($change['reason']) ?></p></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (empty($cashReport['day']['closed_at'])): ?>
                <form class="cash-correction" method="post">
                    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                    <input type="hidden" name="action" value="cash_correct">
                    <input type="hidden" name="business_date" value="<?= escape($cashDate) ?>">
                    <input type="hidden" name="order_id" value="<?= $id ?>">
                    <input type="hidden" name="revision" value="<?= (int) ($history[0]['revision'] ?? 0) ?>">
                    <label class="admin-editor-field">Nova forma de pagamento
                        <select name="payment_method" required><option value="">Selecione</option>
                            <?php foreach ($methods as $key => $label): ?>
                                <?php if ($key !== $method): ?><option value="<?= $key ?>"><?= escape($label) ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="admin-editor-field">Motivo da correção <input name="reason" required maxlength="240"></label>
                    <button class="admin-button admin-button-secondary">Corrigir recebimento</button>
                </form>
            <?php else: ?><p>Reabra o caixa para corrigir este recebimento.</p><?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>
<?php if ($cashReport['events']): ?>
    <section class="admin-panel admin-detail"><h2>Histórico do dia</h2><ul>
        <?php foreach ($cashReport['events'] as $event): ?>
            <li><?= $event['action'] === 'close' ? 'Fechamento' : 'Reabertura' ?> · <?= escape($event['actor']) ?>
            às <?= adminTime($event['created_at'])->format('H:i') ?> · Contado <?= price((int) $event['counted_cents']) ?> · Esperado <?= price((int) $event['expected_cents']) ?></li>
        <?php endforeach; ?>
    </ul></section>
<?php endif; ?>
