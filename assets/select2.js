import $ from 'jquery';

let select2Ready;

export default async function loadSelect2() {
    // Select2's distribution build is UMD and expects jQuery on the browser global.
    window.jQuery = $;
    select2Ready ??= import('select2/dist/js/select2.min.js');
    await select2Ready;

    return $;
}
