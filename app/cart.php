<?php

declare(strict_types=1);

require_once __DIR__ . '/limits.php';

function availableQuantities(array $quantities, array $products): array
{
    return array_filter(
        array_intersect_key($quantities, $products),
        // Keep existing carts editable if the order limit has since been lowered.
        static fn ($quantity): bool => is_int($quantity) && $quantity > 0,
    );
}

function updateCart(array &$quantities, array $products, array $input, string $csrf): array
{
    $token = $input['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($csrf, $token)) {
        return [403, 'A sessão mudou. Recarregue a página e tente novamente.'];
    }

    $action = $input['action'] ?? null;
    if (!in_array($action, ['add', 'increment', 'decrement', 'remove'], true)) {
        return [400, 'Ação inválida para o carrinho.'];
    }

    $id = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || !isset($products[$id])) {
        return [404, 'Esse produto não está disponível.'];
    }

    $quantity = $quantities[$id] ?? 0;
    $name = $products[$id]['name'];
    if ($action !== 'add' && $quantity === 0) {
        return [404, 'Esse produto já saiu do carrinho.'];
    }

    if ($action === 'remove' || ($action === 'decrement' && $quantity === 1)) {
        unset($quantities[$id]);
        return [200, "{$name} removido do carrinho."];
    }

    if ($action === 'decrement') {
        $quantities[$id] = $quantity - 1;
    } elseif (array_sum($quantities) < MAX_ORDER_ITEMS) {
        $quantities[$id] = $quantity + 1;
    } else {
        return [422, 'O limite é de ' . MAX_ORDER_ITEMS . ' unidades por pedido. Remova um item antes de adicionar outro.'];
    }

    if ($action === 'add') {
        return [200, "{$name} adicionado ao carrinho."];
    }

    return [200, "{$name}. Quantidade: {$quantities[$id]}."];
}

function cartSummary(array $quantities, array $products): array
{
    $cart = ['items' => [], 'count' => 0, 'totalCents' => 0];

    foreach ($quantities as $id => $quantity) {
        $product = $products[$id];
        $subtotal = $product['price_cents'] * $quantity;
        $cart['items'][] = ['product' => $product, 'quantity' => $quantity, 'subtotalCents' => $subtotal];
        $cart['count'] += $quantity;
        $cart['totalCents'] += $subtotal;
    }

    return $cart;
}

function cartChanges(array $previous, array $quantities, array $products, array $snapshot): string
{
    $removed = [];
    foreach (array_diff_key($previous, $quantities) as $id => $quantity) {
        $removed[] = $snapshot[$id]['name'] ?? 'Item removido';
    }
    $repriced = [];
    foreach ($quantities as $id => $quantity) {
        if (isset($snapshot[$id]) && $snapshot[$id]['price_cents'] !== $products[$id]['price_cents']) {
            $repriced[] = $products[$id]['name'];
        }
    }

    $notices = [];
    if ($removed) {
        $notices[] = 'Itens indisponíveis: ' . implode(', ', $removed) . '.';
    }
    if ($repriced) {
        $notices[] = 'Preços atualizados: ' . implode(', ', $repriced) . '.';
    }
    return $notices ? implode(' ', $notices) . ' Confira o novo total antes de enviar o pedido.' : '';
}

function rememberCart(array $quantities, array $products): void
{
    $_SESSION['cart'] = $quantities;
    $snapshot = [];
    foreach ($quantities as $id => $quantity) {
        $snapshot[$id] = ['name' => $products[$id]['name'], 'price_cents' => $products[$id]['price_cents']];
    }
    $_SESSION['cart_snapshot'] = $snapshot;
}
