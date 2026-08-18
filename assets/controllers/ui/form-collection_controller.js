import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ['items', 'template'];
    static values = { index: Number, placeholder: String };
    add() { const key=this.hasPlaceholderValue?this.placeholderValue:'__name__'; const html=this.templateTarget.innerHTML.replaceAll(key,String(this.indexValue++)); this.itemsTarget.insertAdjacentHTML('beforeend',`<div class="pf-card p-3 mb-3" data-collection-item>${html}<button type="button" class="btn btn-outline-danger btn-sm mt-3" data-action="ui--form-collection#remove">Eliminar</button></div>`); }
    remove(event) { event.currentTarget.closest('[data-collection-item]')?.remove(); }
}
