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

    const inventoryRows = Array.from(document.querySelectorAll('.dc-inventory-row'));
    if (inventoryRows.length) {
        inventoryRows.forEach((row) => {
            row.addEventListener('click', () => {
                const details = row.nextElementSibling;
                if (!details || !details.classList.contains('dc-inventory-details')) return;

                const currentlyOpen = row.classList.contains('open');
                document.querySelectorAll('.dc-inventory-row.open').forEach((r) => r.classList.remove('open'));
                document.querySelectorAll('.dc-inventory-details.open').forEach((d) => d.classList.remove('open'));

                if (!currentlyOpen) {
                    row.classList.add('open');
                    details.classList.add('open');
                }
            });
        });
    }
});

// פתיחה/סגירה של שורות מורחבות
document.querySelectorAll('.dc-server-row').forEach((row) => {
    row.addEventListener('click', (e) => {
        // מנע פתיחה אם לחצו על כפתור בתוך השורה
        if (e.target.closest('button') || e.target.closest('form')) {
            return;
        }
        
        const serverId = row.dataset.serverId;
        const detailsRow = document.querySelector(`.dc-server-details[data-server-id="${serverId}"]`);
        
        if (!detailsRow) return;
        
        // סגור את כל השורות האחרות
        document.querySelectorAll('.dc-server-row.active').forEach((r) => {
            if (r !== row) {
                r.classList.remove('active');
            }
        });
        document.querySelectorAll('.dc-server-details.open').forEach((d) => {
            if (d !== detailsRow) {
                d.classList.remove('open');
            }
        });
        
        // Toggle השורה הנוכחית
        row.classList.toggle('active');
        detailsRow.classList.toggle('open');
    });
});

// כפתור שכפול
document.querySelectorAll('.dc-duplicate-server').forEach((btn) => {
    btn.addEventListener('click', () => {
        const sourceRow = btn.closest('.dc-server-details').previousElementSibling;
        const form = document.querySelector('.dc-form-modern');
        
        if (!form || !sourceRow) return;
        
        // מילוי הטופס מהשורה המקורית
        const editBtn = btn.parentElement.querySelector('.dc-edit-server');
        if (editBtn) {
            // העתק את כל הנתונים פרט ל-ID
            form.querySelector('input[name="server_name"]').value = editBtn.dataset.server_name + ' (עותק)';
            form.querySelector('input[name="ip_internal"]').value = ''; // יצטרך למלא חדש
            form.querySelector('input[name="ip_wan"]').value = '';
            form.querySelector('select[name="location"]').value = editBtn.dataset.location || '';
            form.querySelector('select[name="farm"]').value = editBtn.dataset.farm || '';
            
            const customerNameInput = form.querySelector('input[name="customer_name_search"]');
            const customerNumberInput = form.querySelector('input[name="customer_number_search"]');
            const customerIdField = form.querySelector('input[name="customer_id"]');
            
            if (customerNameInput) customerNameInput.value = editBtn.dataset.customer_name || '';
            if (customerNumberInput) customerNumberInput.value = editBtn.dataset.customer_number || '';
            if (customerIdField) customerIdField.value = editBtn.dataset.customer_id || '';
            
            // נקה את ID כדי שזה יהיה הוספה חדשה
            form.querySelector('input[name="id"]').value = '';
            form.querySelector('.dc-form-title').textContent = 'שכפול שרת';
        }
        
        // פתח את הטופס וגלול אליו
        form.classList.remove('dc-form-collapsed');
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
