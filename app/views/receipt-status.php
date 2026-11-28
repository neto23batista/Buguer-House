<?php
$status = $order['status'];
$stages = ['received', 'preparing', 'ready', 'completed'];
$currentStage = array_search($status, $stages, true);
?>
<?php if (!empty($order['ticket_number'])): ?>
    <p><strong>Senha de retirada <?= (int) $order['ticket_number'] ?></strong> · <?= date('d/m', strtotime($order['pickup_day'])) ?></p>
<?php endif; ?>
<div class="receipt-indicator" data-state="<?= escape($status) ?>"><span aria-hidden="true"></span><?= escape($statuses[$status]) ?></div>
<h2 id="receipt-title"><?= $status === 'cancelled' ? 'Pedido cancelado' : 'Retirada no balcão' ?></h2>
<?php if ($status === 'ready'): ?>
    <p>Seu pedido está pronto. Informe <?= !empty($order['ticket_number']) ? 'a senha' : 'o código' ?> no balcão e faça o pagamento na retirada.</p>
<?php elseif ($status === 'completed'): ?>
    <p>Seu pedido foi retirado. Obrigado pela preferência!</p>
    <?php if (!empty($order['payment_method'])): ?><p>Pagamento recebido · <?= escape(paymentMethods()[$order['payment_method']]) ?></p><?php endif; ?>
<?php elseif ($status === 'cancelled'): ?>
    <p>Este pedido foi cancelado pela loja.</p>
    <?php if (!empty($order['customer_reason'])): ?><p>Motivo · <?= escape($order['customer_reason']) ?></p><?php endif; ?>
    <?php if ($contact = presentationContact()): ?>
        <p><a href="<?= escape($contact['href']) ?>">Falar com a loja · <?= escape($contact['label']) ?></a></p>
    <?php endif; ?>
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
