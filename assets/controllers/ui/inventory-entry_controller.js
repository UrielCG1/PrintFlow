import { Controller } from '@hotwired/stimulus';

/** Selección de catálogo y contenido de una unidad física. */
export default class extends Controller {
    static targets = ['origin', 'material', 'variant', 'physicalForm', 'physicalFormField', 'quantityLabel', 'quantityHelp', 'unit', 'legacyWarning', 'legacyUnit', 'receiptOnly', 'submit', 'rollSetupLink', 'addWidthLink'];

    connect() {
        this.updateOrigin();
        this.updateMaterial();
    }

    updateOrigin() {
        const selected = this.originTargets.find((input) => input.checked)
            || this.element.querySelector('input[name="origin"][type="hidden"]');
        const isOpening = selected?.value === 'INITIAL_COUNT';

        for (const field of this.receiptOnlyTargets) {
            field.hidden = isOpening;
        }
    }

    updateVariant() {
        this.physicalFormTarget.value = this.variantTarget.selectedOptions[0]?.dataset.defaultPhysicalForm || '';
        this.updateUnit();
    }

    updateMaterial() {
        const materialId = this.materialTarget.value;
        const previousVariantId = this.variantTarget.value;
        const available = [];
        for (const option of this.variantTarget.options) {
            if (!option.value) {
                continue;
            }
            const matches = Boolean(materialId) && option.dataset.materialId === materialId;
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches) {
                available.push(option);
            }
        }
        const selected = this.variantTarget.selectedOptions[0];
        if (!selected?.value || selected.dataset.materialId !== materialId) {
            this.variantTarget.value = available.length === 1 ? available[0].value : '';
        }
        if (this.variantTarget.value !== previousVariantId) {
            this.updateVariant();
        } else {
            this.updateUnit();
        }
        this.updateMaterialLinks(materialId);
    }

    updateMaterialLinks(materialId) {
        for (const link of [...this.rollSetupLinkTargets, ...this.addWidthLinkTargets]) {
            const url = new URL(link.href);
            if (materialId) {
                url.searchParams.set('material', materialId);
            } else {
                url.searchParams.delete('material');
            }
            link.href = url.toString();
        }
    }

    updateUnit() {
        const option = this.variantTarget.selectedOptions[0];
        const unit = option?.dataset.inventoryUnit || 'Selecciona material';
        this.unitTarget.textContent = unit;
        this.legacyUnitTarget.textContent = unit;
        const isLegacy = option?.dataset.legacyPresentation === '1';
        this.legacyWarningTarget.hidden = !isLegacy;
        this.submitTarget.disabled = isLegacy;
        const isRoll = option?.dataset.defaultPhysicalForm === 'ROLLO';
        this.physicalFormFieldTarget.hidden = Boolean(option?.dataset.defaultPhysicalForm);
        this.quantityLabelTarget.textContent = isRoll ? 'Longitud de este rollo' : 'Contenido de esta unidad física';
        this.quantityHelpTarget.textContent = isRoll
            ? 'Captura los metros lineales medidos de este rollo. Ej. 20 m; se generará un QR para este rollo.'
            : 'Captura cuánto contiene una sola unidad física en la unidad indicada.';
    }
}
