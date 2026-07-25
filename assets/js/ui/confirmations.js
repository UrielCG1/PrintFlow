import Swal from 'sweetalert2';

const baseOptions = {
    buttonsStyling: false,
    reverseButtons: true,
    focusCancel: true,
    customClass: {
        actions: 'pf-swal-actions',
        confirmButton: 'btn btn-primary',
        cancelButton: 'btn btn-outline-secondary',
    },
};

export async function confirmAction({
    title = '¿Deseas continuar?',
    text = '',
    confirmButtonText = 'Sí, continuar',
    cancelButtonText = 'Cancelar',
    icon = 'warning',
} = {}) {
    return Swal.fire({
        ...baseOptions,
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
    });
}

export async function confirmDeletion({
    title = '¿Eliminar este registro?',
    text = 'Esta acción no se puede deshacer.',
    confirmButtonText = 'Sí, eliminar',
    cancelButtonText = 'Cancelar',
} = {}) {
    return confirmAction({
        title,
        text,
        confirmButtonText,
        cancelButtonText,
        icon: 'warning',
    });
}

export function showError({
    title = 'Ocurrió un error',
    text = 'No fue posible completar la operación. Intenta nuevamente.',
} = {}) {
    return Swal.fire({
        ...baseOptions,
        title,
        text,
        icon: 'error',
        confirmButtonText: 'Entendido',
        showCancelButton: false,
    });
}