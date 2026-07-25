import { Notyf } from 'notyf';

const notifier = new Notyf({
    duration: 4500,
    position: {
        x: 'right',
        y: 'top',
    },
    dismissible: true,
    ripple: false,
    types: [
        {
            type: 'success',
            background: '#198754',
            icon: false,
        },
        {
            type: 'error',
            background: '#dc3545',
            icon: false,
        },
        {
            type: 'warning',
            background: '#f0ad4e',
            icon: false,
        },
        {
            type: 'info',
            background: '#0d6efd',
            icon: false,
        },
    ],
});

export const notify = {
    success(message) {
        notifier.success(message);
    },

    error(message) {
        notifier.error(message);
    },

    warning(message) {
        notifier.open({
            type: 'warning',
            message,
        });
    },

    info(message) {
        notifier.open({
            type: 'info',
            message,
        });
    },
};