'use strict';
const { test, expect } = require('@playwright/test');
const { randomInt } = require('node:crypto');
const path = require('node:path');

const productId = process.env.BH_BROWSER_PRODUCT_ID;
const owner = process.env.ADMIN_USER;
const password = process.env.BH_BROWSER_PASSWORD;

async function login(page, user = owner) {
    await page.goto('/admin/');
    await page.locator('#username').fill(user);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page.locator('.admin-sections')).toBeVisible();
}

async function checkout(page) {
    await page.goto('/');
    const product = page.locator(`article:has(input[name="product_id"][value="${productId}"])`);
    await product.getByRole('button').click();
    await expect(page.locator('header [data-cart-count]')).toHaveText('1');
    await page.locator('header [data-cart-open]').click();
    await page.locator('[data-checkout]').click();
    await page.locator('#customer-name').fill('Cliente Operação');
    await page.locator('#customer-phone').fill('119' + randomInt(10000000, 99999999));
}

async function sendOrder(page) {
    await page.locator('[data-submit-order]').click();
    await expect(page.locator('[data-receipt-status]')).toBeVisible();
    return new URL(page.url()).searchParams.get('pedido');
}

test('pedido, revisão de prazo, senha, cozinha, recebimento e fechamento de caixa', async ({ page, browser }) => {
    test.setTimeout(60000);
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    const cashTab = await adminContext.newPage();
    try {
        await login(admin);
        await checkout(page);
        await admin.goto('/admin/?secao=loja');
        const minutes = (Number(await admin.locator('#store-prep').inputValue()) % 235) + 5;
        await admin.locator('#store-prep').fill(String(minutes));
        await admin.getByRole('button', { name: 'Salvar configurações' }).click();
        await page.locator('[data-submit-order]').click();
        await expect(page.getByRole('alert')).toContainText('prazo de preparo mudou');
        await expect(page.locator('#customer-name')).toHaveValue('Cliente Operação');
        const code = await sendOrder(page);
        const ticket = (await page.getByText(/Senha de retirada \d+/, {exact:false}).last().textContent()).match(/retirada (\d+)/)[1];
        await admin.goto('/admin/');
        await admin.locator('[name=buscar]').fill(ticket);
        await admin.getByRole('button', {name:'Buscar pedido'}).click();
        await expect(admin.locator('#pedido-detalhe')).toContainText(code);
        await cashTab.goto('/admin/?secao=caixa');
        await admin.goto('/admin/cozinha.php');
        const card = admin.locator('[data-kitchen-order]').filter({hasText:code});
        await expect(card).toContainText('Retirada prevista');
        await card.getByRole('button', {name:'Iniciar preparo'}).click();
        await card.getByRole('button', {name:'Marcar como pronto'}).click();
        await card.locator('[name=payment_method]').selectOption('cash');
        await card.getByRole('button', {name:'Concluir retirada'}).click();
        await expect(card).toHaveCount(0);
        await page.reload();
        await expect(page.locator('[data-receipt-status]')).toHaveAttribute('data-state','completed');
        await admin.goto('/admin/?secao=relatorios');
        await expect(admin.locator('.report-metrics')).toContainText('Prontos no prazo');
        await expect(admin.locator('.report-list').first()).toContainText('Produto E2E');
        await cashTab.locator('[name=counted]').fill('0,00');
        await cashTab.getByRole('button', {name:'Fechar caixa'}).click();
        await expect(cashTab.locator('.admin-alert')).toContainText('caixa mudou');
        await admin.goto('/admin/?secao=caixa');
        const cash = admin.locator('.report-metrics div').filter({hasText:'Dinheiro'}).locator('dd');
        const amount = (await cash.textContent()).replace(/[^0-9,]/g,'');
        expect(Number(amount.replace(',','.'))).toBeGreaterThanOrEqual(12.34);
        await admin.locator('[name=counted]').fill(amount);
        await admin.getByRole('button', {name:'Fechar caixa'}).click();
        await expect(admin.getByText(/Diferença/)).toContainText('+R$ 0,00');
        await admin.getByRole('button', {name:'Reabrir caixa'}).click();
        await expect(admin.getByRole('button', {name:'Fechar caixa'})).toBeVisible();
    } finally {
        await adminContext.close();
    }
});

