import { Controller } from '@hotwired/stimulus';

/**
 * Mantiene coherente la captura de una unidad sin sustituir las validaciones
 * del servidor: filtra bases por dimensión y simplifica factor/precisión.
 */
export default class extends Controller {
    static targets = [
        'dimension',
        'baseUnit',
        'factor',
        'allowsFraction',
        'decimalScale',
    ];

    connect() {
        this.syncDimension();
        this.syncPrecision();
    }

    syncDimension() {
        if (!this.hasDimensionTarget || !this.hasBaseUnitTarget) {
            return;
        }

        const dimension = this.dimensionTarget.value;
        let selectedStillValid = true;

        for (const option of this.baseUnitTarget.options) {
            if (option.value === '') {
                option.disabled = false;
                option.hidden = false;
                continue;
            }

            const allowed = dimension !== ''
                && dimension !== 'COUNT'
                && option.dataset.dimension === dimension;
            option.disabled = !allowed;
            option.hidden = !allowed;

            if (option.selected && !allowed) {
                selectedStillValid = false;
            }
        }

        if (!selectedStillValid) {
            this.baseUnitTarget.value = '';
        }

        this.syncConversion();
    }

    syncConversion() {
        if (!this.hasBaseUnitTarget || !this.hasFactorTarget) {
            return;
        }

        const usesBaseUnit = this.baseUnitTarget.value !== '';
        this.factorTarget.readOnly = !usesBaseUnit;

        if (!usesBaseUnit) {
            this.factorTarget.value = '1';
        }
    }

    syncPrecision() {
        if (!this.hasAllowsFractionTarget || !this.hasDecimalScaleTarget) {
            return;
        }

        const allowsFraction = this.allowsFractionTarget.checked;
        this.decimalScaleTarget.readOnly = !allowsFraction;

        if (!allowsFraction) {
            this.decimalScaleTarget.value = '0';
        } else if (Number(this.decimalScaleTarget.value) === 0) {
            this.decimalScaleTarget.value = '4';
        }
    }
}
