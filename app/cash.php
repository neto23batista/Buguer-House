<?php

declare(strict_types=1);

// The original receipt stays immutable. The (order_id, revision) key serves each lookup.
const EFFECTIVE_PAYMENT_METHOD_SQL = 'COALESCE((SELECT change_log.new_method FROM payment_method_changes change_log
    WHERE change_log.order_id=payment.order_id ORDER BY change_log.revision DESC LIMIT 1), payment.method)';

function paymentMethods(): array
{
    return ['cash' => 'Dinheiro', 'pix' => 'Pix', 'card' => 'Cartão'];
}

function cashToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function validCashDate(mixed $date): bool
{
    return is_string($date) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) && $date <= cashToday();
}

function lockCashDay(PDO $db, string $date): array
{
    $db->prepare('INSERT INTO cash_days (business_date) VALUES (?) ON DUPLICATE KEY UPDATE business_date=cash_days.business_date')
        ->execute([$date]);
    $query = $db->prepare('SELECT * FROM cash_days WHERE business_date=? FOR UPDATE');
    $query->execute([$date]);
    return $query->fetch();
}

function recordPayment(PDO $db, int $id, string $method, string $actor): void
{
    if (!isset(paymentMethods()[$method])) throw new DomainException('Selecione a forma de pagamento recebida.');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $date = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
    $day = lockCashDay($db, $date);
    if ($day['closed_at'] !== null) throw new DomainException('O caixa de hoje está fechado. Peça ao responsável para reabrir.');
    $query = $db->prepare('INSERT INTO payments(order_id,business_date,method,amount_cents,received_by,received_at)
        SELECT id,?,?,total_cents,?,? FROM orders WHERE id=?');
    $query->execute([$date, $method, $actor, $now->format('Y-m-d H:i:s'), $id]);
    $db->prepare('UPDATE cash_days SET revision=revision+1 WHERE business_date=?')->execute([$date]);
}

function cashTotals(PDO $db, string $date): array
{
    $query = $db->prepare('SELECT effective_method, SUM(amount_cents) FROM (
        SELECT ' . EFFECTIVE_PAYMENT_METHOD_SQL . ' AS effective_method, payment.amount_cents
        FROM payments payment WHERE payment.business_date=?
    ) AS receipts GROUP BY effective_method');
    $query->execute([$date]);
    return array_map('intval', array_replace(array_fill_keys(array_keys(paymentMethods()), 0), $query->fetchAll(PDO::FETCH_KEY_PAIR)));
}

function cashSnapshot(int $revision, array $totals): string
{
    return hash('sha256', json_encode([$revision, $totals], JSON_THROW_ON_ERROR));
}

function cashReport(PDO $db, string $date): array
{
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM cash_days WHERE business_date=?');
        $query->execute([$date]);
        $day = $query->fetch() ?: ['revision' => 1];
        $totals = cashTotals($db, $date);
        $snapshot = cashSnapshot((int) $day['revision'], $totals);
        $difference = (int) ($day['counted_cents'] ?? 0) - $totals['cash'];
        $query = $db->prepare('SELECT * FROM cash_events WHERE business_date=? ORDER BY id DESC');
        $query->execute([$date]);
        $events = $query->fetchAll();
        $query = $db->prepare('SELECT p.order_id, o.public_code, p.method, p.amount_cents, p.received_by, p.received_at
            FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.business_date=?
            ORDER BY p.received_at DESC, p.order_id DESC');
        $query->execute([$date]);
        $payments = $query->fetchAll();
        $query = $db->prepare('SELECT c.order_id, c.revision, c.previous_method, c.new_method, c.reason, c.actor, c.created_at
            FROM payment_method_changes c JOIN payments p ON p.order_id=c.order_id
            WHERE p.business_date=? ORDER BY c.order_id, c.revision DESC');
        $query->execute([$date]);
        $changes = $query->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        $db->commit();
        return compact('day', 'totals', 'snapshot', 'difference', 'events', 'payments', 'changes');
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function correctPaymentMethod(PDO $db, string $date, string $actor, array $input): void
{
    $id = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $method = $input['payment_method'] ?? null;
    $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
    if (!validCashDate($date) || !$id || $revision === false || !is_string($method) || !isset(paymentMethods()[$method])) {
        throw new DomainException('Confira o recebimento e a nova forma de pagamento.');
    }
    if (!preg_match('/\A[^\p{C}]{1,240}\z/u', $reason)) {
        throw new DomainException('Informe o motivo da correção em até 240 caracteres.');
    }
    $db->beginTransaction();
    try {
        $day = lockCashDay($db, $date);
        if ($day['closed_at'] !== null) {
            throw new DomainException('Reabra o caixa desse dia antes de corrigir um recebimento.');
        }
        $query = $db->prepare('SELECT method FROM payments WHERE order_id=? AND business_date=? FOR UPDATE');
        $query->execute([$id, $date]);
        $previous = $query->fetchColumn();
        if ($previous === false) {
            throw new DomainException('Recebimento não encontrado neste dia.');
        }
        $query = $db->prepare('SELECT revision, new_method FROM payment_method_changes WHERE order_id=? ORDER BY revision DESC LIMIT 1 FOR UPDATE');
        $query->execute([$id]);
        $latest = $query->fetch();
        if ((int) ($latest['revision'] ?? 0) !== $revision) {
            throw new DomainException('O recebimento mudou. Confira a forma atual antes de corrigir.');
        }
        $previous = $latest['new_method'] ?? $previous;
        if ($previous === $method) {
            throw new DomainException('Selecione uma forma diferente da registrada.');
        }
        $db->prepare('INSERT INTO payment_method_changes(order_id,revision,previous_method,new_method,reason,actor) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $revision + 1, $previous, $method, $reason, $actor]);
        $db->prepare('UPDATE cash_days SET revision=revision+1 WHERE business_date=?')->execute([$date]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function settleCash(PDO $db, string $date, string $actor, array $input): void
{
    if (!validCashDate($date)) throw new DomainException('Selecione um dia válido até hoje.');
    $db->beginTransaction();
    try {
        $day = lockCashDay($db, $date);
        $totals = cashTotals($db, $date);
        $seen = cashSnapshot((int) $day['revision'], $totals);
        if ($seen !== ($input['snapshot'] ?? null)) throw new DomainException('O caixa mudou. Confira os valores atualizados.');
        if ($input['action'] === 'cash_reopen') {
            if ($day['closed_at'] === null) throw new DomainException('O caixa já está aberto.');
            $db->prepare('UPDATE cash_days SET counted_cents=NULL,closed_at=NULL,closed_by=NULL,revision=revision+1 WHERE business_date=?')->execute([$date]);
            $event = 'reopen';
            $counted = $day['counted_cents'];
        } else {
            $amount = $input['counted'] ?? null;
            if (!is_string($amount) || !preg_match('/\A(\d{1,12})(?:[,.](\d{1,2}))?\z/', trim($amount), $parts)) {
                throw new DomainException('Informe o valor contado em reais, com até duas casas decimais.');
            }
            if ($day['closed_at'] !== null) throw new DomainException('Este caixa já está fechado.');
            $counted = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
            $db->prepare('UPDATE cash_days SET counted_cents=?, closed_by=?, closed_at=UTC_TIMESTAMP(),revision=revision+1 WHERE business_date=?')
                ->execute([$counted, $actor, $date]);
            $event = 'close';
        }
        $db->prepare('INSERT INTO cash_events(business_date,action,actor,counted_cents,expected_cents) VALUES (?,?,?,?,?)')
            ->execute([$date, $event, $actor, $counted, $totals['cash']]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}
