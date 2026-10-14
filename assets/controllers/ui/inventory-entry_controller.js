import { Controller } from '@hotwired/stimulus';

/** Mantiene visible la diferencia entre objeto físico, saldo y origen de la entrada. */
export default class extends Controller {
    static targets = ['origin', 'variant', 'physicalForm', 'unit', 'legacyWarning', 'legacyUnit', 'receiptOnly', 'submit', 'rollSetupLink'];

    connect() {
        this.updateOrigin();
        this.updateUnit();
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

    updateUnit() {
        const option = this.variantTarget.selectedOptions[0];
        const unit = option?.dataset.inventoryUnit || 'Selecciona material';
        this.unitTarget.textContent = unit;
        this.legacyUnitTarget.textContent = unit;
        const isLegacy = option?.dataset.legacyPresentation === '1';
        this.legacyWarningTarget.hidden = !isLegacy;
        this.submitTarget.disabled = isLegacy;
        if (this.hasRollSetupLinkTarget && option?.dataset.materialId) {
            const url = new URL(this.rollSetupLinkTarget.href);
            url.searchParams.set('material', option.dataset.materialId);
            this.rollSetupLinkTarget.href = url.toString();
        }
    }
}