test('pausa, cadastros em duas abas e foto persistida', async ({page,context,browser}) => {
    await login(page);
    const other = await context.newPage();
    const customer = await browser.newPage();
    try {
        for (const tab of [page,other]) await tab.goto('/admin/?secao=loja');
        await page.locator('[name=paused]').check();
        await page.getByRole('button', {name:'Salvar configurações'}).click();
        await other.locator('#store-prep').fill('45');
        await other.getByRole('button', {name:'Salvar configurações'}).click();
        await expect(other.getByRole('alert')).toContainText('atualizada');
        await expect(other.locator('[name=paused]')).toBeChecked();
        await customer.goto('/');
        await customer.locator(`[data-cart-form]:has(input[value="${productId}"]) button`).first().click();
        await expect(customer.locator('header [data-cart-count]')).toHaveText('1');
        await customer.locator('header [data-cart-open]').click();
        await expect(customer.locator('#cart-content')).toContainText('pausou');
        await expect(customer.locator('[data-checkout]')).toHaveCount(0);
        await customer.goto('/checkout.php');
        await expect(customer.locator('#customer-phone')).toHaveCount(0);
        await other.locator('[name=paused]').uncheck();
        await other.getByRole('button', {name:'Salvar configurações'}).click();
        for (const tab of [page,other]) await tab.goto('/admin/?secao=cardapio&produto=' + productId);
        await page.locator('#product-upload').setInputFiles(path.resolve('public/assets/img/house-smash.webp'));
        await page.getByRole('button', {name:'Salvar produto'}).click();
        await expect(page.locator('#product-image option:checked')).toHaveText('Foto enviada');
        const url = await page.locator('.admin-catalog-image[src*="foto.php"]').first().getAttribute('src');
        const response = await page.request.get(new URL(url,page.url()).href);
        expect(response.headers()['content-type']).toContain('image/jpeg');
        expect((await response.body()).subarray(0,2).toString('hex')).toBe('ffd8');
        await other.locator('#product-price').fill('75,00');
        await other.getByRole('button', {name:'Salvar produto'}).click();
        await expect(other.getByRole('alert')).toContainText('cadastro mudou');
        await expect(other.locator('#product-price')).toHaveValue('12,34');
    } finally {
        await customer.close();
    }
});

test('operador individual, cancelamento público e revogação de acesso', async ({page,browser}) => {
    const user = 'op-' + owner + '-' + randomInt(1000000);
    const operator = await browser.newPage();
    const customer = await browser.newPage();
    try {
        await login(page);
        await page.goto('/admin/?secao=equipe');
        await page.locator('[name=staff_name]').first().fill(user);
        await page.locator('[name=staff_password]').first().fill(password);
        await page.getByRole('button', {name:'Criar operador'}).click();
        await login(operator,user);
        await operator.goto('/admin/?secao=caixa');
        await expect(operator.getByRole('heading',{name:'Acesso restrito'})).toBeVisible();
        await checkout(customer);
        const code = await sendOrder(customer);
        await operator.goto('/admin/cozinha.php');
        const card = operator.locator('[data-kitchen-order]').filter({hasText:code});
        await card.locator('summary').click();
        await card.locator('[name=customer_reason]').selectOption('Item indisponível');
        await card.locator('[name=cancel_reason]').fill('Observação exclusiva da equipe');
        await card.getByRole('button',{name:'Confirmar cancelamento'}).click();
        await expect(card).toHaveCount(0);
        await customer.locator('[data-private-receipt]').click();
        await expect(customer.locator('[data-receipt-status]')).toContainText('Item indisponível');
        await expect(customer.getByRole('link',{name:/Falar com a loja/})).toBeVisible();
        await expect(customer.locator('body')).not.toContainText('Observação exclusiva');
        await page.locator('section').filter({has:page.getByRole('heading',{name:user,exact:true})}).getByRole('button',{name:'Desativar conta'}).click();
        await operator.goto('/admin/cozinha.php');
        await expect(operator.locator('#username')).toBeVisible();
    } finally {
        await operator.close();
        await customer.close();
    }
});
