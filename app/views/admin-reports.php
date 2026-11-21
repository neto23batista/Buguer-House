<div class="admin-page-heading"><h1>Relatórios</h1></div>
<form class="admin-panel report-filter" method="get" action="./">
    <input type="hidden" name="secao" value="relatorios">
    <label class="admin-editor-field">De <input name="inicio" type="date" value="<?= escape($period['start']) ?>" required></label>
    <label class="admin-editor-field">Até <input name="fim" type="date" value="<?= escape($period['end']) ?>" required></label>
    <button class="admin-button admin-button-primary" type="submit">Aplicar período</button>
</form>

<?php if ($period['error'] !== null): ?>
    <p class="admin-alert admin-alert-error" role="alert"><?= escape($period['error']) ?></p>
<?php elseif ($report === null): ?>
    <p class="admin-alert admin-alert-error" role="alert">O relatório está temporariamente indisponível.</p>
<?php else: ?>
    <?php $totals = $report['totals']; ?>
    <dl class="report-metrics" aria-label="Indicadores dos pedidos feitos no período">
        <div><dt>Pedidos feitos</dt><dd><?= (int) $totals['received'] ?></dd></div>
        <div><dt>Concluídos</dt><dd><?= (int) $totals['completed'] ?></dd></div>
        <div><dt>Cancelados</dt><dd><?= (int) $totals['cancelled'] ?></dd></div>
        <div><dt>Valor concluído</dt><dd><?= price((int) $totals['total']) ?></dd></div>
        <div><dt>Ticket médio concluído</dt><dd><?= $totals['completed'] ? price((int) round($totals['total'] / $totals['completed'])) : '—' ?></dd></div>
    </dl>
    <div class="admin-layout">
        <section class="admin-panel admin-detail" aria-labelledby="report-products">
            <h2 id="report-products">Mais vendidos</h2>
            <?php if ($report['products']): ?>
                <?php $names = array_count_values(array_column($report['products'], 'product_name')); ?>
                <ul class="report-list">
                    <?php foreach ($report['products'] as $product): ?>
                        <li>
                            <span>
                                <?= escape($product['product_name']) ?>
                                <?php if ($names[$product['product_name']] > 1): ?>
                                    <small>Produto #<?= (int) $product['product_id'] ?></small>
                                <?php endif; ?>
                            </span>
                            <strong><?= (int) $product['units'] ?> un.</strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="report-description">Os itens de pedidos concluídos aparecerão aqui.</p>
            <?php endif; ?>
        </section>
        <section class="admin-panel admin-detail" aria-labelledby="report-cancellations">
            <h2 id="report-cancellations">Motivos de cancelamento</h2>
            <?php if ($report['cancellations']): ?>
                <ul class="report-list">
                    <?php foreach ($report['cancellations'] as $entry): ?>
                        <li><span><?= escape($entry['reason']) ?></span><strong><?= (int) $entry['orders_count'] ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="report-description">Os motivos registrados no período aparecerão aqui.</p>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
