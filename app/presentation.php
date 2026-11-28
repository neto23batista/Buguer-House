<?php

declare(strict_types=1);

function presentationBaseUrl(): ?string
{
    $url = trim((string) getenv('APP_URL'));
    if ($url === '' || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)
        || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
        return null;
    }
    $host = strtolower($parts['host']);
    $ip = trim($host, '[]');
    if (!filter_var($ip, FILTER_VALIDATE_IP)
        && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return null;
    }
    return strtolower($parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

function presentationAbsoluteUrl(string $path = ''): ?string
{
    $base = presentationBaseUrl();
    if ($base === null || !preg_match('/\A[a-zA-Z0-9_\/.\-]*\z/', $path)
        || array_intersect(explode('/', $path), ['.', '..'])) {
        return null;
    }
    return $base . '/' . ltrim($path, '/');
}

function renderPresentationHead(string $title, string $description, bool $shareable = false): void
{
    $encode = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<link rel="icon" href="favicon.svg" type="image/svg+xml">' . "\n";
    if (!$shareable) {
        return;
    }
    foreach (['og:type' => 'website', 'og:site_name' => 'Burger House', 'og:title' => $title, 'og:description' => $description] as $property => $content) {
        echo '<meta property="' . $property . '" content="' . $encode($content) . '">' . "\n";
    }
    $url = presentationAbsoluteUrl();
    if ($url === null) {
        return;
    }
    echo '<link rel="canonical" href="' . $encode($url) . '">' . "\n";
    echo '<meta property="og:url" content="' . $encode($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . $encode(presentationAbsoluteUrl('assets/brand/social-card.png')) . '">' . "\n";
    echo '<meta property="og:image:width" content="1200">' . "\n";
    echo '<meta property="og:image:height" content="630">' . "\n";
    echo '<meta property="og:image:alt" content="Burger House — smash burger na chapa">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}

function presentationContact(): ?array
{
    $raw = trim((string) getenv('STORE_CONTACT_PHONE'));
    if (!preg_match('/\A\+?[0-9 ().-]{10,25}\z/', $raw)) {
        return null;
    }
    $phone = preg_replace('/\D/', '', $raw);
    if (strlen($phone) >= 12 && str_starts_with($phone, '55')) {
        $phone = substr($phone, 2);
    }
    if (!preg_match('/\A[1-9][0-9]{9,10}\z/', $phone)) {
        return null;
    }
    return [
        'href' => 'tel:+55' . $phone,
        'label' => preg_replace('/\A(\d{2})(\d{4,5})(\d{4})\z/', '($1) $2-$3', $phone),
    ];
}

function presentationHours(array $intervals): string
{
    if (!$intervals) {
        return 'Fechado';
    }
    $hours = [];
    foreach ($intervals as [$start, $end]) {
        if ($start === '00:00' && in_array($end, ['00:00', '24:00'], true)) {
            $hours[] = '24 horas';
            continue;
        }
        $displayEnd = $end === '24:00' ? '00:00' : $end;
        $nextDay = $end === '24:00' || $end <= $start;
        $hours[] = $start . ' às ' . $displayEnd . ($nextDay ? ' (dia seguinte)' : '');
    }
    return implode(' · ', $hours);
}

function presentationSpecialDates(array $exceptions, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('America/Sao_Paulo');
    $today = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->format('Y-m-d');
    $upcoming = array_filter($exceptions, static fn (string $date): bool => $date >= $today, ARRAY_FILTER_USE_KEY);
    ksort($upcoming);
    return $upcoming;
}
