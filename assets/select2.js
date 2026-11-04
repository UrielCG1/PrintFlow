import $ from 'jquery';

let select2Ready;

export default async function loadSelect2() {
    // Select2 is bundled as a UMD factory, so importing it alone does not attach
    // the plugin to jQuery. Expose jQuery globally and invoke that factory once.
    window.jQuery = $;
    select2Ready ??= import('select2/dist/js/select2.min.js');
    const select2Module = await select2Ready;

    if (typeof $.fn.select2 !== 'function') {
        if (typeof select2Module.default !== 'function') {
            throw new TypeError('La distribución de Select2 no expone su inicializador.');
        }

        select2Module.default(window, $);
    }

    return $;
}
