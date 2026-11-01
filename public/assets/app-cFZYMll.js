async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (clipboardError) {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (fallbackError) {
            console.error('O navegador não permitiu copiar o texto pelo método alternativo.', fallbackError);
        } finally {
            field.remove();
        }

        if (!copied) {
            console.warn('O navegador não permitiu copiar o texto.', clipboardError);
        }

        return copied;
    }
}

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest('[data-copy-target]');
    if (!button) {
        return;
    }

    const target = document.getElementById(button.dataset.copyTarget);
    const feedback = document.getElementById(button.dataset.copyFeedback);
    if (!target || !feedback) {
        console.error('Não foi possível localizar o campo ou a mensagem de cópia.');
        return;
    }

    const text = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
        ? target.value
        : target.textContent.trim();
    const copied = await copyText(text);
    feedback.textContent = copied ? button.dataset.copySuccess : button.dataset.copyError;
    button.classList.toggle('copied', copied);
});
