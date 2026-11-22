<?php

declare(strict_types=1);

require __DIR__ . '/../app/catalog.php';
require __DIR__ . '/../app/cart.php';
require __DIR__ . '/../app/session.php';
require __DIR__ . '/../app/orders.php';
require __DIR__ . '/../app/presentation.php';
require_once __DIR__ . '/../app/assets.php';

startBurgerSession();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$receipts = $_SESSION['order_receipts'] ?? [];
$isReceipt = array_key_exists('pedido', $_GET);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$order = null;
$lines = [];
$customer = ['name' => '', 'phone' => '', 'notes' => ''];
$errors = [];
$unavailable = false;
$cart = ['items' => [], 'count' => 0, 'totalCents' => 0];
$draft = null;
$polling = $isReceipt && ($_GET['atualizacao'] ?? null) === '1';
$receiptUrl = null;
$privateUrl = null;
$retryUrl = 'checkout.php';
$pickupAddress = trim(databaseSetting('STORE_PICKUP_ADDRESS', ''));
$store = null;
$availability = null;
$cartReview = $_SESSION['cart_review'] ?? '';
$statuses = orderStatuses();
$isDemo = presentationDemoMode();

try {
    if ($isReceipt) {
        $code = $_GET['pedido'];
        if (is_string($code) && preg_match('/\A[A-F0-9]{16}\z/', $code)) {
            $originalToken = array_search($code, $receipts, true);
            $sentKey = $_GET['chave'] ?? null;
            $sentKey = is_string($sentKey) && preg_match('/\A[a-f0-9]{64}\z/', $sentKey) ? $sentKey : null;
            $retryUrl = orderReceiptUrl($code, $sentKey);
            $db = databaseConnection();
            $candidate = orderTrackingDetails($db, $code);
            if ($candidate && ($originalToken !== false || canReadOrderTracking($candidate, $sentKey))) {
                $order = $candidate;
                $pickupAddress = $order['pickup_address'] ?? $pickupAddress;
                if ($order['is_current']) {
                    $trackingKey = $originalToken !== false ? orderTrackingToken($originalToken) : $sentKey;
                    $privateUrl = orderReceiptUrl($code, $trackingKey);
                }
                $receiptUrl = $originalToken !== false ? orderReceiptUrl($code) : $privateUrl;
                $lines = $polling ? [] : listOrderItems($db, (int) $order['id']);
            }
        }
        if (!$order) {
            http_response_code(404);
        }
    } else {
        $db = databaseConnection();
        $store = loadStore($db);
        $availability = storeAvailability($store);
        $pickupAddress = $store['pickup_address'];
        $products = loadProducts($db);
        $rawCart = $_SESSION['cart'] ?? [];
        $quantities = availableQuantities($rawCart, $products);
        $detectedChanges = cartChanges($rawCart, $quantities, $products, $_SESSION['cart_snapshot'] ?? []);
        if ($detectedChanges !== '') {
            $cartReview = $_SESSION['cart_review'] = $detectedChanges;
        }
        $cart = cartSummary($quantities, $products);
        $fingerprint = cartFingerprint($cart);
        $previousDraft = $_SESSION['checkout_draft'] ?? null;

        if ($isPost) {
            $sentCsrf = $_POST['csrf'] ?? null;
            $sentToken = $_POST['checkout_token'] ?? null;
            $validCsrf = is_string($sentCsrf) && hash_equals($csrf, $sentCsrf);

            if ($validCsrf && is_string($sentToken) && isset($receipts[$sentToken])) {
                header('Location: checkout.php?pedido=' . $receipts[$sentToken], true, 303);
                exit;
            }

            foreach (['name' => 400, 'phone' => 64, 'notes' => 1200] as $field => $byteLimit) {
                $value = $_POST[$field] ?? null;
                if (is_string($value)) {
                    $customer[$field] = trim(substr($value, 0, $byteLimit + 1));
                }
            }

            if (!$validCsrf) {
                http_response_code(403);
                $errors['form'] = 'Sua sessão mudou. Revise os dados e tente novamente.';
            } elseif (!$previousDraft || !is_string($sentToken) || !hash_equals($previousDraft['token'], $sentToken)) {
                http_response_code(409);
                $errors['form'] = 'Este checkout foi atualizado. Confira o resumo antes de enviar.';
            } elseif ($previousDraft['fingerprint'] !== $fingerprint) {
                http_response_code(409);
                $errors['form'] = 'Seu carrinho mudou. Confira os itens e os valores atualizados.';
            } elseif (!is_string($previousDraft['pickup_address'] ?? null)) {
                http_response_code(409);
                $errors['form'] = 'Confira o endereço de retirada antes de enviar o pedido.';
            }

            if (!isset($errors['form']) && $rawCart && count($rawCart) !== count($cart['items'])) {
                http_response_code(409);
                $errors['form'] = 'Um item ficou indisponível. Confira o carrinho atualizado antes de enviar.';
            }

            if (!preg_match('/\A[^\p{C}]{2,100}\z/u', $customer['name'])) {
                $errors['name'] = 'Informe um nome entre 2 e 100 caracteres.';
            }

            $phone = preg_replace('/\D/', '', $customer['phone']);
            if (strlen($phone) >= 12 && str_starts_with($phone, '55')) {
                $phone = substr($phone, 2);
            }
            if (!preg_match('/\A\+?[0-9 ().-]{10,25}\z/', $customer['phone']) || !preg_match('/\A[1-9][0-9]{9,10}\z/', $phone)) {
                $errors['phone'] = 'Informe um telefone brasileiro com DDD.';
            }

            $customer['notes'] = str_replace(["\r\n", "\r"], "\n", $customer['notes']);
            if (!preg_match('/\A(?:[^\p{C}]|[\n\t]){0,300}\z/u', $customer['notes'])) {
                $errors['notes'] = 'Use até 300 caracteres nas observações.';
            }

            if (!$errors && $cart['items']) {
                $details = $customer;
                $details['phone'] = $phone;
                try {
                    $order = placeOrder(
                        $db, $sentToken, $details, $rawCart, $fingerprint,
                        $previousDraft['pickup_address'], clientAddress(),
                    );
                    $receipts[$sentToken] = $order['public_code'];
                    $_SESSION['order_receipts'] = array_slice($receipts, -20, null, true);
                    unset($_SESSION['cart'], $_SESSION['checkout_draft'], $_SESSION['cart_snapshot'], $_SESSION['cart_review']);
                    session_write_close();
                    header('Location: ' . orderReceiptUrl($order['public_code']), true, 303);
                    exit;
                } catch (OrderRateLimitException $exception) {
                    $retryAfter = $exception->retryAfter;
                    http_response_code($retryAfter === null ? 422 : 429);
                    if ($retryAfter !== null) {
                        header('Retry-After: ' . $retryAfter);
                    }
                    $errors['form'] = $exception->getMessage();
                } catch (DomainException $exception) {
                    http_response_code(409);
                    $errors['form'] = $exception->getMessage();
                    $store = loadStore($db);
                    $availability = storeAvailability($store);
                    $pickupAddress = $store['pickup_address'];
                    $products = loadProducts($db);
                    $quantities = availableQuantities($rawCart, $products);
                    $cart = cartSummary($quantities, $products);
                    $fingerprint = cartFingerprint($cart);
                }
            } elseif ($errors && !isset($errors['form'])) {
                http_response_code(422);
            }
        }

        rememberCart($quantities, $products);
        if ($cart['items']) {
            $sameDraft = ($previousDraft['fingerprint'] ?? null) === $fingerprint
                && ($previousDraft['pickup_address'] ?? null) === $pickupAddress;
            $draft = $sameDraft
                ? $previousDraft
                : ['token' => bin2hex(random_bytes(32)), 'fingerprint' => $fingerprint, 'pickup_address' => $pickupAddress];
            $_SESSION['checkout_draft'] = $draft;
        } else {
            unset($_SESSION['checkout_draft']);
        }
    }
} catch (PDOException $exception) {
    error_log('Burger House checkout: ' . $exception->getMessage());
    $unavailable = true;
    http_response_code(503);
}

