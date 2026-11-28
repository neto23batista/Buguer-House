<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin-auth.php';
require_once __DIR__ . '/../../app/kitchen.php';
require_once __DIR__ . '/../../app/assets.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isFeed = !$isPost && ($_GET['atualizacao'] ?? null) === '1';
$isJson = $isPost && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$auth = beginAdminSession(passive: $isFeed);

if (!$auth['loggedIn']) {
    session_write_close();
    if ($isFeed || $isJson) {
        http_response_code(401);
    } else {
        header('Location: ./', true, 303);
    }
    exit;
}

$adminCsrf = $auth['csrf'];
if ($isPost) {
    $sentCsrf = $_POST['csrf'] ?? null;
    $id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $expected = $_POST['expected_status'] ?? null;
    $next = $_POST['next_status'] ?? null;
    $reason = $_POST['cancel_reason'] ?? '';
    $customerReason = $_POST['customer_reason'] ?? '';
    $status = 200;
    $message = 'Status atualizado.';

    if (!is_string($sentCsrf) || !hash_equals($adminCsrf, $sentCsrf)) {
        $status = 403;
        $message = 'Sua sessão mudou. Recarregue a tela para tentar novamente.';
    } elseif (($_POST['action'] ?? null) !== 'change_status' || $id === false
        || !is_string($expected) || !is_string($next) || !is_string($reason) || !is_string($customerReason)
        || !in_array($next, orderTransitions($expected), true)) {
        $status = 422;
        $message = 'Escolha uma ação válida para este pedido.';
    } else {
        try {
            if ($next === 'cancelled' && $customerReason === '') {
                throw new DomainException('Selecione um motivo de cancelamento para o cliente.');
            }
            if (!changeKitchenOrderStatus(databaseConnection(), $id, $expected, $next, $auth['user'], $reason, $customerReason, is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '')) {
                $status = 409;
                $message = 'O pedido já mudou. Confira a fila atual.';
            }
        } catch (DomainException $exception) {
            $status = 422;
            $message = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('Burger House cozinha: ' . $exception->getMessage());
            $status = 503;
            $message = 'O pedido não pôde ser atualizado. Tente novamente.';
        }
    }

    if ($isJson) {
        session_write_close();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        $_SESSION['kitchen_message'] = ['text' => $message, 'error' => $status !== 200];
        session_write_close();
        header('Location: cozinha.php', true, 303);
    }
    exit;
}

$flash = $isFeed ? null : ($_SESSION['kitchen_message'] ?? null);
if (!$isFeed) {
    unset($_SESSION['kitchen_message']);
}
session_write_close();

$stages = null;
try {
    $stages = listKitchenOrders(databaseConnection());
} catch (PDOException $exception) {
    error_log('Burger House cozinha: ' . $exception->getMessage());
    http_response_code(503);
}

if ($isFeed) {
    header('Content-Type: text/html; charset=utf-8');
    if ($stages !== null) {
        require __DIR__ . '/../../app/views/kitchen-board.php';
    }
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#172923">
    <title>Cozinha — Burger House</title>
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/style.css', '../')) ?>">
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/admin.css', '../')) ?>">
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/kitchen.css', '../')) ?>">
    <script src="<?= escape(assetUrl('assets/js/kitchen.js', '../')) ?>" defer></script>
    <link rel="stylesheet" href="<?= escape(assetUrl('assets/css/accessibility.css', '../')) ?>">
    <script src="<?= escape(assetUrl('assets/js/accessibility.js', '../')) ?>"></script>
</head>
<body class="admin-body">
    <a class="skip-link" href="#kitchen-main">Pular para o conteúdo</a>
    <header class="site-header admin-header">
        <div class="shell admin-header-inner">
            <a class="brand" href="./" aria-label="Burger House — painel administrativo">
                <span class="brand-mark" aria-hidden="true"><i></i></span>
                <span class="brand-name">BURGER <b>HOUSE</b></span>
            </a>
            <nav class="admin-header-actions" aria-label="Painel administrativo">
                <?php require __DIR__ . '/../../app/views/accessibility.php'; ?>
                <a href="../">Ver loja <span aria-hidden="true">↗</span></a>
                <form action="./" method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                    <button type="submit" class="admin-header-button">Sair</button>
                </form>
            </nav>
        </div>
    </header>

    <main id="kitchen-main" class="shell admin-main kitchen-main" tabindex="-1">
        <nav class="admin-sections" aria-label="Seções do painel">
            <a href="./">Pedidos</a>
            <a href="cozinha.php" aria-current="page">Cozinha</a>
            <?php if ($auth['role'] === 'owner'): ?>
                <a href="./?secao=cardapio">Cardápio</a>
                <a href="./?secao=loja">Loja</a>
                <a href="./?secao=relatorios">Relatórios</a>
                <a href="./?secao=caixa">Caixa</a>
                <a href="./?secao=equipe">Equipe</a>
            <?php endif; ?>
        </nav>
        <div class="admin-page-heading kitchen-heading">
            <h1>Cozinha</h1>
            <div class="kitchen-tools">
                <p class="kitchen-connection" data-kitchen-connection role="status" hidden>Fila conectada</p>
                <button class="admin-button admin-button-secondary" type="button" data-kitchen-audio aria-pressed="false" hidden>Alerta sonoro</button>
                <a class="admin-button admin-button-secondary" href="cozinha.php" data-kitchen-refresh>Atualizar <span aria-hidden="true">↻</span></a>
            </div>
        </div>
        <p class="admin-alert<?= !empty($flash['error']) ? ' admin-alert-error' : '' ?>" role="status" data-kitchen-message<?= $flash === null ? ' hidden' : '' ?>><?= escape($flash['text'] ?? '') ?></p>
        <div class="kitchen-board" data-kitchen-board>
            <?php if ($stages === null): ?>
                <p class="admin-panel admin-empty" role="alert">A fila da cozinha está indisponível. Tente atualizar em instantes.</p>
            <?php else: ?>
                <?php require __DIR__ . '/../../app/views/kitchen-board.php'; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
