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
    $sql = 'SELECT pickup_address, prep_minutes, paused, schedule, exceptions FROM store_settings WHERE id = 1';
    $row = $db->query($sql . ($lock ? ' FOR SHARE' : ''))->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new PDOException('Configurações da loja ausentes.');
    }

    return [
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

    $zone = new DateTimeZone('America/Sao_Paulo');
    $local = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $today = $local->setTime(0, 0);
    $minute = storeClockMinutes($local->format('H:i'));
    $isException = array_key_exists($today->format('Y-m-d'), $settings['exceptions']);
    $next = null;

    if (!$isException) {
        foreach (storeIntervalsOn($settings, $today->modify('-1 day')) as [$start, $end]) {
            [, $until] = storeClockInterval($start, $end);
            if ($minute + 1440 < $until) {
                return storeOpenStatus($settings['prep_minutes']);
            }
        }
    }

    // Each special date can override at most one matching weekday in the search.
    $searchDays = (count($settings['exceptions']) + 1) * 7;
    for ($offset = 0; $offset <= $searchDays; $offset++) {
        $date = $today->modify("+{$offset} days");
        foreach (storeIntervalsOn($settings, $date) as [$start, $end]) {
            [$from, $until] = storeClockInterval($start, $end);
            if ($offset === 0 && $minute >= $from && $minute < $until) {
                return storeOpenStatus($settings['prep_minutes']);
            }
            if ($offset > 0 || $minute < $from) {
                $next = $date->setTime(intdiv($from, 60), $from % 60);
                break 2;
            }
        }
    }

    if ($next === null) {
        // A store with all weekdays closed can still open at a distant special date.
        $exceptions = $settings['exceptions'];
        ksort($exceptions);
        foreach ($exceptions as $date => $intervals) {
            if ($date > $today->format('Y-m-d') && $intervals) {
                $next = new DateTimeImmutable($date . ' ' . $intervals[0][0], $zone);
                break;
            }
        }
    }

    return [
        'accepting' => false,
        'state' => 'closed',
        'label' => 'Fechada',
        'message' => $next === null
            ? 'A loja está fechada para novos pedidos.'
            : 'Próxima abertura em ' . $next->format('d/m') . ' às ' . $next->format('H:i') . '.',
        'next_open' => $next?->format(DATE_ATOM),
    ];
}

function storeOpenStatus(int $minutes): array
{
    return [
        'accepting' => true,
        'state' => 'open',
        'label' => 'Aberta',
        'message' => "Previsão de retirada em {$minutes} min.",
        'next_open' => null,
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
        'UPDATE store_settings SET pickup_address = ?, prep_minutes = ?, paused = ?, schedule = ?, exceptions = ? WHERE id = 1',
    );
    $query->execute([
        $values['pickup_address'],
        (int) $values['prep_minutes'],
        (int) $values['paused'],
        json_encode((object) $values['schedule'], JSON_THROW_ON_ERROR),
        json_encode((object) $values['exceptions'], JSON_THROW_ON_ERROR),
    ]);
}
