<?php if (!empty($cartReview)): ?>
    <p class="cart-review" role="status"><?= escape($cartReview) ?></p>
<?php endif; ?>
<p class="cart-review" data-cart-limit="<?= MAX_ORDER_ITEMS ?>" data-cart-count-total="<?= $cart['count'] ?>">
    <?php if ($cart['count'] >= MAX_ORDER_ITEMS): ?>
        <?= $cart['count'] > MAX_ORDER_ITEMS ? 'Seu carrinho excede o limite.' : 'Limite atingido.' ?>
        O limite é de <?= MAX_ORDER_ITEMS ?> unidades por pedido. Diminua as quantidades para adicionar outros itens.
    <?php else: ?>
        Até <?= MAX_ORDER_ITEMS ?> unidades por pedido.
    <?php endif; ?>
</p>
<p class="cart-review" data-cart-recovery role="alert" hidden>
    Não foi possível confirmar a última alteração. Confira o carrinho antes de continuar.
    <a href="./?carrinho=1#carrinho">Recarregar e conferir carrinho</a>
</p>
<?php if ($cart['items']): ?>
    <ul class="cart-items">
        <?php foreach ($cart['items'] as $item): ?>
            <?php $product = $item['product']; ?>
            <li class="cart-item">
                <?php if ($product['image'] !== ''): ?>
                    <img src="<?= escape(productImage($product)) ?>" alt="" width="72" height="72">
                <?php else: ?>
                    <span class="cart-item-placeholder" aria-hidden="true"><span class="brand-mark"><i></i></span></span>
                <?php endif; ?>
                <div class="cart-item-main">
                    <div class="cart-item-heading">
                        <h3><?= escape($product['name']) ?></h3>
                        <strong><?= price($item['subtotalCents']) ?></strong>
                    </div>
                    <div class="cart-item-controls">
                        <div class="stepper" role="group" aria-label="Quantidade de <?= escape($product['name']) ?>">
                            <form action="./?carrinho=1" method="post" data-cart-form>
                                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                <input type="hidden" name="action" value="decrement">
                                <button type="submit" aria-label="Diminuir quantidade de <?= escape($product['name']) ?>">−</button>
                            </form>
                            <span><?= $item['quantity'] ?></span>
                            <form action="./?carrinho=1" method="post" data-cart-form>
                                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                <input type="hidden" name="action" value="increment">
                                <button type="submit" aria-label="Aumentar quantidade de <?= escape($product['name']) ?>" <?= $cart['count'] >= MAX_ORDER_ITEMS ? 'disabled' : '' ?>>+</button>
                            </form>
                        </div>
                        <form action="./?carrinho=1" method="post" data-cart-form>
                            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <input type="hidden" name="action" value="remove">
                            <button class="remove-button" type="submit" aria-label="Remover <?= escape($product['name']) ?>">Remover</button>
                        </form>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <div class="cart-empty">
        <span class="bag-icon" aria-hidden="true"></span>
        <p>Seu carrinho está vazio.</p>
        <a href="./#cardapio" data-cart-close data-cart-menu>Ver cardápio <span aria-hidden="true">↗</span></a>
    </div>
<?php endif; ?>

<div class="cart-total">
    <span>Total</span>
    <strong data-cart-total><?= price($cart['totalCents']) ?></strong>
</div>
<?php if ($cart['items']): ?>
    <div class="cart-checkout">
        <?php if (!($storeStatus['accepting'] ?? false)): ?>
            <p role="status"><?= escape($storeStatus['message'] ?? 'Não foi possível consultar a disponibilidade da loja.') ?></p>
            <a href="./?carrinho=1#carrinho">Consultar disponibilidade</a>
        <?php elseif ($cart['count'] <= MAX_ORDER_ITEMS): ?>
            <?php if (!empty($storeStatus['last_order_label'])): ?>
                <p>Último pedido do turno às <?= escape($storeStatus['last_order_label']) ?>.</p>
            <?php endif; ?>
            <a class="primary-button" href="checkout.php" data-checkout>Finalizar pedido <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <p role="alert">Diminua as quantidades para finalizar o pedido.</p>
        <?php endif; ?>
        <p>Retirada no balcão · Pagamento na retirada</p>
    </div>
<?php endif; ?>
