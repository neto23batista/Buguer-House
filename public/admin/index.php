<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/admin-auth.php';
require_once __DIR__ . '/../../app/admin-rate-limit.php';
require_once __DIR__ . '/../../app/orders.php';
require_once __DIR__ . '/../../app/admin-catalog.php';
require_once __DIR__ . '/../../app/kitchen.php';
require_once __DIR__ . '/../../app/reports.php';

const ADMIN_ORDER_PAGE_SIZE = 20;

function adminUrl(?string $status = null, int $page = 1, ?string $code = null): string
{
    $query = [];
    if ($status !== null) {
        $query['status'] = $status;
    }
    if ($page > 1) {
        $query['pagina'] = $page;
    }
    if ($code !== null) {
        $query['pedido'] = $code;
    }
    return './' . ($query ? '?' . http_build_query($query) : '');
}

function adminRedirect(string $url, ?string $message = null, bool $isError = false): never
{
    if ($message !== null) {
        $_SESSION['admin_message'] = ['text' => $message, 'error' => $isError];
    }
    session_write_close();
    header('Location: ' . $url, true, 303);
    exit;
}

function adminCatalogUrl(?int $id = null, bool $create = false): string
{
    $query = ['secao' => 'cardapio'];
    if ($id !== null) {
        $query['produto'] = $id;
    } elseif ($create) {
        $query['novo'] = '1';
    }
    return './?' . http_build_query($query);
}

function adminTime(string $timestamp): DateTimeImmutable
{
    return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'));
}

[
    'user' => $adminUser, 'accounts' => $accounts, 'role' => $adminRole,
    'configured' => $isConfigured, 'loggedIn' => $isLoggedIn, 'csrf' => $adminCsrf,
] = beginAdminSession();
$canManageStore = $isLoggedIn && $adminRole === 'owner';

