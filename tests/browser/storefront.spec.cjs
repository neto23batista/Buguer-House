'use strict';
const { test, expect } = require('@playwright/test');
const productId = process.env.BH_BROWSER_PRODUCT_ID;

function addFixture(page) {
    return page.locator(`[data-cart-form]:has(input[name="product_id"][value="${productId}"]) button`).first().click();
}

test('carrinho abre por teclado, mantém foco e não gera overflow no celular', async ({ page }) => {
    await page.goto('/');
    const opener = page.locator('header [data-cart-open]');
    await opener.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#carrinho')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#carrinho')).not.toBeVisible();
    await expect(opener).toBeFocused();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('sessão perdida oferece recuperação persistente e recarga libera o carrinho', async ({ page, context }) => {
    await page.goto('/');
    await context.clearCookies();
    await addFixture(page);
    await expect(page.locator('[data-status]')).toContainText('Sua sessão mudou');
    await page.locator('header [data-cart-open]').click();
    const recovery = page.locator('[data-cart-recovery]');
    await expect(recovery).toBeVisible();
    await expect(page.locator('.product [data-cart-form] button').first()).toBeDisabled();
    await page.waitForTimeout(3600);
    await expect(recovery).toBeVisible();
    await recovery.getByRole('link', { name: 'Recarregar e conferir carrinho' }).click();
    await page.locator('[data-cart-close]').first().click();
    await addFixture(page);
    await expect(page.locator('header [data-cart-count]')).toHaveText('1');
});

test('falha de rede mantém o carrinho em revisão sem repetir o POST', async ({ page }) => {
    await page.goto('/');
    let posts = 0;
    await page.route('**/*', async route => {
        if (route.request().method() === 'POST') { posts++; await route.abort('failed'); }
        else await route.continue();
    });
    await addFixture(page);
    await page.locator('header [data-cart-open]').click();
    await expect(page.locator('[data-cart-recovery]')).toBeVisible();
    await expect(page.locator('.product [data-cart-form] button').first()).toBeDisabled();
    expect(posts).toBe(1);
});

test('checkout informa envio, impede duplicação e restaura controles ao voltar', async ({ page }) => {
    await page.goto('/');
    await addFixture(page);
    await expect(page.locator('header [data-cart-count]')).toHaveText('1');
    await page.locator('header [data-cart-open]').click();
    await page.locator('[data-checkout]').click();
    await page.getByLabel('Nome', { exact: true }).fill('Cliente E2E');
    await page.getByLabel('Telefone com DDD').fill('(11) 90000-0000');
    let posts = 0;
    await page.route('**/checkout.php', async route => {
        if (route.request().method() !== 'POST') { await route.continue(); return; }
        posts++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Resposta E2E</h1>' });
    });
    const submissions = await page.evaluate(() => {
        const form = document.querySelector('[data-checkout-form]');
        const states = [];
        form.addEventListener('submit', event => {
            states.push({ prevented: event.defaultPrevented,
                disabled: document.querySelector('[data-submit-order]').disabled,
                label: document.querySelector('[data-submit-order]').textContent,
                progressVisible: !document.querySelector('[data-checkout-progress]').hidden });
        });
        form.requestSubmit();
        form.requestSubmit();
        return states;
    });
    expect(submissions).toEqual([
        { prevented: false, disabled: true, label: 'Enviando pedido…', progressVisible: true },
        { prevented: true, disabled: true, label: 'Enviando pedido…', progressVisible: true },
    ]);
    await expect.poll(() => posts).toBe(1);
    await expect(page.getByRole('heading', { name: 'Resposta E2E' })).toBeVisible();
    await page.goBack();
    await expect(page.locator('[data-submit-order]')).toBeEnabled();
    await expect(page.locator('[data-submit-order]')).toContainText('Enviar pedido de teste');
    await expect(page.locator('[data-checkout-progress]')).toBeHidden();
});
