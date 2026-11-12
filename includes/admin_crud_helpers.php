<?php
declare(strict_types=1);

/**
 * @param array<string, mixed> $values
 * @param array<int, array<string, mixed>> $fields
 */
function render_admin_crud_form(
    string $action,
    string $csrfToken,
    array $values,
    array $fields,
    string $cancelPath,
    ?int $recordId = null,
    string $idField = 'id'
): void {
    ?>
    <form class="user-form" method="post" action="<?= e(app_url($action)) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <?php if ($recordId !== null): ?>
            <input type="hidden" name="<?= e($idField) ?>" value="<?= $recordId ?>">
        <?php endif; ?>
        <?php foreach ($fields as $field): ?>
            <?php
            $name = (string) $field['name'];
            $label = (string) $field['label'];
            $type = (string) $field['type'];
            $fieldId = (string) ($field['id'] ?? str_replace('_', '-', $name));
            $required = !empty($field['required']);
            $value = $values[$name] ?? '';
            ?>
            <div class="form-field">
                <?php if ($type === 'checkbox'): ?>
                    <label class="checkbox-label" for="<?= e($fieldId) ?>">
                        <input id="<?= e($fieldId) ?>" name="<?= e($name) ?>" type="checkbox" value="1"<?= (string) $value === '1' ? ' checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php else: ?>
                    <label for="<?= e($fieldId) ?>"><?= e($label) ?></label>
                    <?php if ($type === 'select'): ?>
                        <select id="<?= e($fieldId) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?>>
                            <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                <option value="<?= e($optionValue) ?>"<?= (string) $value === (string) $optionValue ? ' selected' : '' ?>>
                                    <?= e($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input
                            id="<?= e($fieldId) ?>"
                            name="<?= e($name) ?>"
                            type="<?= e($type) ?>"
                            value="<?= $type === 'password' ? '' : e($value) ?>"
                            autocomplete="<?= e((string) ($field['autocomplete'] ?? 'off')) ?>"
                            <?= isset($field['maxlength']) ? 'maxlength="' . (int) $field['maxlength'] . '"' : '' ?>
                            <?= isset($field['min']) ? 'min="' . e($field['min']) . '"' : '' ?>
                            <?= isset($field['max']) ? 'max="' . e($field['max']) . '"' : '' ?>
                            <?= isset($field['step']) ? 'step="' . e($field['step']) . '"' : '' ?>
                            <?= isset($field['placeholder']) ? 'placeholder="' . e($field['placeholder']) . '"' : '' ?>
                            <?= $required ? 'required' : '' ?>
                        >
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (isset($field['help'])): ?>
                    <small class="field-help"><?= e($field['help']) ?></small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="form-actions">
            <button class="button" type="submit">Simpan</button>
            <a class="button button-secondary" href="<?= e(app_url($cancelPath)) ?>">Batal</a>
        </div>
    </form>
    <?php
}
