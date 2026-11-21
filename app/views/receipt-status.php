<?php
$status = $order['status'];
$stages = ['received', 'preparing', 'ready', 'completed'];
$currentStage = array_search($status, $stages, true);
?>
<div class="receipt-indicator" data-state="<?= escape($status) ?>"><span aria-hidden="true"></span><?= escape($statuses[$status]) ?></div>
<h2 id="receipt-title"><?= $status === 'cancelled' ? 'Pedido cancelado' : 'Retirada no balcão' ?></h2>
<?php if ($status === 'ready'): ?>
    <p>Seu pedido está pronto. Informe o código no balcão e faça o pagamento na retirada.</p>
<?php elseif ($status === 'completed'): ?>
    <p>Seu pedido foi retirado. Obrigado pela preferência!</p>
<?php elseif ($status === 'cancelled'): ?>
    <p>Este pedido foi cancelado pela loja.</p>
<?php else: ?>
    <p>Retire quando o status mudar para <strong><?= escape($statuses['ready']) ?></strong>. O pagamento é feito no balcão.</p>
    <?php if ($estimatedPickup = orderPickupTime($order)): ?>
        <p>Previsão de retirada <strong><?= escape($estimatedPickup) ?></strong></p>
    <?php endif; ?>
<?php endif; ?>
<?php if ($status !== 'cancelled'): ?>
    <?php if ($pickupAddress !== '' && $status !== 'completed'): ?>
        <p class="pickup-address"><?= escape($pickupAddress) ?></p>
    <?php endif; ?>
    <ol class="receipt-progress" aria-label="Etapas do pedido">
        <?php foreach ($stages as $index => $stage): ?>
            <li<?= $stage === $status ? ' aria-current="step"' : '' ?><?= $index < $currentStage ? ' class="is-done"' : '' ?>><?= escape($statuses[$stage]) ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
