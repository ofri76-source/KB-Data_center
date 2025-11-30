document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.dc-servers-wrap').forEach((wrap) => {
        const form = wrap.querySelector('.dc-form-modern');
        const toggleBtn = wrap.querySelector('.dc-toggle-form');
        const formTitle = wrap.querySelector('.dc-form-title');
        const idField = wrap.querySelector('input[name="id"]');
        const selectAll = wrap.querySelector('.dc-select-all');

        const setFormState = (state) => {
            if (!form) return;
            if (state === 'collapsed') {
                form.classList.add('dc-form-collapsed');
            } else {
                form.classList.remove('dc-form-collapsed');
            }
        };

        const resetForm = () => {
            if (!form) return;
            form.reset();
            if (idField) {
                idField.value = '';
            }
            if (formTitle) {
                formTitle.textContent = 'הוספת שרת חדש';
            }
            setFormState('expanded');
        };

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                const isCollapsed = form && form.classList.contains('dc-form-collapsed');
                if (!isCollapsed) {
                    setFormState('collapsed');
                } else {
                    resetForm();
                }
            });
        }

        wrap.querySelectorAll('.dc-edit-server').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!form) return;
                const customerSelect = form.querySelector('select[name="customer_id"]');
                form.querySelector('input[name="server_name"]').value = btn.dataset.server_name || '';
                form.querySelector('input[name="ip_internal"]').value = btn.dataset.ip_internal || '';
                form.querySelector('input[name="ip_wan"]').value = btn.dataset.ip_wan || '';
                form.querySelector('input[name="location"]').value = btn.dataset.location || '';
                form.querySelector('input[name="farm"]').value = btn.dataset.farm || '';
                if (customerSelect) {
                    customerSelect.value = btn.dataset.customer_id || '';
                }
                if (idField) {
                    idField.value = btn.dataset.id || '';
                }
                if (formTitle) {
                    formTitle.textContent = 'עריכת שרת';
                }
                setFormState('expanded');
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                const checkboxes = wrap.querySelectorAll('input[name="ids[]"]');
                checkboxes.forEach((cb) => {
                    cb.checked = selectAll.checked;
                });
            });
        }
    });
});
