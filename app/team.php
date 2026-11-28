<?php

declare(strict_types=1);

function staffAccount(PDO $db, string $user): ?array
{
    $query = $db->prepare('SELECT username, password_hash, revision FROM staff_accounts WHERE username=? AND active=1');
    $query->execute([$user]);
    $row = $query->fetch();
    if (!$row) return null;

    $account = adminAccount($row['username'], $row['password_hash']);
    $account['version'] = hash('sha256', $account['version'] . ':' . $row['revision']);
    return $account;
}

function updateStaff(PDO $db, array $input, array $accounts): void
{
    $user = $input['staff_name'] ?? null;
    $password = $input['staff_password'] ?? '';
    $action = $input['action'] ?? '';
    if (!is_string($user) || !preg_match('/\A[a-zA-Z0-9._-]{2,64}\z/', $user)
        || in_array($user, array_column($accounts, 'user'), true)) {
        throw new DomainException('Escolha um login próprio de 2 a 64 letras, números, pontos, hífens ou sublinhados.');
    }
    if ($action === 'staff_disable') {
        $query = $db->prepare('UPDATE staff_accounts SET active=0, revision=revision+1 WHERE username=? AND active=1 AND revision=?');
        $query->execute([$user, (int) ($input['revision'] ?? 0)]);
    } else {
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Use uma senha de 12 a 72 bytes.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($action === 'staff_create') {
            $query = $db->prepare('INSERT INTO staff_accounts(username,password_hash) VALUES (?,?)');
            try {
                $query->execute([$user, $hash]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException('Este login já existe.');
                throw $error;
            }
            return;
        }
        $query = $db->prepare('UPDATE staff_accounts SET password_hash=?, active=1, revision=revision+1 WHERE username=? AND revision=?');
        $query->execute([$hash, $user, (int) ($input['revision'] ?? 0)]);
    }
    if ($query->rowCount() !== 1) throw new DomainException('A conta mudou. Confira o estado atual.');
}
