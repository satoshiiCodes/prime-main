/* ==========================================================================
   Prime City - Customer list behaviour
   Table, filters, edit modal, profile images, requirements and deletions.
   Talks to api/customer-endpoint.php for JSON and to api/*.php for the
   edit form (multipart upload) handlers.
   ========================================================================== */

(function () {
    'use strict';

    var state = {
        config: null,
        table: null,
        modals: {},
        live: null,
        refreshing: null
    };

    /* ----------------------------------------------------------------------
       Configuration and transport
       ---------------------------------------------------------------------- */

    function config() {
        if (state.config) {
            return state.config;
        }

        var node = document.getElementById('customer-list-data');

        if (!node) {
            state.config = {};
            return state.config;
        }

        try {
            state.config = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[customer-list] Could not parse page configuration.', error);
            state.config = {};
        }

        return state.config;
    }

    function endpointUrl(action, params) {
        var url = new URL(config().endpoint || 'api/customer-endpoint.php', window.location.href);
        url.searchParams.set('action', action);

        Object.keys(params || {}).forEach(function (key) {
            url.searchParams.set(key, params[key]);
        });

        return url.toString();
    }

    function listUrl() {
        return config().listUrl || 'customer_list.php';
    }

    function readJson(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (payload) {
            if (!response.ok || (payload && payload.success === false)) {
                var error = new Error((payload && payload.message) || 'Request failed (' + response.status + ')');
                error.status = response.status;
                throw error;
            }

            return payload;
        });
    }

    function getJson(action, params) {
        return fetch(endpointUrl(action, params), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(readJson);
    }

    function postForm(action, payload) {
        var body = new URLSearchParams();

        Object.keys(payload || {}).forEach(function (key) {
            body.append(key, payload[key]);
        });

        return fetch(endpointUrl(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                Accept: 'application/json'
            },
            body: body.toString()
        }).then(readJson);
    }

    function setBusy(button, html) {
        if (!button) {
            return function () {};
        }

        var original = button.innerHTML;
        button.innerHTML = html;
        button.disabled = true;

        return function () {
            button.innerHTML = original;
            button.disabled = false;
        };
    }

    function rowCustomerName(row) {
        var cell = row ? row.querySelector('td:nth-child(2)') : null;
        return cell ? cell.textContent.trim() : '';
    }

    function requirementDownloadUrl(customerId, fileIndex) {
        var url = new URL(listUrl(), window.location.href);
        url.searchParams.set('download_requirements', '1');
        url.searchParams.set('customer_id', customerId);

        if (typeof fileIndex !== 'undefined') {
            url.searchParams.set('file_index', fileIndex);
        }

        return url.toString();
    }

    /* ----------------------------------------------------------------------
       Shared UI helpers
       ---------------------------------------------------------------------- */

    function togglePasswordVisibility(fieldId) {
        var field = document.getElementById(fieldId);

        if (!field) {
            return;
        }

        var icon = field.parentElement.querySelector('.password-toggle');

        if (field.type === 'password') {
            field.type = 'text';
            if (icon) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        } else {
            field.type = 'password';
            if (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }

    function showToast(type, message) {
        var container = document.querySelector('.toast-container');

        if (!container) {
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'toast show align-items-center text-white bg-' + type + ' border-0';
        toast.setAttribute('role', 'alert');
        toast.innerHTML = '<div class="d-flex"><div class="toast-body"></div>'
            + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        toast.querySelector('.toast-body').textContent = message;

        container.appendChild(toast);

        window.setTimeout(function () {
            toast.remove();
        }, 5000);
    }

    /* ----------------------------------------------------------------------
       Filters
       ---------------------------------------------------------------------- */

    function applyFilters() {
        var params = new URLSearchParams();
        var status = document.getElementById('statusFilter');
        var branch = document.getElementById('branchFilter');
        var search = document.querySelector('.search-input');

        if (status && status.value) {
            params.set('status', status.value);
        }
        if (branch && branch.value) {
            params.set('branch', branch.value);
        }
        if (search && search.value.trim()) {
            params.set('search', search.value.trim());
        }

        var query = params.toString();
        window.location.href = listUrl() + (query ? '?' + query : '');
    }

    /* ----------------------------------------------------------------------
       Requirements (edit modal)
       ---------------------------------------------------------------------- */

    function buildRequirementRow(customerId, requirement, options) {
        var item = document.createElement('div');
        item.className = options.itemClass;

        var name = document.createElement('div');
        name.className = options.nameClass;

        var icon = document.createElement('i');
        icon.className = 'fas fa-file me-2';
        name.appendChild(icon);
        name.appendChild(document.createTextNode(requirement.name));

        var actions = document.createElement('div');
        actions.className = options.actionsClass;

        var download = document.createElement('a');
        download.href = requirementDownloadUrl(customerId, requirement.index);
        download.className = options.downloadClass;
        download.title = 'Download';
        download.innerHTML = options.downloadIcon;

        actions.appendChild(download);

        if (options.deletable) {
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.title = 'Delete';
            remove.setAttribute('data-delete-requirement', requirement.index);
            remove.setAttribute('data-customer-id', customerId);
            remove.innerHTML = '<i class="fas fa-trash"></i>';
            actions.appendChild(remove);
        }

        item.appendChild(name);
        item.appendChild(actions);

        return item;
    }

    function deleteRequirement(customerId, fileIndex, button) {
        window.Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0a5fe6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            var restore = setBusy(button, '<i class="fas fa-spinner fa-spin"></i>');

            postForm('delete-requirement', {
                customer_id: customerId,
                file_index: fileIndex
            }).then(function () {
                window.PCToast.show('success', 'Requirement deleted', 'The requirement was removed from this customer.');

                var item = button ? button.closest('.requirement-file-item, .requirement-item') : null;
                if (item) {
                    item.remove();
                }

                var container = document.getElementById('currentRequirementsContainer');
                if (container && container.querySelectorAll('.requirement-file-item').length === 0) {
                    container.innerHTML = '<p class="text-muted">No requirements uploaded yet.</p>';
                }
            }).catch(function (error) {
                console.error('[customer-list]', error);
                window.PCToast.show('error', 'Could not delete requirement', 'Error deleting requirement: ' + error.message);
                restore();
            });
        });
    }

    function loadCurrentRequirements(customerId) {
        var container = document.getElementById('currentRequirementsContainer');

        if (!container) {
            return;
        }

        container.innerHTML = '<p class="text-muted">Loading current requirements...</p>';

        getJson('requirements', { customer_id: customerId }).then(function (payload) {
            container.innerHTML = '';

            if (!payload.requirements || payload.requirements.length === 0) {
                container.innerHTML = '<p class="text-muted">No requirements uploaded yet.</p>';
                return;
            }

            var list = document.createElement('div');
            list.className = 'requirements-list';

            payload.requirements.forEach(function (requirement) {
                list.appendChild(buildRequirementRow(customerId, requirement, {
                    itemClass: 'requirement-file-item',
                    nameClass: 'requirement-file-name',
                    actionsClass: 'requirement-actions',
                    downloadClass: 'btn btn-sm btn-outline-primary me-1',
                    downloadIcon: '<i class="fas fa-download"></i>',
                    deletable: true
                }));
            });

            container.appendChild(list);
        }).catch(function (error) {
            console.error('[customer-list]', error);
            container.innerHTML = '';
            var message = document.createElement('p');
            message.className = 'text-danger';
            message.textContent = 'Error loading requirements: ' + error.message;
            container.appendChild(message);
        });
    }

    /* ----------------------------------------------------------------------
       Modals
       ---------------------------------------------------------------------- */

    function makeModal(id) {
        var element = document.getElementById(id);

        if (!element || typeof bootstrap === 'undefined') {
            return null;
        }

        return new bootstrap.Modal(element);
    }

    function initEditRequirementsZone() {
        var input = document.getElementById('newRequirements');
        var zone = document.querySelector('#editRequirementsZone .upload');
        var nameLabel = document.getElementById('editRequirementsName');
        var clear = document.getElementById('editRequirementsClear');

        if (!input || !nameLabel) {
            return;
        }

        function sync() {
            var files = input.files;
            var count = files ? files.length : 0;

            if (zone) {
                zone.classList.toggle('has-file', count > 0);
            }

            if (count === 0) {
                nameLabel.textContent = 'No files selected';
                nameLabel.classList.remove('is-set');
                nameLabel.removeAttribute('title');
            } else if (count === 1) {
                nameLabel.textContent = files[0].name;
                nameLabel.classList.add('is-set');
                nameLabel.title = files[0].name;
            } else {
                nameLabel.textContent = count + ' files selected';
                nameLabel.classList.add('is-set');
                nameLabel.removeAttribute('title');
            }

            if (clear) {
                clear.hidden = count === 0;
            }
        }

        input.addEventListener('change', sync);

        if (clear) {
            clear.addEventListener('click', function (event) {
                event.preventDefault();
                input.value = '';
                sync();
            });
        }

        sync();
    }
    function initProfileImage() {
        var input = document.getElementById('editProfileImage');
        var preview = document.getElementById('editProfileImagePreview');
        var zone = document.querySelector('#editProfileImageZone .upload');
        var nameLabel = document.getElementById('editProfileImageName');
        var removeBtn = document.getElementById('removeProfileImageBtn');
        var current = document.getElementById('currentProfileImage');
        var flag = document.getElementById('removeProfileImageFlag');

        function resetPhotoLabel() {
            if (nameLabel) {
                nameLabel.textContent = 'No file selected';
                nameLabel.classList.remove('is-set');
                nameLabel.removeAttribute('title');
            }

            if (zone) {
                zone.classList.remove('has-file');
            }

            if (preview) {
                preview.removeAttribute('src');
                preview.style.display = 'none';
            }

            if (removeBtn) {
                removeBtn.hidden = true;
            }
        }

        if (input) {
            input.addEventListener('change', function (event) {
                var file = event.target.files[0];

                if (!file) {
                    return;
                }

                var reader = new FileReader();

                reader.onload = function (loadEvent) {
                    if (preview) {
                        preview.src = loadEvent.target.result;
                        preview.style.display = 'block';
                    }
                    if (zone) {
                        zone.classList.add('has-file');
                    }
                    if (nameLabel) {
                        nameLabel.textContent = file.name;
                        nameLabel.classList.add('is-set');
                        nameLabel.title = file.name;
                    }
                    if (removeBtn) {
                        removeBtn.hidden = false;
                    }
                    if (flag) {
                        flag.value = '0';
                    }
                };

                reader.readAsDataURL(file);
            });
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (input) {
                    input.value = '';
                }
                if (current) {
                    current.value = '';
                }
                if (flag) {
                    flag.value = '1';
                }
                resetPhotoLabel();
            });
        }

        var label = document.querySelector('.file-upload-label');

        if (label && input) {
            label.addEventListener('click', function (event) {
                event.preventDefault();
                input.click();
            });
        }
    }

    function initImageView() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('.customer-image, .no-image-icon');

            if (!trigger) {
                return;
            }

            var row = trigger.closest('tr');
            var customerName = rowCustomerName(row);
            var imageSrc;

            var label = document.getElementById('imageViewModalLabel');
            if (label) {
                label.textContent = customerName + "'s Profile";
            }

            if (trigger.classList.contains('customer-image')) {
                imageSrc = trigger.getAttribute('src');
            } else {
                imageSrc = 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300" fill="%23f8f9fa"%3E%3Crect width="400" height="300"/%3E%3Ctext x="200" y="150" font-family="Arial" font-size="20" text-anchor="middle" fill="%236c757d"%3ENo Image Available%3C/text%3E%3C/svg%3E';
            }

            var fullSize = document.getElementById('fullSizeImage');
            if (fullSize) {
                fullSize.src = imageSrc;
                fullSize.alt = customerName + "'s Profile Image";
            }

            if (state.modals.imageView) {
                state.modals.imageView.show();
            }
        });
    }

    /* Address strings are stored as "No., Street, Floor/Building, Barangay,
       Municipality, Province" - split them back into the six boxes. */
    var ADDRESS_PARTS = ['HouseNumber', 'Street', 'Building', 'Barangay', 'Municipality', 'Province'];

    function addressFieldId(prefix, part) {
        return (prefix === 'editPresent' ? 'editPresent' : 'edit') + part;
    }

    function fillAddressParts(prefix, value) {
        var parts = String(value || '').split(',').map(function (part) {
            return part.trim();
        });

        ADDRESS_PARTS.forEach(function (part, index) {
            var field = document.getElementById(addressFieldId(prefix, part));

            if (field) {
                field.value = parts[index] || '';
            }
        });
    }

    function initEditSameAsPermanent() {
        var checkbox = document.getElementById('editSameAsPermanent');
        var presentFields = document.getElementById('editPresentAddressFields');

        if (!checkbox) {
            return;
        }

        var presentInputs = presentFields
            ? presentFields.querySelectorAll('input[type="text"]')
            : [];

        function copyPermanent() {
            ADDRESS_PARTS.forEach(function (part) {
                var source = document.getElementById('edit' + part);
                var target = document.getElementById('editPresent' + part);

                if (source && target) {
                    target.value = source.value;
                }
            });
        }

        checkbox.addEventListener('change', function () {
            if (checkbox.checked) {
                copyPermanent();
            }

            Array.prototype.forEach.call(presentInputs, function (input) {
                input.readOnly = checkbox.checked;
            });
        });

        ADDRESS_PARTS.forEach(function (part) {
            var source = document.getElementById('edit' + part);

            if (source) {
                source.addEventListener('input', function () {
                    if (checkbox.checked) {
                        copyPermanent();
                    }
                });
            }
        });
    }

    function fillEditForm(customer) {
        var fields = {
            editCustomerId: customer.id,
            editLastName: customer.last_name,
            editFirstName: customer.first_name,
            editMiddleName: customer.middle_name,
            editSuffix: customer.suffix,
            editGender: customer.gender || 'Male',
            editMaritalStatus: customer.marital_status,
            editDateOfBirth: customer.date_of_birth === '0000-00-00' ? '' : customer.date_of_birth,
            editPlaceOfBirth: customer.place_of_birth,
            editSpouseName: customer.spouse_name,
            editSpouseOccupation: customer.spouse_occupation,
            editOccupation: customer.occupation,
            editEmployer: customer.employer_business_name,
            editNatureOfWork: customer.nature_of_work,
            editContactNumber: customer.contact_number,
            editContactNumber2: customer.contact_number_2,
            editUsername: customer.username,
            editBranch: customer.branch,
            editArea: customer.area,
            editStatus: customer.status || 'Active',
            editNotes: customer.notes
        };

        Object.keys(fields).forEach(function (id) {
            var field = document.getElementById(id);
            if (field) {
                field.value = fields[id] === null || fields[id] === undefined ? '' : fields[id];
            }
        });

        fillAddressParts('', customer.address);
        fillAddressParts('editPresent', customer.present_address);

        var password = document.getElementById('editPassword');
        var confirmPassword = document.getElementById('editConfirmPassword');
        if (password) {
            password.value = '';
        }
        if (confirmPassword) {
            confirmPassword.value = '';
        }

        var preview = document.getElementById('editProfileImagePreview');
        var zone = document.querySelector('#editProfileImageZone .upload');
        var nameLabel = document.getElementById('editProfileImageName');
        var current = document.getElementById('currentProfileImage');
        var removeBtn = document.getElementById('removeProfileImageBtn');
        var flag = document.getElementById('removeProfileImageFlag');
        var input = document.getElementById('editProfileImage');

        if (preview && current && removeBtn) {
            if (customer.profile_image) {
                var fileName = String(customer.profile_image).split('/').pop();

                preview.src = customer.profile_image;
                preview.style.display = 'block';
                current.value = customer.profile_image;
                removeBtn.hidden = false;

                if (zone) {
                    zone.classList.add('has-file');
                }

                if (nameLabel) {
                    nameLabel.textContent = fileName;
                    nameLabel.classList.add('is-set');
                    nameLabel.title = fileName;
                }
            } else {
                preview.removeAttribute('src');
                preview.style.display = 'none';
                current.value = '';
                removeBtn.hidden = true;

                if (zone) {
                    zone.classList.remove('has-file');
                }

                if (nameLabel) {
                    nameLabel.textContent = 'No file selected';
                    nameLabel.classList.remove('is-set');
                    nameLabel.removeAttribute('title');
                }
            }
        }

        if (flag) {
            flag.value = '0';
        }

        if (input) {
            input.value = '';
        }
    }

    function initEditFlow() {
        document.addEventListener('click', function (event) {
            var button = event.target.classList.contains('edit-btn')
                ? event.target
                : event.target.closest('.edit-btn');

            if (!button) {
                return;
            }

            var customerId = button.getAttribute('data-id');
            var restore = setBusy(button, '<i class="fas fa-spinner fa-spin"></i>');

            var flag = document.getElementById('removeProfileImageFlag');
            if (flag) {
                flag.value = '0';
            }

            fetch('api/get_customer_details.php?id=' + encodeURIComponent(customerId), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            }).then(function (customer) {
                if (customer.error) {
                    throw new Error(customer.error);
                }

                fillEditForm(customer);
                loadCurrentRequirements(customerId);

                if (state.modals.edit) {
                    state.modals.edit.show();
                }
            }).catch(function (error) {
                console.error('[customer-list]', error);
                window.PCToast.show('error', 'Could not load customer', 'Error loading customer data: ' + error.message);
            }).finally(restore);
        });

        var form = document.getElementById('editCustomerForm');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var submitBtn = form.querySelector('button[type="submit"]');
            var restore = setBusy(submitBtn, '<i class="fas fa-spinner fa-spin"></i> Saving...');

            if (config().isAdmin) {
                var password = document.getElementById('editPassword');
                var confirmPassword = document.getElementById('editConfirmPassword');

                if (password && confirmPassword && password.value && password.value !== confirmPassword.value) {
                    restore();
                    window.PCToast.show('warning', 'Passwords do not match', 'Enter the same password in both fields.');
                    return;
                }
            }

            fetch('api/update_customers.php', {
                method: 'POST',
                body: new FormData(form)
            }).then(function (response) {
                if (!response.ok) {
                    return response.text().then(function (text) {
                        var parsed;

                        try {
                            parsed = JSON.parse(text);
                        } catch (error) {
                            throw new Error(text || 'Network response was not ok');
                        }

                        throw new Error(parsed.error || parsed.message || 'Unknown error occurred');
                    });
                }

                return response.json();
            }).then(function (payload) {
                if (!payload.success) {
                    throw new Error(payload.message || 'Failed to update customer');
                }

                window.PCToast.show('success', 'Customer updated', payload.message || 'Customer updated successfully!');

                if (state.modals.edit) {
                    state.modals.edit.hide();
                }

                // No page reload: the table re-renders from the server, and the
                // live stream keeps it in sync afterwards.
                window.setTimeout(function () {
                    liveRefreshTable();
                }, 250);
            }).catch(function (error) {
                console.error('[customer-list]', error);
                window.PCToast.show('error', 'Could not update customer', 'Error updating customer: ' + error.message);
            }).finally(restore);
        });
    }

    function initDeleteFlow() {
        function deleteCustomer(id, row, button) {
            var restore = setBusy(button, '<i class="fas fa-spinner fa-spin"></i>');

            postForm('delete-customer', { id: id }).then(function () {
                window.PCToast.show('success', 'Customer deleted', 'The customer has been removed from the list.');

                if (!row) {
                    window.setTimeout(function () {
                        liveRefreshTable();
                    }, 300);
                    return;
                }

                row.classList.add('fade-out');

                window.setTimeout(function () {
                    row.remove();
                    if (state.table) {
                        state.table.refresh();
                    }
                }, 500);
            }).catch(function (error) {
                console.error('[customer-list]', error);
                restore();
                window.PCToast.show(
                    'error',
                    'Cannot delete customer',
                    error.status === 409 || error.status === 404
                        ? error.message
                        : 'Cannot delete Customer with an Active Loan.'
                );
            });
        }

        document.addEventListener('click', function (event) {
            var button = event.target.classList.contains('delete-btn')
                ? event.target
                : event.target.closest('.delete-btn');

            if (!button) {
                return;
            }

            var row = button.closest('tr');
            var customerId = button.getAttribute('data-id');

            window.Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0a5fe6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then(function (result) {
                if (result.isConfirmed) {
                    deleteCustomer(customerId, row, button);
                }
            });
        });
    }

    function initRequirementsFlow() {
        document.addEventListener('click', function (event) {
            if (event.target.hasAttribute('data-delete-requirement')
                || event.target.closest('[data-delete-requirement]')) {
                var removeBtn = event.target.hasAttribute('data-delete-requirement')
                    ? event.target
                    : event.target.closest('[data-delete-requirement]');

                event.preventDefault();
                deleteRequirement(
                    removeBtn.getAttribute('data-customer-id'),
                    removeBtn.getAttribute('data-delete-requirement'),
                    removeBtn
                );
                return;
            }

            var button = event.target.classList.contains('requirements-btn')
                ? event.target
                : event.target.closest('.requirements-btn');

            if (!button) {
                return;
            }

            var customerId = button.getAttribute('data-id');
            var customerName = rowCustomerName(button.closest('tr'));
            var restore = setBusy(button, '<i class="fas fa-spinner fa-spin"></i>');

            var label = document.getElementById('requirementsModalLabel');
            if (label) {
                label.textContent = customerName + "'s Requirements";
            }

            var downloadAll = document.getElementById('downloadAllRequirements');
            if (downloadAll) {
                downloadAll.href = requirementDownloadUrl(customerId);
            }

            if (state.modals.requirements) {
                state.modals.requirements.show();
            }

            getJson('requirements', { customer_id: customerId }).then(function (payload) {
                var container = document.getElementById('requirementsContainer');

                if (!container) {
                    return;
                }

                container.innerHTML = '';

                if (!payload.requirements || payload.requirements.length === 0) {
                    container.innerHTML = '<div class="alert alert-warning text-center py-4">'
                        + '<i class="fas fa-exclamation-circle fa-2x mb-3"></i>'
                        + '<h5>No Requirements Found</h5>'
                        + '<p class="mb-0">This customer doesn\'t have any requirements uploaded yet.</p>'
                        + '</div>';
                    return;
                }

                var list = document.createElement('div');
                list.className = 'requirements-list';

                payload.requirements.forEach(function (requirement) {
                    list.appendChild(buildRequirementRow(customerId, requirement, {
                        itemClass: 'requirement-item',
                        nameClass: 'requirement-name',
                        actionsClass: 'requirement-actions',
                        downloadClass: 'btn btn-sm btn-warning download-requirement-btn',
                        downloadIcon: '<i class="fas fa-download me-1"></i> Download',
                        deletable: false
                    }));
                });

                container.appendChild(list);
            }).catch(function (error) {
                console.error('[customer-list]', error);

                var container = document.getElementById('requirementsContainer');
                if (container) {
                    container.innerHTML = '<div class="alert alert-danger text-center py-4">'
                        + '<i class="fas fa-exclamation-triangle fa-2x mb-3"></i>'
                        + '<h5>Error Loading Requirements</h5>'
                        + '<p class="mb-0"></p>'
                        + '</div>';
                    container.querySelector('p.mb-0').textContent = error.message;
                }
            }).finally(restore);
        });
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */

    /* ----------------------------------------------------------------------
       Live updates (Server-Sent Events)

       The stream pushes a `customers` event whenever the table changes, so an
       edit or delete made here - or in another tab - lands without a reload.
       ---------------------------------------------------------------------- */

    var LIVE_STREAM_URL = 'api/customer-list-stream.php';

    function liveSetText(id, value) {
        var node = document.getElementById(id);

        if (node) {
            node.textContent = value;
        }
    }

    function liveStats(payload) {
        if (!payload) {
            return;
        }

        var total = Number(payload.total) || 0;
        var active = Number(payload.active) || 0;
        var inactive = Number(payload.inactive) || 0;
        var other = Number(payload.other) || 0;
        var branches = Number(payload.branches) || 0;
        var activePercent = total > 0 ? Math.round(active * 100 / total) : 0;
        var inactivePercent = total > 0 ? Math.round(inactive * 100 / total) : 0;

        liveSetText('statTotal', total.toLocaleString());
        liveSetText('statTotalMeta', branches === 1 ? '1 branch' : branches.toLocaleString() + ' branches');
        liveSetText('statActive', active.toLocaleString());
        liveSetText('statActiveMeta', activePercent + '% of listed accounts');
        liveSetText('statInactive', inactive.toLocaleString());
        liveSetText('statInactiveMeta', inactivePercent + '% of listed accounts');
        liveSetText('statOther', other.toLocaleString());
        liveSetText('statAccountsLabel', total.toLocaleString());
    }

    /* Re-render the rows from the server without reloading the page. */
    function liveRefreshTable() {
        if (state.refreshing) {
            return state.refreshing;
        }

        var page = state.table && state.table._currentPage ? state.table._currentPage : 1;
        var searchInput = document.querySelector('.search-input');
        var term = searchInput ? searchInput.value : '';

        state.refreshing = window.fetch(window.location.pathname + window.location.search, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function (response) {
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var freshBody = parsed.querySelector('#datatablesSimple tbody');
            var current = document.getElementById('datatablesSimple');

            if (!freshBody || !current) {
                return;
            }

            // Destroy first: the library would otherwise restore its own rows.
            if (state.table && typeof state.table.destroy === 'function') {
                state.table.destroy();
            }

            var body = current.querySelector('tbody');

            if (body) {
                body.innerHTML = freshBody.innerHTML;
            }

            initTable();

            if (!state.table) {
                return;
            }

            if (term !== '') {
                state.table.search(term);
            }

            if (page > 1) {
                state.table.page(page);
            }
        }).catch(function (error) {
            console.warn('[customer-list] live refresh failed', error);
        }).then(function () {
            state.refreshing = null;
        });

        return state.refreshing;
    }

    function connectLiveUpdates() {
        if (typeof window.EventSource === 'undefined') {
            return;
        }

        state.live = { connected: false, lastEvent: null, source: null };

        var source = new window.EventSource(LIVE_STREAM_URL);
        state.live.source = source;

        source.addEventListener('open', function () {
            state.live.connected = true;
        });

        source.addEventListener('customers', function (event) {
            var payload;

            try {
                payload = JSON.parse(event.data);
            } catch (error) {
                return;
            }

            state.live.lastEvent = payload;
            liveStats(payload);

            // Skip the very first snapshot: the page already rendered it.
            if (state.live.lastSignature === undefined) {
                state.live.lastSignature = payload.signature;
                return;
            }

            if (payload.signature !== state.live.lastSignature) {
                state.live.lastSignature = payload.signature;
                liveRefreshTable();
            }
        });

        source.addEventListener('error', function () {
            state.live.connected = false;
        });
    }
    function closeLiveUpdates() {
        if (state.live && state.live.source) {
            state.live.source.close();
            state.live.connected = false;
        }
    }
    /* ----------------------------------------------------------------------
       Add New Customer modal

       The form itself is includes/customer-registration-form.php - the same
       markup the registration page renders - so validation and uploads come
       from customer-registration.js. Submitting goes to api/create-customer.php
       and the list refreshes through the live stream instead of a reload.
       ---------------------------------------------------------------------- */

    function resetAddCustomerForm(form) {
        if (!form) {
            return;
        }

        form.reset();

        var name = document.getElementById('profileImageName');
        if (name) {
            name.textContent = 'No file selected';
            name.classList.remove('is-set');
            name.removeAttribute('title');
        }

        var photoClear = document.getElementById('profileImageClear');
        if (photoClear) {
            photoClear.hidden = true;
        }

        var requirementsName = document.getElementById('requirementsName');
        if (requirementsName) {
            requirementsName.textContent = 'No files selected';
            requirementsName.classList.remove('is-set');
            requirementsName.removeAttribute('title');
        }

        var requirementsClear = document.getElementById('requirementsClear');
        if (requirementsClear) {
            requirementsClear.hidden = true;
        }

        var fileList = document.getElementById('fileList');
        if (fileList) {
            fileList.innerHTML = '';
        }

        var preview = document.getElementById('profile_image_preview');
        if (preview) {
            preview.removeAttribute('src');
            preview.style.display = 'none';
        }

        ['profileImageZone', 'requirementsZone'].forEach(function (id) {
            var zone = document.getElementById(id);
            if (zone) {
                zone.classList.remove('has-file', 'is-dragover');
            }
        });
    }

    function submitAddCustomer(form, submitButton) {
        var original = submitButton ? submitButton.innerHTML : '';

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
        }

        return window.fetch('api/create-customer.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                throw new Error((payload && payload.message) || 'Registration failed.');
            }

            window.PCToast.show('success', 'Customer registered', payload.message || 'The customer has been added.');

            if (state.modals.add) {
                state.modals.add.hide();
            }

            resetAddCustomerForm(form);
            liveRefreshTable();

            return true;
        }).catch(function (error) {
            window.PCToast.show('error', 'Registration failed', error.message);

            return false;
        }).then(function (result) {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = original;
            }

            return result;
        });
    }

    function initAddCustomerFlow() {
        var form = document.getElementById('customerRegistrationForm');
        var modal = document.getElementById('addCustomerModal');

        if (!form || !modal) {
            return;
        }

        form.addEventListener('submit', function (event) {
            // customer-registration.js validates first and cancels invalid submits.
            if (event.defaultPrevented) {
                return;
            }

            event.preventDefault();

            submitAddCustomer(form, form.querySelector('button[type="submit"]'));
        });

        modal.addEventListener('hidden.bs.modal', function () {
            resetAddCustomerForm(form);
        });
    }
    function initTable() {
        var element = document.getElementById('datatablesSimple');

        if (!element || typeof simpleDatatables === 'undefined') {
            return;
        }

        state.table = new simpleDatatables.DataTable(element, {
            searchable: false,
            fixedHeight: false,
            perPage: 10,
            perPageSelect: false,
            // The library truncates the pager itself (ellipsis, first/last
            // always kept); a delta of 1 keeps the row short.
            truncatePager: true,
            pagerDelta: 1,
            labels: {
                placeholder: 'Search customers...',
                searchTitle: 'Search within table',
                pageTitle: 'Page {page}',
                perPage: 'entries per page',
                noRows: 'No entries to found',
                info: 'Showing {start} to {end} of {rows} entries',
                noResults: 'No results match your search query'
            }
        });
    }

    /* ----------------------------------------------------------------------
       Search: filter the rendered table as the user types (no reload).
       The form still works without JavaScript - pressing Enter submits it.
       ---------------------------------------------------------------------- */

    function initSearch() {
        var input = document.querySelector('.search-input');

        if (!input || !state.table) {
            return;
        }

        var form = input.form;
        var clear = document.querySelector('.dash-toolbar__clear');
        var timer = null;

        function applySearch() {
            state.table.search(input.value.trim());
        }

        function syncClearButton() {
            if (!clear) {
                return;
            }

            var hasServerFilters = clear.getAttribute('data-has-filters') === '1';

            clear.hidden = input.value === '' && !hasServerFilters;
            input.form && input.form.classList.toggle('is-filtered', input.value !== '');
        }

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                applySearch();
                syncClearButton();
            }, 150);
        });

        // Enter filters live instead of reloading the page.
        if (form) {
            form.addEventListener('submit', function (event) {
                if (event.submitter && event.submitter.classList.contains('dash-toolbar__clear')) {
                    return;
                }

                event.preventDefault();
                applySearch();
                syncClearButton();
            });
        }

        if (clear) {
            clear.addEventListener('click', function (event) {
                // Server-side filters need a reload; a typed query does not.
                if (clear.getAttribute('data-has-filters') === '1') {
                    return;
                }

                event.preventDefault();
                input.value = '';
                applySearch();
                syncClearButton();
                input.focus();
            });
        }

        // Seed from ?search= when the page was loaded with a query.
        if (input.value.trim() !== '') {
            applySearch();
        }

        syncClearButton();
    }
    function initFilters() {
        var status = document.getElementById('statusFilter');
        var branch = document.getElementById('branchFilter');

        if (status) {
            status.addEventListener('change', applyFilters);
        }

        if (branch && branch.tagName === 'SELECT') {
            branch.addEventListener('change', applyFilters);
        }
    }

    function init() {
        state.modals = {
            add: makeModal('addCustomerModal'),
            edit: makeModal('editCustomerModal'),
            requirements: makeModal('requirementsModal'),
            imageView: makeModal('imageViewModal'),
            viewPayments: makeModal('viewPaymentsModal'),
            deleteConfirmation: makeModal('deleteConfirmationModal')
        };

        initTable();
        initSearch();
        initFilters();
        initProfileImage();
        initImageView();

        initAddCustomerFlow();
        initEditRequirementsZone();
        initEditSameAsPermanent();

        if (config().canManage !== false) {
            initEditFlow();
            initDeleteFlow();
            initRequirementsFlow();
        }

        connectLiveUpdates();
    }

    // Live-update handle: handy for debugging and for closing the stream cleanly.
    window.__customerListLive = {
        refresh: liveRefreshTable,
        stats: liveStats,
        close: closeLiveUpdates,
        state: function () { return state.live; }
    };

    window.addEventListener('pagehide', closeLiveUpdates);

    // Inline handlers in the markup (password toggles) rely on these globals.
    window.togglePasswordVisibility = togglePasswordVisibility;
    window.deleteRequirement = deleteRequirement;
    window.loadCurrentRequirements = loadCurrentRequirements;
    window.filterByStatus = applyFilters;
    window.filterByBranch = applyFilters;

    document.addEventListener('DOMContentLoaded', init);
})();
