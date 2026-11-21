<?php

declare(strict_types=1);

require __DIR__ . '/../app/catalog.php';
require __DIR__ . '/../app/cart.php';
require __DIR__ . '/../app/order-rate-limit.php';

$checks = 0;
function checkCart(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$products = [
    1 => ['id' => 1, 'name' => 'House Smash', 'price_cents' => 2890, 'image' => '', 'image_alt' => ''],
    2 => ['id' => 2, 'name' => 'Fritas', 'price_cents' => 1490, 'image' => '', 'image_alt' => ''],
];
$quantities = [];
$change = static function (string $action, int $id = 1, string $csrf = 'token') use (&$quantities, $products): array {
    return updateCart($quantities, $products, ['csrf' => $csrf, 'action' => $action, 'product_id' => (string) $id], 'token');
};

for ($unit = 0; $unit < MAX_ORDER_ITEMS; $unit++) {
    [$status] = $change('add', $unit % 2 + 1);
    checkCart($status === 200, 'Uma unidade dentro do limite deve ser aceita.');
}
checkCart(array_sum($quantities) === MAX_ORDER_ITEMS, 'O limite deve considerar todos os produtos.');
$before = $quantities;
[$status, $message] = $change('increment', 2);
checkCart($status === 422 && $quantities === $before, 'Incrementar acima do limite não pode alterar o carrinho.');
checkCart(str_contains($message, (string) MAX_ORDER_ITEMS), 'A mensagem deve informar o limite compartilhado.');
[$status] = $change('add', 1);
checkCart($status === 422 && $quantities === $before, 'Adicionar outro produto também deve respeitar o total.');
[$status] = $change('decrement', 1);
checkCart($status === 200 && array_sum($quantities) === MAX_ORDER_ITEMS - 1, 'Diminuir deve funcionar no limite.');
[$status] = $change('add', 2);
checkCart($status === 200 && array_sum($quantities) === MAX_ORDER_ITEMS, 'Uma vaga liberada deve poder ser usada por outro produto.');

$quantities = [1 => MAX_ORDER_ITEMS + 1];
checkCart(availableQuantities($quantities, $products) === $quantities, 'Um carrinho antigo acima do limite deve continuar editável.');
[$status] = $change('decrement');
checkCart($status === 200 && $quantities[1] === MAX_ORDER_ITEMS, 'Carrinhos antigos devem poder voltar ao limite.');
$quantities = [1 => MAX_ORDER_ITEMS + 1];
[$status] = $change('remove');
checkCart($status === 200 && $quantities === [], 'Remover deve funcionar mesmo acima do limite.');
$quantities = [1 => 2];
[$status] = $change('add', 1, 'invalid');
checkCart($status === 403 && $quantities === [1 => 2], 'Uma sessão inválida não pode alterar o carrinho.');
checkCart(availableQuantities([1 => -1, 2 => '2', 999 => 1], $products) === [], 'Quantidades inválidas e produtos ausentes devem ser descartados.');

function renderTestCart(array $quantities, array $products): string
{
    $cart = cartSummary($quantities, $products);
    $csrf = 'token';
    $cartReview = '';
    ob_start();
    require __DIR__ . '/../app/views/cart.php';
    return ob_get_clean();
}

$html = renderTestCart([1 => 20, 2 => 20], $products);
checkCart(substr_count($html, 'disabled') === 2, 'Todos os incrementos devem estar desabilitados quando o total chegar ao limite.');
checkCart(str_contains($html, 'data-checkout'), 'Um pedido exatamente no limite pode ser finalizado.');
checkCart(str_contains($html, 'Limite atingido.'), 'O carrinho deve explicar por que os incrementos estão desabilitados.');
$html = renderTestCart([1 => 20, 2 => 19], $products);
checkCart(!str_contains($html, ' disabled'), 'Os incrementos devem estar disponíveis abaixo do limite.');
$html = renderTestCart([1 => MAX_ORDER_ITEMS + 1], $products);
checkCart(!str_contains($html, 'data-checkout'), 'Carrinhos antigos acima do teto devem ser ajustados antes do checkout.');
checkCart(str_contains($html, 'Recarregar e conferir carrinho'), 'A recuperação deve oferecer um link GET para conferir o estado real.');

echo "Carrinho PHP: {$checks} verificações passaram.", PHP_EOL;
