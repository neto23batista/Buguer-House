<div class="admin-page-heading"><h1>Equipe</h1></div>
<p>Operadores acessam pedidos e cozinha. Cada movimentação fica registrada com o login usado.</p>
<form class="admin-panel report-filter" method="post">
    <input type="hidden" name="action" value="staff_create">
    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
    <label class="admin-editor-field">Login <input name="staff_name" required maxlength="64" autocomplete="off"></label>
    <label class="admin-editor-field">Senha inicial <input type="password" name="staff_password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
    <button class="admin-button admin-button-primary">Criar operador</button>
</form>
<?php foreach ($staff as $member): ?>
    <section class="admin-panel admin-detail">
        <h2><?= escape($member['username']) ?></h2>
        <p><?= $member['active'] ? 'Conta ativa' : 'Conta desativada' ?></p>
        <form method="post" class="report-filter">
            <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
            <input type="hidden" name="staff_name" value="<?= escape($member['username']) ?>">
            <input type="hidden" name="revision" value="<?= (int) $member['revision'] ?>">
            <label class="admin-editor-field">Nova senha <input type="password" name="staff_password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
            <button name="action" value="staff_reset" class="admin-button admin-button-secondary"><?= $member['active'] ? 'Alterar senha' : 'Reativar com nova senha' ?></button>
            <?php if ($member['active']): ?>
                <button name="action" value="staff_disable" formnovalidate class="admin-button admin-button-secondary">Desativar conta</button>
            <?php endif; ?>
        </form>
    </section>
<?php endforeach; ?>
