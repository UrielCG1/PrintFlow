import { Controller } from '@hotwired/stimulus';

const quantityPattern = /^(?:0|[1-9]\d{0,13})(?:[.,]\d{1,6})?$/;

export default class extends Controller {
    static targets = [
        'summary',
        'total',
        'waste',
        'wasteReason',
        'measurementMethod',
        'adjustmentReason',
    ];

    static values = {
        plannedQuantity: String,
    };

    connect() {
        this.hasAttemptedSubmit = false;
        this.handleInvalid = this.handleInvalid.bind(this);
        this.handleInput = this.handleInput.bind(this);
        this.handleSubmit = this.handleSubmit.bind(this);

        this.element.addEventListener('invalid', this.handleInvalid, true);
        this.element.addEventListener('input', this.handleInput);
        this.element.addEventListener('change', this.handleInput);
        this.element.addEventListener('submit', this.handleSubmit);
        this.refreshConstraints();
    }

    disconnect() {
        this.element.removeEventListener('invalid', this.handleInvalid, true);
        this.element.removeEventListener('input', this.handleInput);
        this.element.removeEventListener('change', this.handleInput);
        this.element.removeEventListener('submit', this.handleSubmit);
    }

    handleInput() {
        this.refreshConstraints();
        if (this.hasAttemptedSubmit && this.element.checkValidity() && this.hasSummaryTarget) {
            this.summaryTarget.hidden = true;
        }
    }

    handleSubmit(event) {
        this.hasAttemptedSubmit = true;
        this.refreshConstraints();

        if (this.element.checkValidity()) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        const firstInvalid = this.element.querySelector(':invalid');
        firstInvalid?.reportValidity();
        this.showSummary(firstInvalid);
    }

    handleInvalid(event) {
        this.hasAttemptedSubmit = true;
        const field = event.target;
        this.openContainingDetails(field);
        this.showSummary(field);
    }

    refreshConstraints() {
        this.element.querySelectorAll('input, textarea, select').forEach((field) => {
            field.setCustomValidity('');
        });

        if (this.hasTotalTarget) {
            this.refreshConsumptionConstraints();
        }

        this.element.querySelectorAll('input[required], textarea[required], select[required]').forEach((field) => {
            if (field instanceof HTMLInputElement && ['checkbox', 'radio', 'number', 'hidden'].includes(field.type)) {
                return;
            }
            if (field instanceof HTMLSelectElement) {
                return;
            }

            const value = field.value.trim();
            if (value === '') {
                field.setCustomValidity('Completa este campo para continuar.');
                return;
            }

            const minimumLength = Number(field.getAttribute('minlength') || 0);
            if (minimumLength > 0 && value.length < minimumLength) {
                field.setCustomValidity(`Escribe al menos ${minimumLength} caracteres.`);
            }
        });

        this.element.querySelectorAll('input[minlength]:not([required]), textarea[minlength]:not([required])').forEach((field) => {
            const value = field.value.trim();
            const minimumLength = Number(field.getAttribute('minlength') || 0);
            if (value !== '' && value.length < minimumLength) {
                field.setCustomValidity(`Si completas este campo, escribe al menos ${minimumLength} caracteres.`);
            }
        });
    }

    refreshConsumptionConstraints() {
        const plannedValue = this.hasPlannedQuantityValue ? this.plannedQuantityValue.trim() : '';
        const hasPlannedQuantity = plannedValue !== '' && Number.isFinite(this.quantityNumber(plannedValue));
        const totalText = this.totalTarget.value.trim();
        const hasMeasuredTotal = totalText !== '';
        const measuredTotal = hasMeasuredTotal ? this.quantityNumber(totalText) : null;
        this.totalTarget.required = !hasPlannedQuantity;
        if (this.hasMeasurementMethodTarget) {
            this.measurementMethodTarget.required = !hasPlannedQuantity || hasMeasuredTotal;
        }

        if (this.hasAdjustmentReasonTarget) {
            const quantityChanged = !hasPlannedQuantity
                || (hasMeasuredTotal && Number.isFinite(measuredTotal) && this.compareQuantities(totalText, plannedValue) !== 0);
            this.adjustmentReasonTarget.required = quantityChanged;
        }

        if (this.hasWasteTarget) {
            const wasteText = this.wasteTarget.value.trim();
            const waste = wasteText === '' ? null : this.quantityNumber(wasteText);
            if (this.hasWasteReasonTarget) {
                this.wasteReasonTarget.required = waste !== null && Number.isFinite(waste) && waste > 0;
            }

            const totalForComparison = hasMeasuredTotal ? totalText : plannedValue;
            if (waste !== null && Number.isFinite(waste) && Number.isFinite(this.quantityNumber(totalForComparison)) && this.compareQuantities(wasteText, totalForComparison) > 0) {
                this.wasteTarget.setCustomValidity('La merma no puede ser mayor que el total real utilizado.');
            }
        }

        if (this.hasTotalTarget && hasMeasuredTotal && !quantityPattern.test(totalText)) {
            this.totalTarget.setCustomValidity('Usa una cantidad positiva con máximo seis decimales.');
        }
        if (this.hasWasteTarget && this.wasteTarget.value.trim() !== '' && !quantityPattern.test(this.wasteTarget.value.trim())) {
            this.wasteTarget.setCustomValidity('Usa una cantidad igual o mayor que cero con máximo seis decimales.');
        }
        if (this.hasWasteReasonTarget && this.wasteReasonTarget.required && this.wasteReasonTarget.value.trim() === '') {
            this.wasteReasonTarget.setCustomValidity('Explica el motivo de la merma para poder cerrar la impresión.');
        }
        if (this.hasAdjustmentReasonTarget && this.adjustmentReasonTarget.required && this.adjustmentReasonTarget.value.trim().length < 5) {
            this.adjustmentReasonTarget.setCustomValidity('Explica el cambio con al menos cinco caracteres.');
        }
    }

    quantityNumber(value) {
        if (!quantityPattern.test(String(value).trim())) {
            return Number.NaN;
        }

        return Number(String(value).trim().replace(',', '.'));
    }

    compareQuantities(left, right) {
        const normalize = (value) => {
            const [whole, fraction = ''] = String(value).trim().replace(',', '.').split('.');
            return [whole.replace(/^0+(?=\d)/, ''), fraction.padEnd(6, '0')];
        };
        const [leftWhole, leftFraction] = normalize(left);
        const [rightWhole, rightFraction] = normalize(right);

        if (leftWhole.length !== rightWhole.length) {
            return leftWhole.length > rightWhole.length ? 1 : -1;
        }
        if (leftWhole !== rightWhole) {
            return leftWhole > rightWhole ? 1 : -1;
        }
        if (leftFraction !== rightFraction) {
            return leftFraction > rightFraction ? 1 : -1;
        }

        return 0;
    }

    openContainingDetails(field) {
        let ancestor = field?.parentElement;
        while (ancestor && ancestor !== this.element) {
            if (ancestor instanceof HTMLDetailsElement) {
                ancestor.open = true;
            }
            ancestor = ancestor.parentElement;
        }
    }

    showSummary(field) {
        if (!this.hasSummaryTarget) {
            return;
        }

        const label = field?.labels?.[0]?.textContent?.trim().replace(/\s+/g, ' ');
        const message = field?.validationMessage || 'Completa los campos obligatorios antes de continuar.';
        this.summaryTarget.textContent = label ? `${label}: ${message}` : message;
        this.summaryTarget.hidden = false;
    }
}
