<?php
$kitchenStages = ['received' => 'Novos pedidos', 'preparing' => 'Em preparo', 'ready' => 'Prontos para retirada'];
$kitchenActions = [
    'received' => ['preparing', 'Iniciar preparo'],
    'preparing' => ['ready', 'Marcar como pronto'],
    'ready' => ['completed', 'Concluir retirada'],
];
$now = time();
$utc = new DateTimeZone('UTC');
?>
<?php foreach ($kitchenStages as $stage => $title): ?>
    <section class="kitchen-stage kitchen-stage-<?= $stage ?>" aria-labelledby="kitchen-<?= $stage ?>-title" data-kitchen-stage="<?= $stage ?>" data-kitchen-time="<?= $now ?>" data-kitchen-csrf="<?= escape($adminCsrf) ?>">
        <div class="kitchen-stage-heading">
            <h2 id="kitchen-<?= $stage ?>-title" tabindex="-1"><?= $title ?></h2>
            <span class="kitchen-count" aria-label="<?= count($stages[$stage]) ?> pedidos" data-kitchen-count><?= count($stages[$stage]) ?></span>
        </div>
        <p class="kitchen-stage-empty" data-kitchen-empty<?= $stages[$stage] ? ' hidden' : '' ?>>Nenhum pedido nesta etapa.</p>
        <ol class="kitchen-list" data-kitchen-list>
            <?php foreach ($stages[$stage] as $order): ?>
                <?php
                $created = new DateTimeImmutable($order['created_at'], $utc);
                $elapsed = intdiv(max(0, $now - $created->getTimestamp()), 60);
                $elapsedLabel = $elapsed >= 60 ? intdiv($elapsed, 60) . ' h ' . $elapsed % 60 . ' min' : $elapsed . ' min';
                $id = (int) $order['id'];
                $signature = hash('sha256', json_encode($order, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                [$next, $actionLabel] = $kitchenActions[$stage];
                ?>
                <li class="kitchen-ticket admin-panel" data-kitchen-order="<?= $id ?>" data-order-signature="<?= $signature ?>">
                    <div class="kitchen-ticket-heading">
                        <h3 tabindex="-1"><a href="./?pedido=<?= escape($order['public_code']) ?>#pedido-detalhe">#<?= escape($order['public_code']) ?></a></h3>
                        <time datetime="<?= $created->format(DATE_ATOM) ?>" data-kitchen-created="<?= $created->getTimestamp() ?>">Há <?= $elapsedLabel ?></time>
                    </div>
                    <ul class="kitchen-items">
                        <?php foreach ($order['items'] as $item): ?>
                            <li><strong><?= $item['quantity'] ?>×</strong><span><?= escape($item['name']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($order['notes'] !== ''): ?>
                        <div class="admin-order-note"><strong>Observações</strong><p><?= escape($order['notes']) ?></p></div>
                    <?php endif; ?>
                    <form class="kitchen-transition" method="post" action="cozinha.php" data-kitchen-form>
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                        <input type="hidden" name="order_id" value="<?= $id ?>">
                        <input type="hidden" name="expected_status" value="<?= $stage ?>">
                        <input type="hidden" name="next_status" value="<?= $next ?>">
                        <button class="admin-button admin-button-primary" type="submit"><?= $actionLabel ?></button>
                    </form>
                    <details class="kitchen-cancel">
                        <summary>Cancelar pedido</summary>
                        <form class="kitchen-cancel-form" method="post" action="cozinha.php" data-kitchen-form>
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                            <input type="hidden" name="order_id" value="<?= $id ?>">
                            <input type="hidden" name="expected_status" value="<?= $stage ?>">
                            <input type="hidden" name="next_status" value="cancelled">
                            <label for="cancel-reason-<?= $id ?>">Motivo do cancelamento</label>
                            <textarea id="cancel-reason-<?= $id ?>" name="cancel_reason" required maxlength="240" rows="2"></textarea>
                            <button class="admin-button admin-button-secondary" type="submit">Confirmar cancelamento</button>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endforeach; ?>