session_write_close();
$title = $isReceipt ? 'Acompanhar pedido' : 'Finalizar pedido';
if ($polling) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$order || $unavailable) {
        echo '{}';
    } else {
        ob_start();
        require __DIR__ . '/../app/views/receipt-status.php';
        echo json_encode(['status' => $order['status'], 'html' => ob_get_clean()], JSON_THROW_ON_ERROR);
    }
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fbf7ed">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $title ?> | Burger House</title>
    <?php renderPresentationHead($title . ' | Burger House', ''); ?>
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/customer.css')) ?>">
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/checkout.css')) ?>">
    <?php if ($order): ?><script src="<?= escape(assetUrl('assets/js/tracking.js')) ?>" defer></script><?php endif; ?>
    <?php if ($draft && !$isReceipt): ?><script src="<?= escape(assetUrl('assets/js/checkout.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="customer checkout-page">
    <header class="site-header">
        <div class="shell checkout-header">
            <a class="brand" href="./" aria-label="Burger House — início">
                <span class="brand-mark" aria-hidden="true"><i></i></span>
                <span class="brand-name">BURGER <b>HOUSE</b></span>
            </a>
            <a class="checkout-return" href="./#cardapio"><span aria-hidden="true">←</span> Cardápio</a>
        </div>
    </header>

    <main class="shell checkout-main">
        <?php if ($isDemo): ?>
            <aside class="demo-notice" aria-label="Demonstração do projeto">
                <strong>Pedido de demonstração</strong>
                <p>Este projeto faz parte de um portfólio. Use dados fictícios: por exemplo, Cliente Demo e (11) 90000-0000. Nenhuma compra real será realizada.</p>
            </aside>
        <?php endif; ?>
        <?php if ($unavailable): ?>
            <div class="checkout-state checkout-card">
                <h1>Pedido indisponível</h1>
                <p>O serviço está temporariamente indisponível. Tente novamente em instantes.</p>
                <a class="primary-button" href="<?= escape($retryUrl) ?>">Tentar novamente</a>
            </div>
        <?php elseif ($isReceipt && !$order): ?>
            <div class="checkout-state checkout-card">
                <h1>Pedido não encontrado</h1>
                <p>Abra a confirmação no navegador em que fez o pedido ou use o link privado dentro do prazo de validade.</p>
                <a class="primary-button" href="./#cardapio">Voltar ao cardápio</a>
            </div>
        <?php elseif ($isReceipt): ?>
            <div class="checkout-intro">
                <p class="checkout-kicker">Pedido #<?= escape($order['public_code']) ?></p>
                <h1>Acompanhe seu pedido</h1>
            </div>
            <div class="checkout-grid">
                <section class="checkout-card receipt-status" aria-labelledby="receipt-title">
                    <div data-receipt-status data-state="<?= escape($order['status']) ?>" aria-live="polite">
                        <?php require __DIR__ . '/../app/views/receipt-status.php'; ?>
                    </div>
                    <div class="receipt-tools">
                        <a data-receipt-refresh class="checkout-text-link" href="<?= escape($receiptUrl) ?>" <?= in_array($order['status'], ['completed', 'cancelled'], true) ? 'hidden' : '' ?>>Atualizar status <span aria-hidden="true">↻</span></a>
                        <?php if ($privateUrl !== null): ?>
                            <a data-private-receipt class="checkout-text-link" href="<?= escape($privateUrl) ?>">Link privado</a>
                            <button data-copy-receipt type="button" hidden>Copiar link</button>
                            <p data-copy-status role="status"></p>
                        <?php endif; ?>
                    </div>
                    <p class="receipt-connection" data-receipt-connection role="status" hidden></p>
                </section>
                <aside class="checkout-card checkout-summary" aria-labelledby="summary-title">
                    <h2 id="summary-title">Resumo do pedido</h2>
                    <ul class="summary-lines">
                        <?php foreach ($lines as $line): ?>
                            <li><span class="line-name"><span class="line-quantity"><?= (int) $line['quantity'] ?>×</span> <?= escape($line['product_name']) ?></span><strong><?= price((int) $line['unit_price_cents'] * (int) $line['quantity']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="summary-total"><span>Total</span><strong><?= price((int) $order['total_cents']) ?></strong></div>
                    <a class="checkout-text-link" href="./#cardapio">Voltar ao cardápio <span aria-hidden="true">→</span></a>
                </aside>
            </div>
        <?php elseif (!$draft): ?>
            <div class="checkout-state checkout-card">
                <h1>Seu carrinho está vazio</h1>
                <?php if (isset($errors['form'])): ?><p role="alert"><?= escape($errors['form']) ?></p><?php endif; ?>
                <?php if ($cartReview !== ''): ?><p role="status"><?= escape($cartReview) ?></p><?php endif; ?>
                <p>Escolha os itens no cardápio para fazer um pedido.</p>
                <a class="primary-button" href="./#cardapio">Ver cardápio <span aria-hidden="true">→</span></a>
            </div>
        <?php else: ?>
            <div class="checkout-intro">
                <a class="checkout-text-link" href="./?carrinho=1#carrinho"><span aria-hidden="true">←</span> Voltar ao carrinho</a>
                <h1>Finalize seu pedido</h1>
            </div>
            <?php if ($errors): ?>
                <p class="checkout-alert" role="alert"><?= escape($errors['form'] ?? 'Confira os campos destacados antes de enviar.') ?></p>
            <?php endif; ?>
            <?php if (!$availability['accepting'] && ($errors['form'] ?? null) !== $availability['message']): ?>
                <p class="checkout-alert" role="status"><?= escape($availability['message']) ?></p>
            <?php endif; ?>
            <?php if ($cartReview !== ''): ?><p class="checkout-alert" role="status"><?= escape($cartReview) ?></p><?php endif; ?>
            <form class="checkout-grid" method="post" action="checkout.php" data-checkout-form>
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                <input type="hidden" name="checkout_token" value="<?= escape($draft['token']) ?>">
                <section class="checkout-card customer-card" aria-labelledby="customer-title">
                    <h2 id="customer-title">Seus dados</h2>
                    <?php if ($isDemo): ?><p class="demo-fields-hint">Preencha somente dados fictícios para testar.</p><?php endif; ?>
                    <div class="checkout-field">
                        <label for="customer-name">Nome</label>
                        <input id="customer-name" name="name" required maxlength="100" autocomplete="<?= $isDemo ? 'off' : 'name' ?>" value="<?= escape($customer['name']) ?>" <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
                        <?php if (isset($errors['name'])): ?><span class="field-error" id="name-error"><?= escape($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="checkout-field">
                        <label for="customer-phone">Telefone com DDD</label>
                        <input id="customer-phone" name="phone" type="tel" required maxlength="25" autocomplete="<?= $isDemo ? 'off' : 'tel' ?>" placeholder="<?= $isDemo ? '(11) 90000-0000' : '(11) 91234-5678' ?>" value="<?= escape($customer['phone']) ?>" <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>>
                        <?php if (isset($errors['phone'])): ?><span class="field-error" id="phone-error"><?= escape($errors['phone']) ?></span><?php endif; ?>
                    </div>
                    <div class="checkout-field">
                        <label for="customer-notes">Observações <span>(opcional)</span></label>
                        <textarea id="customer-notes" name="notes" maxlength="300" rows="3" placeholder="Ex.: sem picles" <?= isset($errors['notes']) ? 'aria-invalid="true" aria-describedby="notes-error"' : '' ?>><?= escape($customer['notes']) ?></textarea>
                        <?php if (isset($errors['notes'])): ?><span class="field-error" id="notes-error"><?= escape($errors['notes']) ?></span><?php endif; ?>
                    </div>
                </section>
                <aside class="checkout-card checkout-summary" aria-labelledby="summary-title">
                    <h2 id="summary-title">Resumo do pedido</h2>
                    <ul class="summary-lines">
                        <?php foreach ($cart['items'] as $item): ?>
                            <li><span class="line-name"><span class="line-quantity"><?= $item['quantity'] ?>×</span> <?= escape($item['product']['name']) ?></span><strong><?= price($item['subtotalCents']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                    <dl class="summary-method">
                        <div><dt>Recebimento</dt><dd>Retirada no balcão</dd></div>
                        <?php if ($pickupAddress !== ''): ?>
                            <div><dt>Endereço</dt><dd><?= escape($pickupAddress) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Pagamento</dt><dd>Na retirada</dd></div>
                        <?php if ($availability['accepting']): ?>
                            <div><dt>Preparo estimado</dt><dd><?= $store['prep_minutes'] ?> min</dd></div>
                        <?php endif; ?>
                    </dl>
                    <div class="summary-total"><span>Total</span><strong><?= price($cart['totalCents']) ?></strong></div>
                    <button class="primary-button submit-order" type="submit" data-submit-order <?= $availability['accepting'] ? '' : 'disabled' ?>><?= $isDemo ? 'Enviar pedido de teste' : 'Enviar pedido' ?> <span aria-hidden="true">→</span></button>
                    <p role="status" data-checkout-progress hidden></p>
                </aside>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
