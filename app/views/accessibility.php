<div class="access-tools" hidden data-access-tools>
    <button type="button" class="access-trigger" aria-label="Acessibilidade" aria-haspopup="dialog" aria-controls="access-panel">
        <span aria-hidden="true">Aa</span><span class="access-label">Acessibilidade</span>
    </button>
    <dialog class="access-panel" id="access-panel" aria-labelledby="access-title">
        <div class="access-heading">
            <h2 id="access-title">Acessibilidade</h2>
            <form method="dialog"><button class="access-close" aria-label="Fechar acessibilidade" autofocus>×</button></form>
        </div>
        <label class="access-size">Tamanho do texto
            <select data-access-size>
                <option value="normal">Padrão</option>
                <option value="large">Ampliado · 125%</option>
                <option value="extra">Ampliado · 150%</option>
            </select>
        </label>
        <label class="access-check"><input type="checkbox" data-access-contrast> Alto contraste</label>
        <label class="access-check"><input type="checkbox" data-access-motion> Reduzir movimento</label>
        <button type="button" class="access-reset">Restaurar padrão do dispositivo</button>
        <p class="access-feedback" role="status"></p>
    </dialog>
</div>