$statuses = orderStatuses();
$filter = $_GET['status'] ?? null;
$filter = is_string($filter) && isset($statuses[$filter]) ? $filter : null;
$page = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, ADMIN_ORDER_PAGE_SIZE)],
]) ?: 1;
$code = $_GET['pedido'] ?? null;
$code = is_string($code) && preg_match('/\A[A-F0-9]{16}\z/', $code) ? $code : null;
$isCatalog = ($_GET['secao'] ?? null) === 'cardapio';
$isStore = ($_GET['secao'] ?? null) === 'loja';
$isReport = ($_GET['secao'] ?? null) === 'relatorios';
$storeSettings = null;
$storeErrors = [];
$catalogId = filter_var($_GET['produto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) ?: null;
$catalogNew = ($_GET['novo'] ?? null) === '1';
$catalogUrl = adminCatalogUrl($catalogId, $catalogNew);
$catalogValues = null;
$catalogErrors = [];
$flash = $_SESSION['admin_message'] ?? null;
unset($_SESSION['admin_message']);
$restrictedSection = $isLoggedIn && !$canManageStore && ($isStore || $isCatalog || $isReport);
$sectionUrl = $isReport ? './?secao=relatorios' : ($isStore ? './?secao=loja' : ($isCatalog ? $catalogUrl : './'));
$sectionTitle = $isReport ? 'Relatórios' : ($isStore ? 'Configurações da loja' : ($isCatalog ? 'Cardápio' : 'Pedidos'));

if (!$isConfigured) {
    http_response_code(503);
} elseif ($restrictedSection) {
    http_response_code(403);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!is_string($token) || !hash_equals($adminCsrf, $token)) {
        http_response_code(403);
        $flash = ['text' => 'Sua sessão mudou. Atualize a página para tentar novamente.', 'error' => true];
    } elseif ($action === 'login' && !$isLoggedIn) {
        $username = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;
        $username = is_string($username) ? trim($username) : '';
        $operator = $accounts['operator'];
        $role = $operator['configured'] && hash_equals($operator['user'], $username) ? 'operator' : 'owner';
        $account = $accounts[$role];
        $maxPasswordBytes = $account['algorithm'] === PASSWORD_BCRYPT ? 72 : 1024;
        $knownUser = hash_equals($account['user'], $username);

        try {
            $db = databaseConnection();
            $verified = checkAdminLogin(
                $db,
                $knownUser ? $account['user'] : null,
                static fn (): bool => is_string($password) && strlen($password) <= $maxPasswordBytes
                    && password_verify($password, $account['hash']) && $knownUser,
            );
            pruneAdminLoginAttempts($db);
            if ($verified === null) {
                adminRedirect($sectionUrl, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', true);
            }
            if (!$verified) {
                adminRedirect($sectionUrl, 'Usuário ou senha inválidos.', true);
            }

            session_regenerate_id(true);
            $now = time();
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['admin_session'] = [
                'role' => $role, 'version' => $account['version'], 'last_seen' => $now, 'started_at' => $now,
            ];
            adminRedirect($role === 'owner' ? $sectionUrl : 'cozinha.php');
        } catch (PDOException $exception) {
            error_log('Burger House login: ' . $exception->getMessage());
            http_response_code(503);
            $flash = ['text' => 'O login está temporariamente indisponível. Tente novamente em instantes.', 'error' => true];
        }
    } elseif (!$isLoggedIn) {
        adminRedirect($sectionUrl, 'Entre para administrar a loja.', true);
    } elseif ($action === 'logout') {
        unset($_SESSION['admin_session'], $_SESSION['admin_csrf']);
        session_regenerate_id(true);
        adminRedirect('./');
    } elseif ($action === 'save_store' && $isStore) {
        [$storeSettings, $storeErrors] = validateStoreSettings($_POST);
        if ($storeErrors) {
            http_response_code(422);
            $flash = ['text' => 'Revise os campos destacados.', 'error' => true];
        } else {
            try {
                saveStoreSettings(databaseConnection(), $storeSettings);
                adminRedirect('./?secao=loja', 'Configurações salvas.');
            } catch (PDOException $exception) {
                error_log('Burger House configurações: ' . $exception->getMessage());
                http_response_code(503);
                $flash = ['text' => 'Não foi possível salvar as configurações.', 'error' => true];
            }
        }
    } elseif ($action === 'save_product' && $isCatalog) {
        $sentId = $_POST['product_id'] ?? null;
        $expectedId = $catalogId === null ? '' : (string) $catalogId;
        if ($sentId !== $expectedId || ($catalogId === null && !$catalogNew)) {
            adminRedirect($catalogUrl, 'Abra o produto antes de salvar as alterações.', true);
        }

        [$catalogValues, $catalogErrors] = validateAdminProduct($_POST);
        if ($catalogErrors) {
            http_response_code(422);
            $flash = ['text' => 'Revise os campos destacados e tente novamente.', 'error' => true];
        } else {
            try {
                $id = saveAdminProduct(databaseConnection(), $catalogId, $catalogValues);
                adminRedirect(adminCatalogUrl($id) . '#produto-editor', $catalogId === null ? 'Produto cadastrado.' : 'Produto atualizado.');
            } catch (DomainException $exception) {
                http_response_code(409);
                $flash = ['text' => $exception->getMessage(), 'error' => true];
            } catch (PDOException $exception) {
                error_log('Burger House admin catálogo: ' . $exception->getMessage());
                http_response_code(503);
                $flash = ['text' => 'O produto não pôde ser salvo. Tente novamente.', 'error' => true];
            }
        }
    } elseif ($action === 'change_status') {
        $id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expected = $_POST['expected_status'] ?? null;
        $next = $_POST['next_status'] ?? null;
        $destination = adminUrl($filter, $page, $code) . '#pedido-detalhe';

        if ($id === false || !is_string($expected) || !is_string($next)
            || !in_array($next, orderTransitions($expected), true)) {
            adminRedirect($destination, 'Escolha uma atualização válida para este pedido.', true);
        }

        try {
            $cancelReason = $_POST['cancel_reason'] ?? '';
            $changed = changeKitchenOrderStatus(databaseConnection(), $id, $expected, $next, $adminUser, is_string($cancelReason) ? $cancelReason : '');
        } catch (DomainException $exception) {
            adminRedirect($destination, $exception->getMessage(), true);
        } catch (PDOException $exception) {
            error_log('Burger House admin: ' . $exception->getMessage());
            adminRedirect($destination, 'O pedido não pôde ser atualizado. Tente novamente.', true);
        }

        if (!$changed) {
            adminRedirect($destination, 'O pedido já mudou. Confira o status atual.', true);
        }

        adminRedirect($destination, 'Status atualizado.');
    } else {
        adminRedirect(adminUrl($filter, $page, $code), 'Ação indisponível.', true);
    }
}

session_write_close();

$orders = [];
$order = null;
$items = [];
$events = [];
$hasNextPage = false;
$databaseAvailable = true;
$catalogProducts = [];
$catalogImages = [];
$report = null;
$period = $isReport ? reportPeriod($_GET) : null;

if ($isLoggedIn && !$restrictedSection) {
    try {
        $db = databaseConnection();
        if ($isReport) {
            if ($period['error'] === null) {
                $report = orderReport($db, $period['bounds']);
            } else {
                http_response_code(422);
            }
        } elseif ($isStore) {
            $storeSettings ??= loadStore($db);
        } elseif ($isCatalog) {
            $catalogProducts = adminProducts($db);
            $catalogImages = adminProductImages();
            if ($catalogId !== null) {
                $storedProduct = adminProduct($db, $catalogId);
                if ($storedProduct === null) {
                    $catalogValues = null;
                    http_response_code(404);
                    $flash = ['text' => 'Produto não encontrado.', 'error' => true];
                } else {
                    $catalogValues ??= $storedProduct;
                }
            } elseif ($catalogNew) {
                $catalogValues ??= adminProductDefaults();
            }
        } else {
            $orders = listOrders($db, $filter, ADMIN_ORDER_PAGE_SIZE + 1, ($page - 1) * ADMIN_ORDER_PAGE_SIZE);
            $hasNextPage = count($orders) > ADMIN_ORDER_PAGE_SIZE;
            $orders = array_slice($orders, 0, ADMIN_ORDER_PAGE_SIZE);
            $order = $code !== null ? findOrder($db, $code) : ($orders[0] ?? null);
            if ($order !== null) {
                $items = listOrderItems($db, (int) $order['id']);
                $events = listOrderStatusEvents($db, (int) $order['id']);
            }
        }
    } catch (PDOException $exception) {
        error_log('Burger House admin: ' . $exception->getMessage());
        $databaseAvailable = false;
        http_response_code(503);
    }
}

$statusActions = [
    'preparing' => 'Iniciar preparo',
    'ready' => 'Marcar pronto para retirada',
    'completed' => 'Concluir pedido',
    'cancelled' => 'Cancelar pedido',
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fbf7ed">
    <title><?= $isLoggedIn ? escape($sectionTitle) : 'Painel administrativo' ?> — Burger House</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <?php if ($isStore): ?><link rel="stylesheet" href="../assets/css/store.css"><?php endif; ?>
</head>
<body class="admin-body">
    <a class="skip-link" href="#admin-main">Pular para o conteúdo</a>
    <header class="site-header admin-header">
        <div class="shell admin-header-inner">
            <a class="brand" href="../" aria-label="Burger House — cardápio">
                <span class="brand-mark" aria-hidden="true"><i></i></span>
                <span class="brand-name">BURGER <b>HOUSE</b></span>
            </a>
            <nav class="admin-header-actions" aria-label="Painel administrativo">
                <a href="../">Ver loja <span aria-hidden="true">↗</span></a>
                <?php if ($isLoggedIn): ?>
                    <form action="./" method="post">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                        <button type="submit" class="admin-header-button">Sair</button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php if (!$isLoggedIn): ?>
        <main id="admin-main" class="shell admin-login" tabindex="-1">
            <section class="admin-panel admin-login-card" aria-labelledby="login-title">
                <h1 id="login-title">Painel administrativo</h1>
                <?php if (!$isConfigured): ?>
                    <p>O painel está indisponível. Configure as credenciais da equipe no servidor para liberar o acesso.</p>
                <?php else: ?>
                    <p>Entre para gerenciar os pedidos e o cardápio da loja.</p>
                    <?php if ($flash !== null): ?>
                        <p class="admin-alert admin-alert-error" role="alert"><?= escape($flash['text']) ?></p>
                    <?php endif; ?>
                    <form class="admin-login-form" action="<?= escape($sectionUrl) ?>" method="post">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                        <label for="username">Usuário</label>
                        <input id="username" name="username" autocomplete="username" autocapitalize="none" required>
                        <label for="password">Senha</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
                        <button class="admin-button admin-button-primary" type="submit">Entrar <span aria-hidden="true">→</span></button>
                    </form>
                <?php endif; ?>
            </section>
        </main>
    <?php else: ?>
        <main id="admin-main" class="shell admin-main" tabindex="-1">
            <nav class="admin-sections" aria-label="Seções do painel">
                <a href="./"<?= !$isCatalog && !$isStore && !$isReport ? ' aria-current="page"' : '' ?>>Pedidos</a>
                <a href="cozinha.php">Cozinha</a>
                <?php if ($canManageStore): ?>
                    <a href="<?= escape(adminCatalogUrl()) ?>"<?= $isCatalog ? ' aria-current="page"' : '' ?>>Cardápio</a>
                    <a href="./?secao=loja"<?= $isStore ? ' aria-current="page"' : '' ?>>Loja</a>
                    <a href="./?secao=relatorios"<?= $isReport ? ' aria-current="page"' : '' ?>>Relatórios</a>
                <?php endif; ?>
            </nav>
            <?php if ($restrictedSection): ?>
                <div class="admin-page-heading"><h1>Acesso restrito</h1></div>
                <p class="admin-alert admin-alert-error" role="alert">Essa área está disponível para o responsável pela loja.</p>
            <?php elseif ($isReport): ?>
                <?php require __DIR__ . '/../../app/views/admin-reports.php'; ?>
            <?php elseif ($isStore): ?>
                <div class="admin-page-heading"><h1>Configurações da loja</h1></div>
                <?php if ($flash !== null): ?>
                    <p class="admin-alert<?= $flash['error'] ? ' admin-alert-error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= escape($flash['text']) ?></p>
                <?php endif; ?>
                <?php require __DIR__ . '/../../app/views/admin-store.php'; ?>
            <?php elseif ($isCatalog): ?>
                <div class="admin-page-heading">
                    <h1>Cardápio</h1>
                    <?php if ($databaseAvailable): ?>
                        <a class="admin-button admin-button-primary" href="<?= escape(adminCatalogUrl(null, true)) ?>#produto-editor"><span aria-hidden="true">+</span> Novo produto</a>
                    <?php endif; ?>
                </div>
                <?php if ($flash !== null && $catalogValues === null): ?>
                    <p class="admin-alert<?= $flash['error'] ? ' admin-alert-error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= escape($flash['text']) ?></p>
                <?php endif; ?>
                <?php require __DIR__ . '/../../app/views/admin-catalog.php'; ?>
            <?php else: ?>
            <div class="admin-page-heading">
                <h1>Pedidos</h1>
                <a class="admin-button admin-button-secondary" href="<?= escape(adminUrl($filter, $page, $code)) ?>">Atualizar <span aria-hidden="true">↻</span></a>
            </div>

            <?php if ($flash !== null && ($code === null || $order === null || !$databaseAvailable)): ?>
                <p class="admin-alert<?= $flash['error'] ? ' admin-alert-error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= escape($flash['text']) ?></p>
            <?php endif; ?>

            <?php if (!$databaseAvailable): ?>
                <p class="admin-panel admin-empty" role="alert">Os pedidos estão indisponíveis. Tente atualizar em instantes.</p>
            <?php else: ?>
                <nav class="admin-filters" aria-label="Filtrar pedidos por status">
                    <a href="./"<?= $filter === null ? ' aria-current="page"' : '' ?>>Todos</a>
                    <?php foreach ($statuses as $value => $label): ?>
                        <a href="<?= escape(adminUrl($value)) ?>"<?= $filter === $value ? ' aria-current="page"' : '' ?>><?= escape($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <div class="admin-layout">
                    <section class="admin-panel admin-orders" aria-labelledby="orders-title">
                        <h2 id="orders-title">Pedidos recentes</h2>
                        <?php if (!$orders): ?>
                            <p class="admin-empty">Nenhum pedido encontrado<?= $filter !== null ? ' com esse status' : '' ?>.</p>
                        <?php else: ?>
                            <ul class="admin-order-list">
                                <?php foreach ($orders as $row): ?>
                                    <?php $placedAt = adminTime($row['created_at']); ?>
                                    <li>
                                        <a class="admin-order-row" href="<?= escape(adminUrl($filter, $page, $row['public_code'])) ?>#pedido-detalhe"<?= $row['public_code'] === ($order['public_code'] ?? null) ? ' aria-current="true"' : '' ?>>
                                            <div class="admin-order-identity">
                                                <strong><?= escape($row['customer_name']) ?></strong>
                                                <span class="admin-code">#<?= escape($row['public_code']) ?></span>
                                            </div>
                                            <div class="admin-order-date">
                                                <strong><?= price((int) $row['total_cents']) ?></strong>
                                                <time datetime="<?= $placedAt->format(DATE_ATOM) ?>"><?= $placedAt->format('d/m/Y · H:i') ?></time>
                                            </div>
                                            <span class="admin-status admin-status-<?= escape($row['status']) ?>"><?= escape($statuses[$row['status']]) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($page > 1 || $hasNextPage): ?>
                            <nav class="admin-pagination" aria-label="Páginas de pedidos">
                                <?php if ($page > 1): ?>
                                    <a class="admin-button admin-button-secondary" href="<?= escape(adminUrl($filter, $page - 1)) ?>">← Anterior</a>
                                <?php endif; ?>
                                <span>Página <?= $page ?></span>
                                <?php if ($hasNextPage): ?>
                                    <a class="admin-button admin-button-secondary" href="<?= escape(adminUrl($filter, $page + 1)) ?>">Próxima →</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </section>

                    <?php if ($order !== null): ?>
                        <?php $transitions = orderTransitions($order['status']); ?>
                        <section id="pedido-detalhe" class="admin-panel admin-detail" aria-labelledby="detail-title" tabindex="-1">
                            <div class="admin-detail-heading">
                                <div>
                                    <h2 id="detail-title">Detalhes do pedido</h2>
                                    <p class="admin-code">#<?= escape($order['public_code']) ?></p>
                                </div>
                                <span class="admin-status admin-status-<?= escape($order['status']) ?>"><?= escape($statuses[$order['status']]) ?></span>
                            </div>
                            <?php if ($flash !== null && $code !== null): ?>
                                <p class="admin-alert<?= $flash['error'] ? ' admin-alert-error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= escape($flash['text']) ?></p>
                            <?php endif; ?>

                            <?php
                            $phone = $order['customer_phone'];
                            $formattedPhone = preg_replace('/\A(\d{2})(\d{4,5})(\d{4})\z/', '($1) $2-$3', $phone);
                            ?>
                            <dl class="admin-customer">
                                <div><dt>Cliente</dt><dd><?= escape($order['customer_name']) ?></dd></div>
                                <div><dt>Telefone</dt><dd><a href="tel:+55<?= escape($phone) ?>"><?= escape($formattedPhone) ?></a></dd></div>
                                <div><dt>Entrega</dt><dd>Retirada na loja</dd></div>
                                <div><dt>Pagamento</dt><dd>Na retirada</dd></div>
                            </dl>

                            <?php if ($order['notes'] !== ''): ?>
                                <div class="admin-order-note">
                                    <strong>Observações</strong>
                                    <p><?= escape($order['notes']) ?></p>
                                </div>
                            <?php endif; ?>

                            <h3>Itens</h3>
                            <ul class="admin-detail-items">
                                <?php foreach ($items as $item): ?>
                                    <li>
                                        <span><?= (int) $item['quantity'] ?> × <?= escape($item['product_name']) ?></span>
                                        <strong><?= price((int) $item['quantity'] * (int) $item['unit_price_cents']) ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="admin-detail-total"><strong>Total</strong><strong><?= price((int) $order['total_cents']) ?></strong></div>

                            <?php if ($events): ?>
                                <h3>Histórico</h3>
                                <ul class="admin-history">
                                    <?php foreach ($events as $event): ?>
                                        <?php $eventTime = adminTime($event['created_at']); ?>
                                        <li>
                                            <strong><?= escape($statuses[$event['next_status']]) ?></strong>
                                            <span><?= escape($event['actor']) ?> · <time datetime="<?= $eventTime->format(DATE_ATOM) ?>"><?= $eventTime->format('d/m H:i') ?></time></span>
                                            <?php if ($event['reason'] !== ''): ?><p><?= escape($event['reason']) ?></p><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($transitions): ?>
                                <form class="admin-order-actions" action="<?= escape(adminUrl($filter, $page, $order['public_code'])) ?>" method="post">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="expected_status" value="<?= escape($order['status']) ?>">
                                    <label for="next-status">Próxima ação</label>
                                    <select id="next-status" name="next_status" required>
                                        <option value="" selected disabled>Selecione uma ação</option>
                                        <?php foreach ($transitions as $next): ?>
                                            <option value="<?= escape($next) ?>"><?= escape($statusActions[$next]) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="admin-button admin-button-primary" type="submit">Atualizar status</button>
                                    <div class="admin-editor-field admin-editor-wide">
                                        <label for="cancel-reason">Motivo de cancelamento <span>(ao cancelar)</span></label>
                                        <input id="cancel-reason" name="cancel_reason" maxlength="240">
                                    </div>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php elseif ($code !== null): ?>
                        <p id="pedido-detalhe" class="admin-panel admin-empty">Pedido não encontrado.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </main>
    <?php endif; ?>
</body>
</html>
