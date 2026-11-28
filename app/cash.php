<?php

declare(strict_types=1);

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
    $query = $db->prepare('SELECT method, SUM(amount_cents) FROM payments WHERE business_date=? GROUP BY method');
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
        $db->commit();
        return compact('day', 'totals', 'snapshot', 'difference', 'events');
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
