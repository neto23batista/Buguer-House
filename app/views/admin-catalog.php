<?php if (!$databaseAvailable): ?>
    <p class="admin-panel admin-empty" role="alert">O cardápio está indisponível. Tente atualizar em instantes.</p>
<?php else: ?>
    <?php $catalogCategories = productCategories(); ?>
    <div class="admin-layout<?= $catalogValues === null ? ' admin-catalog-wide' : '' ?>">
        <section class="admin-panel admin-catalog" aria-labelledby="catalog-list-title">
            <div class="admin-catalog-heading">
                <h2 id="catalog-list-title">Produtos</h2>
            </div>

            <?php if (!$catalogProducts): ?>
                <p class="admin-empty">Nenhum produto cadastrado.</p>
            <?php else: ?>
                <ul class="admin-catalog-list">
                    <?php foreach ($catalogProducts as $row): ?>
                        <?php $selected = $catalogId === (int) $row['id']; ?>
                        <li>
                            <a class="admin-catalog-row" href="<?= escape(adminCatalogUrl((int) $row['id'])) ?>#produto-editor"<?= $selected ? ' aria-current="true"' : '' ?>>
                                <?php if ($row['image'] !== ''): ?>
                                    <img class="admin-catalog-image" src="../<?= escape(productImage($row)) ?>" alt="" width="48" height="48" loading="lazy">
                                <?php else: ?>
                                    <span class="admin-catalog-image admin-catalog-placeholder" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="admin-catalog-identity">
                                    <strong><?= escape($row['name']) ?></strong>
                                    <small><?= escape($catalogCategories[$row['category']] ?? $row['category']) ?></small>
                                </span>
                                <strong class="admin-catalog-price"><?= price((int) $row['price_cents']) ?></strong>
                                <span class="admin-status admin-status-<?= $row['is_active'] ? 'ready' : 'neutral' ?>"><?= $row['is_active'] ? 'Disponível' : 'Indisponível' ?></span>
                                <span class="admin-catalog-edit" aria-hidden="true">Editar <span>↗</span></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($catalogValues !== null): ?>
            <?php
                $fieldValue = static fn (string $key): string => escape((string) ($catalogValues[$key] ?? ''));
                $fieldError = static fn (string $key): string => isset($catalogErrors[$key])
                    ? ' aria-invalid="true" aria-describedby="product-' . $key . '-error"'
                    : '';
                $fieldMessage = static function (string $key) use ($catalogErrors): void {
                    if (isset($catalogErrors[$key])) {
                        echo '<p class="admin-field-error" id="product-' . $key . '-error">' . escape($catalogErrors[$key]) . '</p>';
                    }
                };
            ?>
            <section id="produto-editor" class="admin-panel admin-detail admin-editor" aria-labelledby="catalog-editor-title" tabindex="-1">
                <div class="admin-detail-heading">
                    <h2 id="catalog-editor-title"><?= $catalogId === null ? 'Novo produto' : 'Editar produto' ?></h2>
                </div>
                <?php if ($flash !== null): ?>
                    <p class="admin-alert<?= $flash['error'] ? ' admin-alert-error' : '' ?>" role="<?= $flash['error'] ? 'alert' : 'status' ?>"><?= escape($flash['text']) ?></p>
                <?php elseif ($catalogErrors): ?>
                    <p class="admin-alert admin-alert-error" role="alert">Revise os campos destacados para salvar o produto.</p>
                <?php endif; ?>
                <form class="admin-editor-form" action="<?= escape(adminCatalogUrl($catalogId, $catalogId === null)) ?>#produto-editor" method="post">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="csrf" value="<?= escape($adminCsrf) ?>">
                    <input type="hidden" name="product_id" value="<?= $catalogId ?? '' ?>">

                    <div class="admin-editor-field admin-editor-wide">
                        <label for="product-name">Nome</label>
                        <input id="product-name" name="name" value="<?= $fieldValue('name') ?>" maxlength="100" required<?= $fieldError('name') ?>>
                        <?php $fieldMessage('name'); ?>
                    </div>
                    <div class="admin-editor-field admin-editor-wide">
                        <label for="product-description">Descrição</label>
                        <textarea id="product-description" name="description" rows="3" maxlength="255" required<?= $fieldError('description') ?>><?= $fieldValue('description') ?></textarea>
                        <?php $fieldMessage('description'); ?>
                    </div>
                    <div class="admin-editor-field">
                        <label for="product-category">Categoria</label>
                        <select id="product-category" name="category" required<?= $fieldError('category') ?>>
                            <?php foreach ($catalogCategories as $value => $label): ?>
                                <option value="<?= escape($value) ?>"<?= $value === ($catalogValues['category'] ?? null) ? ' selected' : '' ?>><?= escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $fieldMessage('category'); ?>
                    </div>
                    <div class="admin-editor-field">
                        <label for="product-price">Preço em reais</label>
                        <input id="product-price" name="price" inputmode="decimal" value="<?= $fieldValue('price') ?>" placeholder="29,90" maxlength="13" required<?= $fieldError('price') ?>>
                        <?php $fieldMessage('price'); ?>
                    </div>
                    <div class="admin-editor-field admin-editor-wide">
                        <label for="product-image">Imagem</label>
                        <select id="product-image" name="image"<?= $fieldError('image') ?>>
                            <option value="">Sem imagem</option>
                            <?php foreach ($catalogImages as $filename): ?>
                                <option value="<?= escape($filename) ?>"<?= $filename === ($catalogValues['image'] ?? null) ? ' selected' : '' ?>><?= escape($filename) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $fieldMessage('image'); ?>
                    </div>
                    <div class="admin-editor-field admin-editor-wide">
                        <label for="product-image_alt">Texto alternativo da imagem</label>
                        <input id="product-image_alt" name="image_alt" value="<?= $fieldValue('image_alt') ?>" maxlength="180"<?= $fieldError('image_alt') ?>>
                        <?php $fieldMessage('image_alt'); ?>
                    </div>
                    <div class="admin-editor-field">
                        <label for="product-sort_order">Ordem no cardápio</label>
                        <input id="product-sort_order" name="sort_order" type="number" inputmode="numeric" min="0" max="65535" value="<?= $fieldValue('sort_order') ?>" required<?= $fieldError('sort_order') ?>>
                        <?php $fieldMessage('sort_order'); ?>
                    </div>
                    <div class="admin-editor-availability">
                        <label class="admin-editor-check">
                            <input type="checkbox" name="is_active" value="1"<?= !empty($catalogValues['is_active']) ? ' checked' : '' ?><?= $fieldError('is_active') ?>>
                            <span>Disponível no cardápio</span>
                        </label>
                        <?php $fieldMessage('is_active'); ?>
                    </div>
                    <div class="admin-editor-actions admin-editor-wide">
                        <a class="admin-button admin-button-secondary" href="<?= escape(adminCatalogUrl()) ?>">Voltar</a>
                        <button class="admin-button admin-button-primary" type="submit">Salvar produto</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>
