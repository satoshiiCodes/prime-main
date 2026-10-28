/* ==========================================================================
   Prime City - Customer registration behaviour
   Field helpers, file previews and client-side validation for
   customer_registration.php. Flash messages and the endpoint come from the
   #customer-registration-data block rendered by the page.
   ========================================================================== */

(function () {
    'use strict';

    var state = {
        config: null
    };

    /* ----------------------------------------------------------------------
       Configuration
       ---------------------------------------------------------------------- */

    function config() {
        if (state.config) {
            return state.config;
        }

        var node = document.getElementById('customer-registration-data');

        if (!node) {
            state.config = {};
            return state.config;
        }

        try {
            state.config = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[customer-registration] Could not parse page configuration.', error);
            state.config = {};
        }

        return state.config;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    /* ----------------------------------------------------------------------
       Field helpers (also called from inline handlers in the markup)
       ---------------------------------------------------------------------- */

    function validateContactNumber(number) {
        var errorElement = byId('contactNumberError');
        var isValid = number === '' || /^09\d{9}$/.test(number);

        if (errorElement) {
            errorElement.style.display = (number.length > 0 && !isValid) ? 'block' : 'none';
        }

        return isValid;
    }

    function validateContactNumber2(number) {
        var errorElement = byId('contactNumber2Error');
        var isValid = number === '' || /^09\d{9}$/.test(number);

        if (errorElement) {
            errorElement.style.display = (number.length > 0 && !isValid) ? 'block' : 'none';
        }

        return isValid;
    }

    function togglePassword(fieldId) {
        var field = byId(fieldId);

        if (!field) {
            return;
        }

        var icon = field.parentNode.querySelector('.password-toggle i');

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

    /* ----------------------------------------------------------------------
       Present address / spouse / file previews
       ---------------------------------------------------------------------- */

    function initSameAsPermanent() {
        var checkbox = byId('sameAsPermanent');
        var presentAddressFields = byId('presentAddressFields');

        if (!checkbox || !presentAddressFields) {
            return;
        }

        checkbox.addEventListener('change', function () {
            var inputs = presentAddressFields.getElementsByTagName('input');

            if (this.checked) {
                var copies = {
                    present_province: 'province',
                    present_municipality: 'municipality',
                    present_barangay: 'barangay',
                    present_house_number: 'house_number',
                    present_street: 'street',
                    present_building: 'building'
                };

                Object.keys(copies).forEach(function (target) {
                    var to = byId(target);
                    var from = byId(copies[target]);

                    if (to && from) {
                        to.value = from.value;
                    }
                });

                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].disabled = true;
                }
            } else {
                for (var j = 0; j < inputs.length; j++) {
                    inputs[j].disabled = false;
                    inputs[j].value = '';
                }
            }
        });
    }

    function formatFileSize(bytes) {
        if (!bytes && bytes !== 0) {
            return '';
        }

        if (bytes < 1024) {
            return bytes + ' B';
        }

        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(0) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function fileIconClass(name) {
        var extension = String(name || '').split('.').pop().toLowerCase();

        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].indexOf(extension) !== -1) {
            return 'fa-file-image';
        }

        if (['pdf'].indexOf(extension) !== -1) {
            return 'fa-file-pdf';
        }

        if (['doc', 'docx'].indexOf(extension) !== -1) {
            return 'fa-file-word';
        }

        if (['xls', 'xlsx', 'csv'].indexOf(extension) !== -1) {
            return 'fa-file-excel';
        }

        return 'fa-file-lines';
    }

    /* Drop zone around a native file input: click to browse, drag to drop,
       file name underneath, optional clear button. The input itself is never
       replaced, so the form still posts the same fields. */
    function initUploadZone(options) {
        var input = byId(options.input);
        var zone = byId(options.zone);

        if (!input || !zone) {
            return;
        }

        var nameLabel = byId(options.name);
        var clearButton = byId(options.clear);
        var emptyText = nameLabel ? nameLabel.textContent : '';

        if (options.photo) {
            zone.classList.add('is-photo');
        }

        function sync() {
            var files = input.files;
            var count = files ? files.length : 0;

            zone.classList.toggle('has-file', count > 0);

            if (nameLabel) {
                if (count === 0) {
                    nameLabel.textContent = emptyText;
                    nameLabel.classList.remove('is-set');
                } else if (count === 1) {
                    nameLabel.textContent = files[0].name;
                    nameLabel.classList.add('is-set');
                    nameLabel.title = files[0].name;
                } else {
                    nameLabel.textContent = count + ' files selected';
                    nameLabel.classList.add('is-set');
                    nameLabel.removeAttribute('title');
                }
            }

            if (clearButton) {
                clearButton.hidden = count === 0;
            }
        }

        input.addEventListener('change', sync);

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            zone.addEventListener(type, function () {
                zone.classList.remove('is-dragover');
            });
        });

        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragover');

            var dropped = event.dataTransfer ? event.dataTransfer.files : null;

            if (!dropped || dropped.length === 0) {
                return;
            }

            var transfer = new DataTransfer();
            var accepted = input.multiple ? dropped : [dropped[0]];

            for (var i = 0; i < accepted.length; i++) {
                transfer.items.add(accepted[i]);
            }

            try {
                input.files = transfer.files;
            } catch (error) {
                return;   // browser refuses programmatic assignment: use browse
            }

            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        if (clearButton) {
            clearButton.addEventListener('click', function (event) {
                event.preventDefault();
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        sync();
    }

    function initUploadZones() {
        initUploadZone({
            input: 'profile_image',
            zone: 'profileImageZone',
            name: 'profileImageName',
            clear: 'profileImageClear',
            photo: true
        });

        initUploadZone({
            input: 'requirements',
            zone: 'requirementsZone',
            name: 'requirementsName',
            clear: 'requirementsClear'
        });
    }
    function initProfilePreview() {
        var input = byId('profile_image');

        if (!input) {
            return;
        }

        input.addEventListener('change', function (event) {
            var file = event.target.files[0];
            var preview = byId('profile_image_preview');

            // Cleared: drop the preview so the zone falls back to its prompt.
            if (!file) {
                if (preview) {
                    preview.removeAttribute('src');
                    preview.style.display = 'none';
                }

                return;
            }

            var reader = new FileReader();

            reader.onload = function (loadEvent) {
                if (preview) {
                    preview.src = loadEvent.target.result;
                    preview.style.display = 'block';
                }
            };

            reader.readAsDataURL(file);
        });
    }

    function initRequirementsList() {
        var input = byId('requirements');

        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            var fileList = byId('fileList');

            if (!fileList) {
                return;
            }

            fileList.innerHTML = '';

            var files = input.files;

            if (!files || files.length === 0) {
                return;
            }

            for (var i = 0; i < files.length; i++) {
                var fileItem = document.createElement('div');
                fileItem.className = 'file-item';

                var icon = document.createElement('i');
                icon.className = 'file-item__icon fa-solid ' + fileIconClass(files[i].name);
                icon.setAttribute('aria-hidden', 'true');

                var name = document.createElement('span');
                name.className = 'file-item__name';
                name.textContent = files[i].name;
                name.title = files[i].name;

                var size = document.createElement('span');
                size.className = 'file-item__size';
                size.textContent = formatFileSize(files[i].size);

                var remove = document.createElement('span');
                remove.className = 'remove-file';
                remove.setAttribute('data-index', i);
                remove.setAttribute('role', 'button');
                remove.setAttribute('title', 'Remove ' + files[i].name);
                remove.innerHTML = '&times;';

                fileItem.appendChild(icon);
                fileItem.appendChild(name);
                fileItem.appendChild(size);
                fileItem.appendChild(remove);
                fileList.appendChild(fileItem);
            }

            var removeButtons = fileList.getElementsByClassName('remove-file');

            for (var b = 0; b < removeButtons.length; b++) {
                removeButtons[b].addEventListener('click', function () {
                    var index = parseInt(this.getAttribute('data-index'), 10);
                    var transfer = new DataTransfer();
                    var current = byId('requirements');
                    var selected = current.files;

                    for (var k = 0; k < selected.length; k++) {
                        if (index !== k) {
                            transfer.items.add(selected[k]);
                        }
                    }

                    current.files = transfer.files;
                    current.dispatchEvent(new Event('change'));
                });
            }
        });
    }

    function initMaritalStatus() {
        var select = byId('marital_status');

        if (!select) {
            return;
        }

        select.addEventListener('change', function () {
            var spouseName = byId('spouse_name');
            var spouseOccupation = byId('spouse_occupation');

            if (!spouseName || !spouseOccupation) {
                return;
            }

            if (this.value === 'Single' || this.value === 'Separated') {
                spouseName.disabled = true;
                spouseName.classList.add('disabled-field');
                spouseName.value = '';

                spouseOccupation.disabled = true;
                spouseOccupation.classList.add('disabled-field');
                spouseOccupation.value = '';
            } else {
                spouseName.disabled = false;
                spouseName.classList.remove('disabled-field');

                spouseOccupation.disabled = false;
                spouseOccupation.classList.remove('disabled-field');
            }
        });
    }

    /* ----------------------------------------------------------------------
       Client-side validation
       ---------------------------------------------------------------------- */

    function alertError(title, text) {
        window.Swal.fire({
            icon: 'error',
            title: title,
            text: text,
            confirmButtonColor: '#0a5fe6',
            confirmButtonText: 'OK'
        });
    }

    function initSubmitValidation() {
        var form = byId('customerRegistrationForm');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            var contactNumber = byId('contact_number');
            var contactNumber2 = byId('contact_number_2');
            var username = byId('username');
            var password = byId('password');
            var confirmPassword = byId('confirm_password');
            var branch = byId('branch');

            var contactValue = contactNumber ? contactNumber.value : '';
            var contactValue2 = contactNumber2 ? contactNumber2.value : '';
            var usernameValue = username ? username.value : '';
            var passwordValue = password ? password.value : '';
            var confirmValue = confirmPassword ? confirmPassword.value : '';
            var branchValue = branch ? branch.value : '';

            if (!validateContactNumber(contactValue) || !validateContactNumber2(contactValue2)) {
                event.preventDefault();
                alertError('Invalid Contact Number', 'Please enter valid contact numbers (11 digits starting with 09)');
                return false;
            }

            if (!usernameValue) {
                event.preventDefault();
                alertError('Missing Information', 'Username is required');
                return false;
            }

            if (!passwordValue) {
                event.preventDefault();
                alertError('Missing Information', 'Password is required');
                return false;
            }

            if (passwordValue !== confirmValue) {
                event.preventDefault();
                alertError('Password Mismatch', 'Passwords do not match');
                return false;
            }

            if (!branchValue) {
                event.preventDefault();
                alertError('Missing Information', 'Please select a branch');
                return false;
            }

            var requiredIds = ['last_name', 'first_name', 'province', 'municipality', 'barangay', 'street'];
            var missing = requiredIds.some(function (id) {
                var field = byId(id);
                return !field || !field.value;
            });

            if (missing) {
                event.preventDefault();
                alertError('Missing Information', 'Please fill all required fields');
                return false;
            }

            return true;
        });
    }

    /* ----------------------------------------------------------------------
       Live username availability
       ---------------------------------------------------------------------- */

    function initUsernameCheck() {
        var input = byId('username');
        var endpoint = config().endpoint;

        if (!input || !endpoint || typeof window.fetch !== 'function') {
            return;
        }

        function hint() {
            var node = byId('usernameAvailability');

            if (node) {
                return node;
            }

            node = document.createElement('div');
            node.id = 'usernameAvailability';
            node.className = 'form-text';

            var wrapper = input.closest('.form-floating');

            if (wrapper && wrapper.parentNode) {
                wrapper.parentNode.insertBefore(node, wrapper.nextSibling);
                return node;
            }

            return null;
        }

        function setHint(text, className) {
            var node = hint();

            if (node) {
                node.textContent = text;
                node.className = className;
            }
        }

        input.addEventListener('blur', function () {
            var username = input.value.trim();

            if (username === '') {
                setHint('', 'form-text');
                return;
            }

            setHint('Checking availability...', 'form-text text-muted');

            window.fetch(endpoint + '?action=username-availability&username=' + encodeURIComponent(username), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (!payload || payload.success !== true) {
                    throw new Error((payload && payload.message) || 'Check failed');
                }

                setHint(
                    payload.message,
                    payload.available ? 'form-text text-success' : 'form-text text-danger'
                );
            }).catch(function () {
                setHint('', 'form-text');
            });
        });
    }

    /* ----------------------------------------------------------------------
       Flash messages
       ---------------------------------------------------------------------- */

    /* Toasts come from assets/js/pc-toast.js (PCToast), shared with the list page. */
    function showFlash() {
        var settings = config();

        if (typeof window.Swal === 'undefined') {
            return;
        }

        if (settings.success) {
            window.PCToast.show('success', 'Customer registered', settings.success);
        }

        if (!settings.error) {
            return;
        }

        if (settings.error === 'A customer with the same name already exists in the system.') {
            alertError('Duplicate Customer', settings.error);
            return;
        }

        window.PCToast.show('error', 'Registration failed', settings.error);
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */

    function init() {
        initSameAsPermanent();
        initUploadZones();
        initProfilePreview();
        initRequirementsList();
        initMaritalStatus();
        initSubmitValidation();
        initUsernameCheck();
        showFlash();
    }

    // Inline handlers in the markup rely on these globals.
    window.togglePassword = togglePassword;
    window.validateContactNumber = validateContactNumber;
    window.validateContactNumber2 = validateContactNumber2;

    document.addEventListener('DOMContentLoaded', init);
})();
