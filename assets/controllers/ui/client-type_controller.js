import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type', 'email', 'branches', 'individualNotice'];
    connect() { this.change(); }
    change() {
        const individual = this.typeTarget.value === 'INDIVIDUAL';
        this.branchesTarget.hidden = individual;
        this.individualNoticeTarget.hidden = !individual;
        this.emailTarget.required = individual;
        this.branchesTarget.querySelectorAll('input, select, textarea, button').forEach((field) => { field.disabled = individual; });
    }
}
