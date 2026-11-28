<?php

declare(strict_types=1);

require __DIR__ . '/../app/catalog.php';
require __DIR__ . '/../app/cart.php';
require __DIR__ . '/../app/session.php';
require __DIR__ . '/../app/store.php';
require __DIR__ . '/../app/presentation.php';
require_once __DIR__ . '/../app/assets.php';

startBurgerSession();

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isJson = $isPost && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$cartPage = ($_GET['carrinho'] ?? null) === '1';
$catalogAvailable = true;
$storeStatus = null;
$store = null;

try {
    $database = databaseConnection();
    $products = loadProducts($database);
} catch (PDOException $exception) {
    error_log('Burger House: ' . $exception->getMessage());
    $catalogAvailable = false;
    $products = [];
}

if ($catalogAvailable) {
    try {
        $store = loadStore($database);
        $storeStatus = storeAvailability($store);
    } catch (PDOException $exception) {
        error_log('Burger House disponibilidade: ' . $exception->getMessage());
    }
}

$previousCart = $_SESSION['cart'] ?? [];
$quantities = availableQuantities($previousCart, $products);
$cartReview = $_SESSION['cart_review'] ?? '';
if ($catalogAvailable) {
    $changes = cartChanges($previousCart, $quantities, $products, $_SESSION['cart_snapshot'] ?? []);
    if ($changes !== '') {
        $cartReview = $_SESSION['cart_review'] = $changes;
    }
}
$catalogError = 'O cardápio está indisponível no momento. Tente novamente em instantes.';

