<?php if (!$storeSettings): ?>
    <p class="admin-panel admin-empty" role="alert">As configurações da loja estão indisponíveis.</p>
<?php else: ?>
    <?php
    $timeValue = static fn (string $time): string => escape($time === '24:00' ? '00:00' : $time);
    $errorAttributes = static fn (string $key): string => isset($storeErrors[$key])
        ? ' aria-invalid="true" aria-describedby="store-' . $key . '-error"'
        : '';
    $renderStoreError = static function (string $key) use ($storeErrors): void {
        if (isset($storeErrors[$key])) {
            echo '<p class="admin-field-error" id="store-' . $key . '-error">' . escape($storeErrors[$key]) . '</p>';
        }
    };
    $exceptionRows = $storeSettings['exception_rows'] ?? [];
    if (!isset($storeSettings['exception_rows'])) {
        foreach ($storeSettings['exceptions'] as $date => $intervals) {
            [$start, $end] = $intervals[0] ?? ['', ''];
            $exceptionRows[] = ['date' => $date, 'start' => $start, 'end' => $end];
        }
    }
    if (count($exceptionRows) < 40) {
        $exceptionRows[] = ['date' => '', 'start' => '', 'end' => ''];
    }
    ?>

    <?php if (!empty($readiness)): ?>
        <section class="admin-panel admin-detail" aria-labelledby="store-readiness">
            <h2 id="store-readiness">Falta para receber pedidos</h2>
            <ul><?php foreach ($readiness as $item): ?><li><?= escape($item) ?></li><?php endforeach; ?></ul>
        </section>
    <?php endif; ?>
    <form class="store-form" method="post" action="./?secao=loja">
        <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
        <input type="hidden" name="action" value="save_store">
        <input type="hidden" name="revision" value="<?= (int) ($storeSettings['revision'] ?? 0) ?>">

        <section class="admin-panel store-panel store-profile" aria-labelledby="store-profile-title">
            <h2 id="store-profile-title">Retirada e atendimento</h2>
            <p class="store-hint">O último pedido precisa ficar pronto até o fim do turno. O prazo de preparo define o horário limite.</p>
            <div class="admin-editor-field">
                <label for="store-address">Endereço de retirada</label>
                <input id="store-address" name="pickup_address" maxlength="250"
                       value="<?= escape($storeSettings['pickup_address_input']) ?>"
                       placeholder="<?= escape($storeSettings['pickup_address_input'] === '' ? $storeSettings['pickup_address'] : '') ?>"
                       <?= $errorAttributes('pickup_address') ?>>
                <?php $renderStoreError('pickup_address'); ?>
            </div>
            <div class="admin-editor-field">
                <label for="store-prep">Tempo estimado de preparo em minutos</label>
                <input id="store-prep" name="prep_minutes" type="number" inputmode="numeric" min="5" max="240" required
                       value="<?= escape((string) $storeSettings['prep_minutes']) ?>" <?= $errorAttributes('prep_minutes') ?>>
                <?php $renderStoreError('prep_minutes'); ?>
            </div>
            <label class="admin-editor-check">
                <input type="checkbox" name="paused" value="1" <?= $storeSettings['paused'] ? 'checked' : '' ?>>
                <span>Pausar novos pedidos</span>
            </label>
        </section>

        <section class="admin-panel store-panel store-hours" aria-labelledby="store-hours-title">
            <h2 id="store-hours-title">Horários semanais</h2>
            <p class="store-hint">Horário de Brasília. Deixe os turnos vazios para fechar no dia. Use 00:00 a 00:00 para abrir 24h. Um fim anterior ao início encerra no dia seguinte.</p>
            <?php $renderStoreError('schedule'); ?>
            <?php foreach (storeWeekdays() as $day => $label): ?>
                <fieldset class="store-day">
                    <legend><?= escape($label) ?></legend>
                    <div class="store-shifts">
                        <?php for ($index = 0; $index < 2; $index++): ?>
                            <?php
                            [$start, $end] = $storeSettings['schedule'][$day][$index] ?? ['', ''];
                            $key = "schedule_{$day}";
                            $prefix = "schedule[{$day}][{$index}]";
                            $shift = $index + 1;
                            ?>
                            <div class="store-shift">
                                <label class="admin-editor-field">
                                    <span>Início <?= $shift ?>º turno</span>
                                    <input type="time" name="<?= $prefix ?>[start]" value="<?= $timeValue($start) ?>"
                                           <?= $errorAttributes($key) ?>>
                                </label>
                                <label class="admin-editor-field">
                                    <span>Fim <?= $shift ?>º turno</span>
                                    <input type="time" name="<?= $prefix ?>[end]" value="<?= $timeValue($end) ?>"
                                           <?= $errorAttributes($key) ?>>
                                </label>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <?php $renderStoreError('schedule_' . $day); ?>
                </fieldset>
            <?php endforeach; ?>
        </section>

        <section class="admin-panel store-panel store-exceptions" aria-labelledby="store-exceptions-title">
            <h2 id="store-exceptions-title">Datas especiais</h2>
            <p class="store-hint">A data especial substitui o horário semanal daquele dia. Deixe os dois horários vazios para fechar.</p>
            <?php $renderStoreError('exceptions'); ?>
            <?php foreach ($exceptionRows as $index => $row): ?>
                <?php $key = 'exception_' . $index; ?>
                <fieldset class="store-day">
                    <legend><?= $row['date'] === '' ? 'Nova data' : 'Data especial' ?></legend>
                    <div class="store-exception-fields">
                        <label class="admin-editor-field">
                            <span>Data</span>
                            <input type="date" name="exceptions[<?= $index ?>][date]" value="<?= escape($row['date']) ?>"
                                   <?= $errorAttributes($key) ?>>
                        </label>
                        <div class="store-shift">
                            <label class="admin-editor-field">
                                <span>Início</span>
                                <input type="time" name="exceptions[<?= $index ?>][start]" value="<?= $timeValue($row['start']) ?>"
                                       <?= $errorAttributes($key) ?>>
                            </label>
                            <label class="admin-editor-field">
                                <span>Fim</span>
                                <input type="time" name="exceptions[<?= $index ?>][end]" value="<?= $timeValue($row['end']) ?>"
                                       <?= $errorAttributes($key) ?>>
                            </label>
                        </div>
                    </div>
                    <?php $renderStoreError($key); ?>
                    <?php if ($row['date'] !== ''): ?>
                        <label class="admin-editor-check">
                            <input type="checkbox" name="exceptions[<?= $index ?>][remove]" value="1">
                            <span>Remover esta data</span>
                        </label>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </section>

        <div class="store-actions">
            <button type="submit" class="admin-button admin-button-primary">Salvar configurações</button>
        </div>
    </form>
<?php endif; ?>
