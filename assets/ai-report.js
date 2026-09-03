(() => {
    'use strict';

    const deleteDialog = document.getElementById('ai-delete-dialog');
    const deleteForm = document.getElementById('ai-delete-form');
    const deleteCancel = document.getElementById('ai-delete-cancel');
    const deleteLabel = document.getElementById('ai-delete-report');
    const deleteDetails = document.querySelectorAll('.ai-archive-delete');

    // Keep the confirmation outside the scrolling table, without changing the POST handler.
    if (deleteDialog && typeof deleteDialog.showModal === 'function' && deleteForm && deleteCancel && deleteLabel) {
        let deleteTrigger = null;
        const deleteSubmit = deleteForm.querySelector('button[type="submit"]');

        deleteDetails.forEach((details) => {
            const summary = details.querySelector('summary');
            const sourceForm = details.querySelector('form');
            summary.addEventListener('click', (event) => {
                event.preventDefault();
                if (deleteDialog.open || deleteForm.dataset.submitting === '1') {
                    return;
                }
                deleteDetails.forEach((item) => { item.open = false; });
                deleteTrigger = summary;
                ['csrf_token', 'action', 'report_id'].forEach((name) => {
                    deleteForm.elements.namedItem(name).value = sourceForm.elements.namedItem(name).value;
                });
                deleteLabel.textContent = details.dataset.reportLabel;
                deleteDialog.showModal();
                deleteCancel.focus();
            });
        });

        deleteCancel.addEventListener('click', () => deleteDialog.close());
        deleteDialog.addEventListener('click', (event) => {
            const bounds = deleteDialog.getBoundingClientRect();
            if (event.target === deleteDialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) {
                deleteDialog.close();
            }
        });
        deleteDialog.addEventListener('close', () => {
            deleteForm.reset();
            deleteForm.dataset.submitting = '0';
            deleteSubmit.disabled = false;
            deleteTrigger?.focus();
        });
        deleteForm.addEventListener('submit', (event) => {
            if (deleteForm.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }
            deleteForm.dataset.submitting = '1';
            deleteSubmit.disabled = true;
        });
    }

    const overlay = document.getElementById('ai-generation-overlay');
    const dialog = overlay?.querySelector('.ai-generation-dialog');
    const indicator = document.getElementById('ai-generation-indicator');
    const title = document.getElementById('ai-generation-title');
    const message = document.getElementById('ai-generation-message');
    const progress = document.getElementById('ai-generation-progress');
    const progressBar = document.getElementById('ai-generation-progress-bar');
    const phase = document.getElementById('ai-generation-phase');
    const percent = document.getElementById('ai-generation-percent');
    const closeButton = document.getElementById('ai-generation-close');
    const forms = document.querySelectorAll(
        '#general-report-form, #custom-report-form, #changes-report-form, #weekly-changes-report-form, #earnings-report-form, #account-general-report-form, #account-changes-report-form, #account-weekly-changes-report-form'
    );

    if (!overlay || !dialog || !indicator || !title || !message || !progress || !progressBar || !phase || !percent || !closeButton || forms.length === 0 || !window.fetch) {
        return;
    }

    let animationFrame = 0;

    const setProgress = (value) => {
        const rounded = Math.max(0, Math.min(100, Math.round(value)));
        progressBar.style.width = `${rounded}%`;
        progress.setAttribute('aria-valuenow', String(rounded));
        percent.textContent = `${rounded}%`;

        if (rounded < 28) {
            phase.textContent = 'Preparazione dati';
        } else if (rounded < 66) {
            phase.textContent = 'Analisi Gemini';
        } else if (rounded < 88) {
            phase.textContent = 'Verifica del report';
        } else if (rounded < 100) {
            phase.textContent = 'Finalizzazione';
        }
    };

    const animateProgress = (startedAt) => {
        const elapsed = Date.now() - startedAt;
        const estimated = 8 + (86 * (1 - Math.exp(-elapsed / 16000)));
        setProgress(Math.min(94, estimated));
        animationFrame = window.requestAnimationFrame(() => animateProgress(startedAt));
    };

    const openOverlay = (action) => {
        dialog.classList.remove('is-success', 'is-error');
        indicator.classList.remove('is-success', 'is-error');
        if (action === 'generate_earnings') {
            title.textContent = 'Analisi della trimestrale';
        } else if (action === 'generate_custom') {
            title.textContent = 'Generazione del report personalizzato';
        } else if (action === 'generate_account_general') {
            title.textContent = 'Generazione del report patrimonio';
        } else if (action === 'generate_account_weekly_changes') {
            title.textContent = 'Analisi patrimoniale della settimana';
        } else if (action === 'generate_account_changes') {
            title.textContent = 'Analisi delle ultime variazioni patrimoniali';
        } else if (action === 'generate_weekly_changes') {
            title.textContent = 'Analisi della settimana';
        } else if (action === 'generate_changes') {
            title.textContent = 'Analisi delle ultime variazioni';
        } else {
            title.textContent = 'Generazione del report in corso';
        }
        if (action === 'generate_earnings') {
            message.textContent = 'Gemini sta recuperando la fonte ufficiale, analizzando i risultati e collegandoli agli snapshot.';
        } else if (action === 'generate_account_general') {
            message.textContent = 'Gemini sta analizzando gli snapshot patrimoniali e verificando la separazione tra patrimonio e credito.';
        } else if (action === 'generate_account_weekly_changes') {
            message.textContent = 'Gemini sta confrontando i due snapshot patrimoniali del sabato e verificando i delta della settimana.';
        } else if (action === 'generate_account_changes') {
            message.textContent = 'Gemini sta confrontando gli ultimi due snapshot patrimoniali e verificando i delta.';
        } else if (action === 'generate_weekly_changes') {
            message.textContent = 'Gemini sta confrontando i due snapshot del sabato e verificando le variazioni della settimana.';
        } else if (action === 'generate_changes') {
            message.textContent = 'Gemini sta confrontando gli ultimi due snapshot e verificando le variazioni.';
        } else {
            message.textContent = 'Gemini sta analizzando gli snapshot. L\'operazione puo richiedere diversi secondi.';
        }
        closeButton.hidden = true;
        setProgress(8);
        overlay.hidden = false;
        document.body.classList.add('ai-generation-active');
        animationFrame = window.requestAnimationFrame(() => animateProgress(Date.now()));
    };

    const stopAnimation = () => {
        if (animationFrame) {
            window.cancelAnimationFrame(animationFrame);
            animationFrame = 0;
        }
    };

    const showSuccess = () => {
        stopAnimation();
        setProgress(100);
        dialog.classList.add('is-success');
        indicator.classList.add('is-success');
        title.textContent = 'Report pronto';
        message.textContent = 'Il report ha superato i controlli ed e stato archiviato. Apertura in corso.';
        phase.textContent = 'Completato';
    };

    const showError = (error) => {
        stopAnimation();
        dialog.classList.add('is-error');
        indicator.classList.add('is-error');
        title.textContent = 'Generazione non completata';
        message.textContent = error || 'Si e verificato un errore durante la generazione del report.';
        phase.textContent = 'Operazione interrotta';
        closeButton.hidden = false;
        closeButton.focus();
    };

    closeButton.addEventListener('click', () => {
        overlay.hidden = true;
        document.body.classList.remove('ai-generation-active');
    });

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            form.dataset.submitting = '1';
            const formData = new FormData(form);
            const submitter = event.submitter;
            if (submitter?.name && !formData.has(submitter.name)) {
                formData.append(submitter.name, submitter.value);
            }

            openOverlay(formData.get('action'));

            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload?.ok || !payload.redirect) {
                    throw new Error(payload?.error || 'Il server non ha restituito un report valido.');
                }

                showSuccess();
                window.setTimeout(() => {
                    window.location.assign(payload.redirect);
                }, 1100);
            } catch (error) {
                form.dataset.submitting = '0';
                showError(error instanceof Error ? error.message : 'Errore imprevisto durante la generazione.');
            }
        });
    });
})();
