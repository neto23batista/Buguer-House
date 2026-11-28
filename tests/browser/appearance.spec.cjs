'use strict';
const { test, expect } = require('@playwright/test');

test('acessibilidade por teclado, persistência e restauração das preferências', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.goto('/');
    const trigger = page.getByRole('button', { name: 'Acessibilidade', exact: true });
    await trigger.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Acessibilidade' });
    await expect(dialog.getByRole('button', { name: 'Fechar acessibilidade' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(dialog.getByRole('combobox')).toBeFocused();
    await dialog.getByRole('combobox').selectOption('extra');
    await dialog.getByLabel('Alto contraste').check();
    await dialog.getByLabel('Reduzir movimento').check();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    for (const path of ['/checkout.php', '/admin/', '/']) {
        await page.goto(path);
        await expect(page.locator('html')).toHaveCSS('font-size', '24px');
        await expect(page.locator('html')).toHaveAttribute('data-high-contrast', '');
        await expect(page.locator('html')).toHaveAttribute('data-reduce-motion', '');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
    await trigger.click();
    await dialog.getByRole('button', { name: 'Restaurar padrão do dispositivo' }).click();
    await expect(page.locator('html')).toHaveCSS('font-size', '16px');
    await expect(page.locator('html')).not.toHaveAttribute('data-high-contrast', '');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect(dialog.getByLabel('Reduzir movimento')).toBeChecked();
    await expect(page.locator('html')).toHaveCSS('scroll-behavior', 'auto');
});

test('busca por ingrediente combina com a categoria e informa resultados', async ({ page }) => {
    await page.goto('/');
    const query = page.getByRole('searchbox', { name: 'Buscar por produto ou ingrediente' });
    await query.fill('PICLES');
    await expect(page.locator('.product:visible').first()).toContainText(/picles/i);
    await page.getByRole('button', { name: 'Bebidas', exact: true }).click();
    await expect(page.locator('[data-filter-empty]')).toBeVisible();
    await expect(page.locator('[data-result-count]')).toHaveText('0 itens encontrados.');
    await query.fill('');
    await expect(page.locator('[data-category="bebidas"]')).toBeVisible();
    await expect(page.locator('[data-category="burgers"]')).toBeHidden();
});