if ($isPost) {
    $hadItems = $quantities !== [];
    [$status, $message] = $catalogAvailable
        ? updateCart($quantities, $products, $_POST, $csrf)
        : [503, $catalogError];

    if ($catalogAvailable) {
        if ($status === 200 && (!$hadItems || !$quantities)) {
            unset($_SESSION['cart_review']);
            $cartReview = '';
        }
        rememberCart($quantities, $products);
    }

    if (!$isJson) {
        $_SESSION['cart_message'] = $message;
        session_write_close();
        header('Location: ' . ($cartPage ? './?carrinho=1#carrinho' : './#cardapio'), true, 303);
        exit;
    }

    session_write_close();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['message' => $message];
    if ($catalogAvailable) {
        $cart = cartSummary($quantities, $products);
        ob_start();
        require __DIR__ . '/../app/views/cart.php';
        $payload['cartHtml'] = ob_get_clean();
        $payload['cart'] = ['count' => $cart['count'], 'totalCents' => $cart['totalCents']];
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($catalogAvailable) {
    rememberCart($quantities, $products);
} else {
    http_response_code(503);
}

$message = $_SESSION['cart_message'] ?? '';
$receipts = $_SESSION['order_receipts'] ?? [];
$lastOrder = $receipts ? end($receipts) : null;
unset($_SESSION['cart_message']);
session_write_close();

$cart = cartSummary($quantities, $products);
$categories = productCategories();
$menuGroups = [];
foreach ($products as $product) {
    $menuGroups[$product['category']][] = $product;
}
$isDemo = presentationDemoMode();
$contact = presentationContact();
$description = $isDemo
    ? 'Projeto de portfólio: explore o cardápio e experimente o fluxo de pedidos da Burger House com dados fictícios.'
    : 'Smash burgers na chapa, fritas da casa e milk-shake. Explore o cardápio da Burger House e monte seu pedido.';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= escape($description) ?>">
    <meta name="theme-color" content="#fbf7ed">
    <title>Burger House — Smash Burgers</title>
    <?php renderPresentationHead('Burger House — Smash Burgers', $description, true); ?>
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/customer.css')) ?>">
    <script src="<?= escape(assetUrl('assets/js/app.js')) ?>" defer></script>
</head>
<body class="customer storefront" id="inicio">
    <a class="skip-link" href="#cardapio">Pular para o cardápio</a>
    <header class="site-header store-header">
        <div class="shell header-inner">
            <a class="brand" href="./" aria-label="Burger House — início">
                <span class="brand-mark" aria-hidden="true"><i></i></span>
                <span class="brand-name">BURGER <b>HOUSE</b></span>
            </a>
            <nav class="site-nav" aria-label="Navegação principal">
                <a href="#cardapio">Cardápio</a>
                <a href="#atendimento">Atendimento</a>
            </nav>
            <a class="cart-link" href="./?carrinho=1#carrinho" data-cart-open>
                <span class="bag-icon" aria-hidden="true"></span>
                <span>Carrinho</span>
                <span class="cart-count" data-cart-count><?= $cart['count'] ?></span>
            </a>
        </div>
    </header>

    <main>
        <?php if ($isDemo): ?>
            <aside class="shell demo-notice" aria-label="Demonstração do projeto">
                <strong>Projeto de portfólio</strong>
                <p>Esta é uma demonstração da Burger House.
                    <?php if ($storeStatus['accepting'] ?? false): ?>
                        Explore o cardápio e teste o pedido com dados fictícios.
                    <?php else: ?>
                        Explore o cardápio e monte seu carrinho. O envio de pedidos de teste está indisponível no momento.
                    <?php endif; ?>
                    Nenhuma compra real será realizada.</p>
            </aside>
        <?php endif; ?>
        <dialog id="carrinho" class="cart-dialog<?= $cartPage ? ' is-page' : '' ?>" aria-labelledby="cart-title" <?= $cartPage ? 'open' : '' ?>>
            <div class="cart-heading">
                <h2 id="cart-title">Seu carrinho</h2>
                <a class="cart-close" href="./#cardapio" aria-label="Fechar carrinho" data-cart-close>×</a>
            </div>
            <p class="cart-inline-status" role="status" data-dialog-status><?= $cartPage ? escape($message) : '' ?></p>
            <div id="cart-content">
                <?php if ($catalogAvailable): ?>
                    <?php require __DIR__ . '/../app/views/cart.php'; ?>
                <?php else: ?>
                    <p class="cart-unavailable"><?= escape($catalogError) ?></p>
                <?php endif; ?>
            </div>
        </dialog>

        <section class="hero" aria-labelledby="hero-title">
            <div class="shell hero-inner">
                <div class="hero-copy">
                    <?php if ($storeStatus): ?>
                        <p class="store-availability">
                            <strong class="service-status" data-state="<?= escape($storeStatus['state']) ?>"><?= escape($storeStatus['label']) ?></strong>
                            <span><?= escape($storeStatus['message']) ?></span>
                        </p>
                    <?php endif; ?>
                    <h1 id="hero-title">Smash burger <span>na chapa</span></h1>
                    <p class="hero-description">Pão brioche tostado, cheddar derretido e molho da casa.</p>
                    <a class="primary-button" href="#cardapio">Ver cardápio <span aria-hidden="true">↓</span></a>
                </div>
                <figure class="hero-photo">
                    <img src="assets/brand/balcao-640.webp"
                         srcset="assets/brand/balcao-640.webp 640w, assets/brand/balcao-1280.webp 1280w"
                         sizes="(max-width: 720px) 94vw, (max-width: 1200px) 51vw, 610px"
                         alt="Uma pessoa serve dois hambúrgueres e fritas em uma bandeja"
                         width="1280" height="853" fetchpriority="high">
                </figure>
            </div>
        </section>

        <div class="shell pickup-strip">
            <p><strong>Retirada no balcão</strong><span><?= $store && $store['pickup_address'] !== '' ? escape($store['pickup_address']) : 'Pagamento na retirada' ?></span></p>
            <?php if ($lastOrder): ?>
                <a class="pickup-order" href="checkout.php?pedido=<?= escape($lastOrder) ?>">Acompanhar pedido <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </div>

        <section class="shell menu" id="cardapio" aria-labelledby="menu-title" tabindex="-1">
            <div class="menu-heading">
                <h2 id="menu-title">Cardápio</h2>
                <?php if ($products): ?>
                    <div class="filters" role="group" aria-label="Categorias do cardápio" data-filters hidden>
                        <button type="button" data-filter="todos" aria-pressed="true">Todos</button>
                        <?php foreach ($categories as $key => $label): ?>
                            <button type="button" data-filter="<?= escape($key) ?>" aria-pressed="false"><?= escape($label) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$catalogAvailable): ?>
                <p class="menu-notice" role="status"><?= escape($catalogError) ?></p>
            <?php elseif (!$products): ?>
                <p class="menu-notice">Nenhum produto disponível no momento.</p>
            <?php else: ?>
                <div class="menu-sections">
                    <?php foreach ($categories as $key => $label): ?>
                        <?php if (empty($menuGroups[$key])) continue; ?>
                        <section class="menu-category" data-category="<?= escape($key) ?>" aria-labelledby="menu-<?= escape($key) ?>">
                            <h3 id="menu-<?= escape($key) ?>"><?= escape($label) ?></h3>
                            <div class="product-grid">
                                <?php foreach ($menuGroups[$key] as $product): ?>
                                    <article class="product">
                                        <?php if ($product['image'] !== ''): ?>
                                            <img class="product-image" src="<?= escape(productImage($product)) ?>" alt="<?= escape($product['image_alt']) ?>" width="512" height="512" loading="lazy">
                                        <?php else: ?>
                                            <div class="product-image product-image-placeholder" aria-hidden="true"><span class="brand-mark"><i></i></span></div>
                                        <?php endif; ?>
                                        <div class="product-body">
                                            <h4><?= escape($product['name']) ?></h4>
                                            <p><?= escape($product['description']) ?></p>
                                            <div class="product-bottom">
                                                <strong><?= price($product['price_cents']) ?></strong>
                                                <form action="./" method="post" data-cart-form>
                                                    <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
                                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                    <input type="hidden" name="action" value="add">
                                                    <button class="add-button" type="submit" aria-label="Adicionar <?= escape($product['name']) ?> ao carrinho"><span aria-hidden="true">+</span> Adicionar</button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
                <p class="menu-notice" role="status" data-filter-empty hidden>Nenhum produto nesta categoria.</p>
            <?php endif; ?>
        </section>

        <section class="shell store-details" id="atendimento" aria-labelledby="store-details-title">
            <div class="store-details-intro">
                <h2 id="store-details-title">Horários e retirada</h2>
                <p>Retirada no balcão. Pagamento na retirada.</p>
                <?php if ($store && $store['pickup_address'] !== ''): ?>
                    <address><?= escape($store['pickup_address']) ?></address>
                <?php else: ?>
                    <p>A retirada está temporariamente indisponível.</p>
                <?php endif; ?>
                <?php if ($contact): ?>
                    <p><span>Fale com a loja</span><br><a class="store-contact" href="<?= escape($contact['href']) ?>"><?= escape($contact['label']) ?></a></p>
                <?php endif; ?>
            </div>
            <?php if ($store): ?>
                <div class="store-details-hours">
                    <h3>Horários de atendimento</h3>
                    <p class="hours-timezone">Horário de Brasília. Datas especiais substituem o horário semanal.</p>
                    <dl class="store-hours">
                        <?php foreach (storeWeekdays() as $day => $label): ?>
                            <div><dt><?= escape($label) ?></dt><dd><?= escape(presentationHours($store['schedule'][$day] ?? [])) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php $specialDates = presentationSpecialDates($store['exceptions']); ?>
                    <?php if ($specialDates): ?>
                        <h3 class="special-dates-title">Datas especiais</h3>
                        <dl class="store-hours">
                            <?php foreach ($specialDates as $date => $intervals): ?>
                                <?php $specialDate = new DateTimeImmutable($date, new DateTimeZone('America/Sao_Paulo')); ?>
                                <div><dt><?= $specialDate->format('d/m/Y') ?></dt><dd><?= escape(presentationHours($intervals)) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="site-footer">
        <div class="shell footer-inner">
            <a class="brand" href="#inicio" aria-label="Burger House — voltar ao início">
                <span class="brand-mark" aria-hidden="true"><i></i></span>
                <span class="brand-name">BURGER <b>HOUSE</b></span>
            </a>
            <a class="footer-contact" href="#atendimento">Horários e contato</a>
            <a class="footer-top" href="#inicio">Voltar ao início <span aria-hidden="true">↑</span></a>
        </div>
    </footer>

    <div class="toast" role="status" data-status><?= $cartPage ? '' : escape($message) ?></div>
    <aside class="mobile-cart" data-mobile-cart <?= $cart['count'] ? '' : 'hidden' ?> aria-label="Resumo do carrinho">
        <a class="primary-button" href="./?carrinho=1#carrinho" data-cart-open>
            <span>Ver carrinho <span class="mobile-cart-count" data-cart-count><?= $cart['count'] ?></span></span>
            <strong data-mobile-total><?= price($cart['totalCents']) ?></strong>
        </a>
    </aside>

</body>
</html>
