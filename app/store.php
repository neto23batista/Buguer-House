<?php

declare(strict_types=1);

function storeWeekdays(): array
{
    return [
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
        7 => 'Domingo',
    ];
}

function storePickupAddress(string $address): string
{
    $address = trim($address);
    if ($address === '') {
        $address = trim((string) getenv('STORE_PICKUP_ADDRESS'));
    }

    return preg_match('/\A[^\p{C}]{1,250}\z/u', $address) === 1 ? $address : '';
}

function loadStore(PDO $db, bool $lock = false): array
{
    $sql = 'SELECT revision, pickup_address, prep_minutes, paused, schedule, exceptions FROM store_settings WHERE id = 1';
    // Exclusive locking also works on TiDB; checkouts briefly serialize on this store row.
    $row = $db->query($sql . ($lock ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new PDOException('Configurações da loja ausentes.');
    }

    return [
        'revision' => (int) $row['revision'],
        'pickup_address' => storePickupAddress($row['pickup_address']),
        'pickup_address_input' => $row['pickup_address'],
        'prep_minutes' => (int) $row['prep_minutes'],
        'paused' => (bool) $row['paused'],
        'schedule' => json_decode($row['schedule'], true, 512, JSON_THROW_ON_ERROR),
        'exceptions' => json_decode($row['exceptions'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function storeClockMinutes(string $time): int
{
    [$hour, $minute] = explode(':', $time);
    return (int) $hour * 60 + (int) $minute;
}

function storeClockInterval(string $start, string $end): array
{
    $from = storeClockMinutes($start);
    $until = storeClockMinutes($end);
    return [$from, $until <= $from ? $until + 1440 : $until];
}

function storeIntervalsOn(array $settings, DateTimeImmutable $date): array
{
    return $settings['exceptions'][$date->format('Y-m-d')]
        ?? $settings['schedule'][(int) $date->format('N')]
        ?? [];
}

/** Merge touching shifts. A null end means the regular schedule continues around the clock. */
function storeServicePeriods(array $settings, DateTimeImmutable $today): Generator
{
    // A full regular week repeats until the next exception. Sample both sides of
    // each exception so a distant holiday doesn't require visiting every date.
    $anchors = [$today];
    foreach (array_keys($settings['exceptions']) as $special) {
        if ($special >= $today->format('Y-m-d')) {
            $anchors[] = new DateTimeImmutable($special, $today->getTimezone());
        }
    }
    $dates = [];
    foreach ($anchors as $anchor) {
        for ($offset = -1; $offset <= 8; $offset++) {
            $date = $anchor->modify("{$offset} days");
            $dates[$date->format('Y-m-d')] = $date;
        }
    }
    ksort($dates);
    $start = $end = $boundary = null;
    foreach ($dates as $day) {
        if ($boundary !== null && $day > $boundary && $start !== null) {
            if ($start <= $boundary->modify('-7 days') && $end >= $boundary) {
                $end = $day;
            } else {
                yield [$start, $end];
                $start = $end = null;
            }
        }
        $boundary = $day->modify('+1 day');
        foreach (storeIntervalsOn($settings, $day) as [$from, $to]) {
            [$first, $last] = storeClockInterval($from, $to);
            $opens = $day->setTime(intdiv($first, 60), $first % 60);
            $closes = $day->setTime(intdiv($last, 60), $last % 60);
            if (array_key_exists($boundary->format('Y-m-d'), $settings['exceptions'])) {
                $closes = min($closes, $boundary);
            }
            if ($start !== null && $opens > $end) {
                yield [$start, $end];
                $start = $end = null;
            }
            $start ??= $opens;
            $end = $end === null ? $closes : max($end, $closes);
        }
    }
    if ($start !== null) {
        $continuous = $start <= $boundary->modify('-7 days') && $end >= $boundary;
        yield [$start, $continuous ? null : $end];
    }
}

function storeAvailability(array $settings, ?DateTimeImmutable $now = null): array
{
    if (trim((string) ($settings['pickup_address'] ?? '')) === '') {
        return [
            'accepting' => false,
            'state' => 'unavailable',
            'label' => 'Pedidos indisponíveis',
            'message' => 'A retirada está temporariamente indisponível. Consulte as informações de atendimento.',
            'next_open' => null,
        ];
    }

    if ($settings['paused']) {
        return [
            'accepting' => false,
            'state' => 'paused',
            'label' => 'Pausada',
            'message' => 'A loja pausou novos pedidos. Tente novamente em instantes.',
            'next_open' => null,
        ];
    }

    return storeHoursAvailability($settings, $now);
}

function storeHoursAvailability(array $settings, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('America/Sao_Paulo');
    $local = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $minutes = (int) $settings['prep_minutes'];
    $expired = null;
    $next = null;
    foreach (storeServicePeriods($settings, $local->setTime(0, 0)) as [$start, $end]) {
        $cutoff = $end?->modify("-{$minutes} minutes");
        if ($cutoff !== null && ($cutoff < $start || $end <= $local)) {
            continue;
        }
        if ($start > $local) {
            $next = $start;
            break;
        }
        if ($cutoff !== null && $local->getTimestamp() > $cutoff->getTimestamp()) {
            $expired = $cutoff;
            continue;
        }
        $limit = $cutoff?->format($cutoff->format('Y-m-d') === $local->format('Y-m-d') ? 'H:i' : 'd/m · H:i');
        return [
            'accepting' => true, 'state' => 'open', 'label' => 'Aberta',
            'message' => "Previsão de retirada em {$minutes} min. " . ($limit === null ? 'Atendimento 24 horas.' : "Último pedido do turno às {$limit}."),
            'last_order_label' => $limit, 'next_open' => null,
        ];
    }
    $message = $next === null ? 'A loja está fechada para novos pedidos.'
        : 'Próxima abertura em ' . $next->format('d/m') . ' às ' . $next->format('H:i') . '.';
    if ($expired !== null) {
        $message = 'O prazo deste turno terminou às ' . $expired->format('H:i') . '. ' . $message;
    }
    return [
        'accepting' => false,
        'state' => 'closed',
        'label' => 'Fechada',
        'message' => $message,
        'next_open' => $next?->format(DATE_ATOM),
    ];
}

function storeFormString(mixed $value, int $maxBytes): string
{
    return is_string($value) ? trim(substr($value, 0, $maxBytes)) : '';
}

function storeFormInterval(mixed $row): array
{
    return [
        storeFormString($row['start'] ?? null, 6),
        storeFormString($row['end'] ?? null, 6),
    ];
}

function normalizeStoreInterval(string $start, string $end): ?array
{
    $normalTime = '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/';
    if (!preg_match($normalTime, $start) || ($end !== '24:00' && !preg_match($normalTime, $end))) {
        return null;
    }
    if ($start === $end && $start !== '00:00') {
        return null;
    }
    return [$start, $end === '00:00' ? '24:00' : $end];
}

function validateStoreSettings(array $input): array
{
    $address = storeFormString($input['pickup_address'] ?? null, 1001);
    $minutes = storeFormString($input['prep_minutes'] ?? null, 5);
    $paused = ($input['paused'] ?? null) === '1';
    $errors = [];
    if (!preg_match('/\A[^\p{C}]{0,250}\z/u', $address)) {
        $errors['pickup_address'] = 'Use até 250 caracteres no endereço.';
    } elseif (!$paused && storePickupAddress($address) === '') {
        $errors['pickup_address'] = 'Informe o endereço de retirada antes de liberar novos pedidos.';
    }
    if (!preg_match('/\A[0-9]{1,3}\z/', $minutes) || (int) $minutes < 5 || (int) $minutes > 240) {
        $errors['prep_minutes'] = 'Informe entre 5 e 240 minutos.';
    }

    $schedule = [];
    $rawSchedule = $input['schedule'] ?? null;
    if (!is_array($rawSchedule)) {
        $errors['schedule'] = 'Confira os horários de funcionamento.';
    }

    foreach (storeWeekdays() as $day => $label) {
        $schedule[$day] = [];
        $rowError = 'Preencha um início e um fim válidos ou deixe o turno vazio.';
        $rawRows = $rawSchedule[$day] ?? null;
        if (!is_array($rawRows)) {
            $errors['schedule_' . $day] = $rowError;
            continue;
        }
        $intervals = [];
        foreach ([0, 1] as $index) {
            [$start, $end] = storeFormInterval($rawRows[$index] ?? null);
            if ($start === '' && $end === '') {
                continue;
            }
            $schedule[$day][] = [$start, $end];
            $interval = normalizeStoreInterval($start, $end);
            if ($interval === null) {
                $errors['schedule_' . $day] = $rowError;
                continue;
            }
            $intervals[] = $interval;
        }
        sort($intervals);
        if (count($intervals) === 2) {
            [, $firstEnd] = storeClockInterval(...$intervals[0]);
            [$secondStart] = storeClockInterval(...$intervals[1]);
            if ($secondStart < $firstEnd) {
                $errors['schedule_' . $day] = "Os turnos de {$label} se sobrepõem.";
            }
        }
        if (!isset($errors['schedule_' . $day])) {
            $schedule[$day] = $intervals;
        }
    }

    $exceptionRows = [];
    $exceptions = [];
    $rawExceptions = $input['exceptions'] ?? [];
    if (!is_array($rawExceptions) || count($rawExceptions) > 41) {
        $errors['exceptions'] = 'Use até 40 datas especiais.';
        $rawExceptions = [];
    }
    foreach ($rawExceptions as $row) {
        if (($row['remove'] ?? null) === '1') {
            continue;
        }
        $date = storeFormString($row['date'] ?? null, 11);
        [$start, $end] = storeFormInterval($row);
        if ($date === '' && $start === '' && $end === '') {
            continue;
        }
        $index = count($exceptionRows);
        $exceptionRows[] = ['date' => $date, 'start' => $start, 'end' => $end];
        $validDate = preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        if (!$validDate) {
            $errors['exception_' . $index] = 'Selecione a data.';
        } elseif (array_key_exists($date, $exceptions)) {
            $errors['exception_' . $index] = 'Esta data já foi configurada.';
        } elseif ($start === '' && $end === '') {
            $exceptions[$date] = [];
        } elseif (($interval = normalizeStoreInterval($start, $end)) === null) {
            $errors['exception_' . $index] = 'Preencha os dois horários ou deixe os dois vazios para fechar.';
        } else {
            $exceptions[$date] = [$interval];
        }
    }
    if (count($exceptions) > 40) {
        $errors['exceptions'] = 'Use até 40 datas especiais.';
    }

    return [[
        'revision' => filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT) ?: 0,
        'pickup_address' => $address,
        'pickup_address_input' => $address,
        'prep_minutes' => $minutes,
        'paused' => $paused,
        'schedule' => $schedule,
        'exceptions' => $exceptions,
        'exception_rows' => $exceptionRows,
    ], $errors];
}

function saveStoreSettings(PDO $db, array $values): void
{
    $query = $db->prepare(
        'UPDATE store_settings SET pickup_address = ?, prep_minutes = ?, paused = ?, schedule = ?, exceptions = ?, revision = revision + 1 WHERE id = 1 AND revision = ?',
    );
    $query->execute([
        $values['pickup_address'],
        (int) $values['prep_minutes'],
        (int) $values['paused'],
        json_encode((object) $values['schedule'], JSON_THROW_ON_ERROR),
        json_encode((object) $values['exceptions'], JSON_THROW_ON_ERROR),
        $values['revision'],
    ]);
    if ($query->rowCount() !== 1) {
        throw new DomainException('A loja foi atualizada. Confira os dados atuais e reaplique sua alteração.');
    }
}
