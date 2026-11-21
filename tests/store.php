<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/presentation.php';

$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$environment = [];
$originalHostHeader = $_SERVER['HTTP_HOST'] ?? null;
foreach (['STORE_PICKUP_ADDRESS', 'APP_URL', 'APP_DEMO', 'STORE_CONTACT_PHONE'] as $key) {
    $environment[$key] = getenv($key);
    putenv($key);
}

try {
    $openSchedule = array_fill(1, 7, [['00:00', '24:00']]);
    $settings = ['paused' => false, 'pickup_address' => '', 'prep_minutes' => 25, 'schedule' => $openSchedule, 'exceptions' => []];
    $noon = new DateTimeImmutable('2026-11-23 12:00:00', new DateTimeZone('America/Sao_Paulo'));
    $check(!storeAvailability($settings, $noon)['accepting'], 'Uma instalação antiga sem endereço não pode aceitar pedidos.');
    $settings['pickup_address'] = '   ';
    $check(!storeAvailability($settings, $noon)['accepting'], 'Um endereço só com espaços não pode liberar pedidos.');
    $settings['pickup_address'] = 'Rua de teste, 123';
    $check(storeAvailability($settings, $noon)['accepting'], 'Loja configurada e aberta deve aceitar pedidos.');
    $settings['paused'] = true;
    $check(!storeAvailability($settings, $noon)['accepting'], 'Pausa deve bloquear pedidos durante o horário de atendimento.');
    $settings['paused'] = false;
    $settings['schedule'] = array_fill(1, 7, []);
    $check(!storeAvailability($settings, $noon)['accepting'], 'Horários vazios devem manter a loja fechada.');
    $settings['schedule'][1] = [['18:00', '02:00']];
    $nextMorning = $noon->modify('+1 day')->setTime(1, 0);
    $check(storeAvailability($settings, $nextMorning)['accepting'], 'Um turno deve continuar depois da meia-noite.');
    $settings['exceptions']['2026-11-24'] = [];
    $check(!storeAvailability($settings, $nextMorning)['accepting'], 'Uma data especial fechada deve substituir o turno da véspera.');

    $form = ['pickup_address' => '', 'prep_minutes' => '25', 'schedule' => [], 'exceptions' => []];
    foreach (storeWeekdays() as $day => $label) {
        $form['schedule'][$day] = [['start' => '', 'end' => ''], ['start' => '', 'end' => '']];
    }
    [, $errors] = validateStoreSettings($form);
    $check(isset($errors['pickup_address']), 'Despausar sem endereço deve gerar um erro no formulário.');
    [, $errors] = validateStoreSettings($form + ['paused' => '1']);
    $check($errors === [], 'Deve ser possível salvar uma loja pausada antes de preencher o endereço.');
    putenv('STORE_PICKUP_ADDRESS= Rua alternativa, 45 ');
    [, $errors] = validateStoreSettings($form);
    $check($errors === [], 'Um endereço alternativo válido deve permitir a configuração.');
    $check(storePickupAddress('') === 'Rua alternativa, 45', 'O endereço alternativo deve ser normalizado.');
    $check(storePickupAddress(' Rua principal, 12 ') === 'Rua principal, 12', 'O endereço do painel deve prevalecer sobre o alternativo.');
    putenv("STORE_PICKUP_ADDRESS=Rua inválida\n123");
    $check(storePickupAddress('') === '', 'Endereço alternativo inválido deve manter pedidos indisponíveis.');

    $check(!presentationDemoMode(), 'A demonstração deve estar desligada sem configuração.');
    putenv('APP_DEMO=1');
    $check(presentationDemoMode(), 'APP_DEMO=1 deve ativar a demonstração.');
    putenv('APP_DEMO=false');
    $check(!presentationDemoMode(), 'Uma string falsa não pode ativar a demonstração.');

    foreach (['javascript:alert(1)', 'https://user:secret@example.com', 'https://example.com/path', 'https://example.com?token=private', 'https://example.com#fragment', "https://exam\nple.com"] as $url) {
        putenv('APP_URL=' . $url);
        $check(presentationBaseUrl() === null, 'APP_URL inválida não deve aparecer nos metadados: ' . str_replace("\n", '\\n', $url));
    }
    putenv('APP_URL=https://Burger.Example/');
    $check(presentationAbsoluteUrl('assets/brand/social-card.png') === 'https://burger.example/assets/brand/social-card.png', 'A imagem social deve usar a origem configurada.');
    $check(presentationAbsoluteUrl('../private') === null, 'Não deve ser possível formar URLs com travessia de diretório.');
    $check(presentationAbsoluteUrl('https://other.example/image.png') === null, 'O caminho de uma imagem não pode trocar a origem.');
    ob_start();
    renderPresentationHead('Burger <House>', 'Descrição "segura"', true);
    $metadata = ob_get_clean();
    $check(str_contains($metadata, 'Burger &lt;House&gt;') && str_contains($metadata, '&quot;segura&quot;'), 'Os metadados devem escapar texto e atributos.');
    $check(str_contains($metadata, 'https://burger.example/assets/brand/social-card.png'), 'Metadados sociais devem conter URL absoluta da imagem.');
    putenv('APP_URL');
    $_SERVER['HTTP_HOST'] = 'untrusted.example';
    ob_start();
    renderPresentationHead('Burger House', 'Demonstração', true);
    $metadata = ob_get_clean();
    $check(!str_contains($metadata, 'og:image') && !str_contains($metadata, 'untrusted.example'), 'Host de requisição não deve ser usado para gerar uma imagem social.');
    unset($_SERVER['HTTP_HOST']);

    putenv('STORE_CONTACT_PHONE=+55 (11) 91234-5678');
    $contact = presentationContact();
    $check($contact === ['href' => 'tel:+5511912345678', 'label' => '(11) 91234-5678'], 'Contato deve normalizar o código do país e formatar o número.');
    putenv('STORE_CONTACT_PHONE=javascript:alert(1)');
    $check(presentationContact() === null, 'Contato inválido não deve gerar um link.');
    $check(presentationHours([]) === 'Fechado', 'Horário vazio deve indicar fechamento.');
    $check(presentationHours([['00:00', '24:00']]) === '24 horas', 'Um dia inteiro deve ser legível.');
    $check(presentationHours([['18:00', '02:00']]) === '18:00 às 02:00 (dia seguinte)', 'Turnos noturnos devem explicitar o dia seguinte.');
    $check(presentationHours([['11:00', '14:00'], ['18:00', '23:00']]) === '11:00 às 14:00 · 18:00 às 23:00', 'Os dois turnos devem aparecer.');
    $specialDates = presentationSpecialDates(['2026-11-25' => [], '2026-11-22' => [], '2026-11-23' => []], $noon);
    $check(array_keys($specialDates) === ['2026-11-23', '2026-11-25'], 'Datas especiais devem incluir hoje, excluir o passado e ficar em ordem.');
    $imageSize = getimagesize(__DIR__ . '/../public/assets/brand/social-card.png');
    $check($imageSize !== false && $imageSize[0] === 1200 && $imageSize[1] === 630 && $imageSize['mime'] === 'image/png', 'A imagem social deve corresponder ao formato e às dimensões dos metadados.');

    echo 'Loja e apresentação: ' . $assertions . " verificações passaram.\n";
} finally {
    foreach ($environment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
    if ($originalHostHeader === null) {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $originalHostHeader;
    }
}
