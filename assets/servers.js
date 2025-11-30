document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.dc-servers-wrap').forEach((wrap) => {
        const form = wrap.querySelector('.dc-form-modern');
        const toggleBtn = wrap.querySelector('.dc-toggle-form');
        const formTitle = wrap.querySelector('.dc-form-title');
        const idField = wrap.querySelector('input[name="id"]');
        const selectAll = wrap.querySelector('.dc-select-all');
        const customerIdField = wrap.querySelector('input[name="customer_id"]');
        const customerNameInput = wrap.querySelector('input[name="customer_name_search"]');
        const customerNumberInput = wrap.querySelector('input[name="customer_number_search"]');
        const ipPool = wrap.querySelector('.dc-ip-pool');
        const ipInternalInput = wrap.querySelector('input[name="ip_internal"]');
        const ipWanInput = wrap.querySelector('input[name="ip_wan"]');
        const hostSelect = wrap.querySelector('select[name="location"]');
        const fillInternalBtn = wrap.querySelector('.dc-fill-next-internal');
        const fillWanBtn = wrap.querySelector('.dc-fill-next-wan');
        const customerOptions = Array.from(wrap.querySelectorAll('.dc-customer-option')).map((opt) => ({
            id: opt.dataset.id,
            name: opt.dataset.name,
            number: opt.dataset.number,
        }));
        const availableInternal = ipPool?.dataset?.availableInternal ? JSON.parse(ipPool.dataset.availableInternal) : [];
        const availableWan = ipPool?.dataset?.availableWan ? JSON.parse(ipPool.dataset.availableWan) : [];
        const nextInternalMap = ipPool?.dataset?.nextInternalMap ? JSON.parse(ipPool.dataset.nextInternalMap) : {};
        const nextInternal = ipPool?.dataset?.nextInternal || '';
        const nextWan = ipPool?.dataset?.nextWan || '';
        const ensureOptionExists = (select, value) => {
            if (!select || !value) return;
            const found = Array.from(select.options).some((opt) => opt.value === value);
            if (!found) {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = value;
                select.appendChild(opt);
            }
        };

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
            if (customerIdField) {
                customerIdField.value = '';
            }
            if (hostSelect) {
                hostSelect.value = '';
            }
            if (ipInternalInput && nextInternal) {
                ipInternalInput.value = nextInternal;
            }
            if (ipWanInput && nextWan) {
                ipWanInput.value = nextWan;
            }
            setFormState('expanded');
            applyHostFilter();
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
                form.querySelector('input[name="server_name"]').value = btn.dataset.server_name || '';
                form.querySelector('input[name="ip_internal"]').value = btn.dataset.ip_internal || '';
                form.querySelector('input[name="ip_wan"]').value = btn.dataset.ip_wan || '';
                const locationField = form.querySelector('select[name="location"]');
                const farmField = form.querySelector('select[name="farm"]');
                if (locationField) {
                    ensureOptionExists(locationField, btn.dataset.location || '');
                    locationField.value = btn.dataset.location || '';
                }
                if (farmField) {
                    ensureOptionExists(farmField, btn.dataset.farm || '');
                    farmField.value = btn.dataset.farm || '';
                }
                if (hostSelect) {
                    ensureOptionExists(hostSelect, btn.dataset.location || '');
                    hostSelect.value = btn.dataset.location || '';
                    applyHostFilter();
                }
                if (customerNameInput) {
                    customerNameInput.value = btn.dataset.customer_name || '';
                }
                if (customerNumberInput) {
                    customerNumberInput.value = btn.dataset.customer_number || '';
                }
                if (idField) {
                    idField.value = btn.dataset.id || '';
                }
                if (customerIdField) {
                    customerIdField.value = btn.dataset.customer_id || '';
                }
                if (formTitle) {
                    formTitle.textContent = 'עריכת שרת';
                }
                setFormState('expanded');
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        const pickNextAvailable = (type) => {
            const hostValue = hostSelect ? hostSelect.value : '';
            const normalizedHost = hostValue ? hostValue.toLowerCase() : '';
            if (type === 'internal') {
                const hostSpecific = normalizedHost && nextInternalMap[hostValue] ? nextInternalMap[hostValue] : '';
                const fallbackInternal = availableInternal.find((row) => {
                    const rowHost = (row.host || '').toLowerCase();
                    return normalizedHost ? rowHost === normalizedHost : true;
                });
                if (ipInternalInput) {
                    ipInternalInput.value = hostSpecific || nextInternal || fallbackInternal?.address || ipInternalInput.value;
                }
            } else if (type === 'wan') {
                if (ipWanInput && nextWan) {
                    ipWanInput.value = nextWan;
                } else if (ipWanInput && availableWan.length) {
                    ipWanInput.value = availableWan[0];
                }
            }
        };

        const applyHostFilter = () => {
            const hostValue = hostSelect ? hostSelect.value : '';
            const normalizedHost = hostValue ? hostValue.toLowerCase() : '';
            wrap.querySelectorAll('#dc-ip-internal-list option').forEach((opt) => {
                const optHost = (opt.dataset.host || '').toLowerCase();
                const match = !normalizedHost || !optHost || optHost === normalizedHost;
                opt.disabled = !match;
                opt.hidden = !match && normalizedHost;
            });

            if (ipInternalInput && !ipInternalInput.value) {
                pickNextAvailable('internal');
            }
        };

        if (fillInternalBtn) {
            fillInternalBtn.addEventListener('click', () => pickNextAvailable('internal'));
        }

        if (fillWanBtn) {
            fillWanBtn.addEventListener('click', () => pickNextAvailable('wan'));
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                const checkboxes = wrap.querySelectorAll('input[name="ids[]"]');
                checkboxes.forEach((cb) => {
                    cb.checked = selectAll.checked;
                });
            });
        }

        const setCustomerByMatch = (type, value) => {
            if (!customerIdField) return;
            const matcher = (candidate) => {
                if (type === 'name') {
                    return candidate.name && candidate.name.toLowerCase() === value.toLowerCase();
                }
                return candidate.number && candidate.number.toLowerCase() === value.toLowerCase();
            };
            const match = customerOptions.find(matcher);
            if (match) {
                customerIdField.value = match.id;
                if (customerNameInput) customerNameInput.value = match.name;
                if (customerNumberInput) customerNumberInput.value = match.number;
            } else {
                customerIdField.value = '';
            }
        };

        if (customerNameInput) {
            customerNameInput.addEventListener('input', () => {
                setCustomerByMatch('name', customerNameInput.value.trim());
            });
        }
        if (customerNumberInput) {
            customerNumberInput.addEventListener('input', () => {
                setCustomerByMatch('number', customerNumberInput.value.trim());
            });
        }

        if (hostSelect) {
            hostSelect.addEventListener('change', applyHostFilter);
            applyHostFilter();
        }

        if (form) {
            form.addEventListener('submit', (e) => {
                if (customerIdField && !customerIdField.value) {
                    e.preventDefault();
                    alert('יש לבחור לקוח מרשימת החיפוש.');
                }
            });
        }
    });
});
