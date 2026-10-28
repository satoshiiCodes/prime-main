/* ==========================================================================
   Prime City - Collector registration behaviour
   Field toggles, live validation and flash messages.
   Page data lives in #collector-registration-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    /**
     * Page configuration from #collector-registration-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('collector-registration-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[collector-registration] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    /* Toasts come from assets/js/pc-toast.js (PCToast), the same component the
       customer list uses. Falls back to SweetAlert2 when it is unavailable. */
    function toast(type, title, text) {
        if (window.PCToast) {
            window.PCToast.show(type, title, text);
            return;
        }

        if (window.Swal) {
            window.Swal.fire({
                icon: type === 'success' ? 'success' : 'error',
                title: title,
                text: text,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 4000
            });
        }
    }

    function toastError(title, text) {
        toast('error', title, text);
    }
    const pageCfg = pageConfig();
            // Function to toggle fields based on account type
            function toggleFields() {
                const accountType = document.getElementById('account_type').value;
                const areaField = document.getElementById('area');
                const areaLabel = document.getElementById('area-label');
                
                // Update area field based on account type
                if (accountType === 'Collector') {
                    // Make area required for Collectors
                    areaField.required = true;
                    areaLabel.innerHTML = 'Area <span style="color: red;">*</span>';
                } else if (accountType === 'Proxy Collector' || accountType === 'Proxy Collector-Cashier') {
                    // Make area not required for Proxy Collectors
                    areaField.required = false;
                    areaLabel.innerHTML = 'Area';
                    
                    // Set area to "All Areas" for Proxy accounts if empty
                    if (areaField.value === '') {
                        areaField.value = 'All Areas';
                    }
                } else {
                    // Reset if no account type selected
                    areaField.required = false;
                    areaLabel.innerHTML = 'Area';
                }
            }

            // Set current date as registration date in Philippine time zone
            /* Profile image drop zone: click to browse, drag to drop, file name,
               clear button and the round preview. */
            function initProfileZone() {
                var input = document.getElementById('profile_image');
                var zone = document.getElementById('profileImageZone');

                if (!input || !zone) {
                    return;
                }

                var nameLabel = document.getElementById('profileImageName');
                var clearButton = document.getElementById('profileImageClear');
                var preview = document.getElementById('profile_image_preview');
                var emptyText = nameLabel ? nameLabel.textContent : '';

                zone.classList.add('is-photo');

                function sync() {
                    var files = input.files;
                    var count = files ? files.length : 0;

                    zone.classList.toggle('has-file', count > 0);

                    if (nameLabel) {
                        if (count === 0) {
                            nameLabel.textContent = emptyText;
                            nameLabel.classList.remove('is-set');
                            nameLabel.removeAttribute('title');
                        } else {
                            nameLabel.textContent = files[0].name;
                            nameLabel.classList.add('is-set');
                            nameLabel.title = files[0].name;
                        }
                    }

                    if (clearButton) {
                        clearButton.hidden = count === 0;
                    }

                    if (!preview) {
                        return;
                    }

                    if (count === 0) {
                        preview.classList.remove('d-none');
                        preview.removeAttribute('src');
                        preview.style.display = 'none';
                        return;
                    }

                    var reader = new FileReader();

                    reader.onload = function (loadEvent) {
                        preview.classList.remove('d-none');
                        preview.src = loadEvent.target.result;
                        preview.style.display = 'block';
                    };

                    reader.readAsDataURL(files[0]);
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
                    transfer.items.add(dropped[0]);

                    try {
                        input.files = transfer.files;
                    } catch (error) {
                        return;
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
            document.addEventListener('DOMContentLoaded', function() {
                // Create date in Philippine time zone
                const philippinesTime = new Date().toLocaleString("en-US", {timeZone: "Asia/Manila"});
                const now = new Date(philippinesTime);
                
                // Format the date for date input (YYYY-MM-DD)
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                
                const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                document.getElementById('registration_date').value = day + ' ' + monthNames[now.getMonth()] + ' ' + year;
                
                // Profile image drop zone
                initProfileZone();
                
                // Contact number validation - only allow numbers
                document.getElementById('contact_number').addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
                
                // Password toggle function
            window.togglePassword = function (fieldId) {
                const field = document.getElementById(fieldId);
                const toggleButton = field.parentNode.querySelector('.password-toggle i');
                
                if (field.type === 'password') {
                    field.type = 'text';
                    toggleButton.classList.remove('fa-eye');
                    toggleButton.classList.add('fa-eye-slash');
                } else {
                    field.type = 'password';
                    toggleButton.classList.remove('fa-eye-slash');
                    toggleButton.classList.add('fa-eye');
                }
            };
                
                // Form validation
                document.getElementById('collectorRegistrationForm').addEventListener('submit', function(e) {
                    const employeeNumber = document.getElementById('employee_number').value;
                    const accountType = document.getElementById('account_type').value;
                    const contactNumber = document.getElementById('contact_number').value;
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    const username = document.getElementById('username').value;
                    const area = document.getElementById('area').value;
                    
                    // Get branch value - check both select and hidden input
                    let branch;
                    const branchSelect = document.getElementById('branch');
                    const branchHidden = document.querySelector('input[name="branch"][type="hidden"]');
                    
                    if (branchHidden) {
                        branch = branchHidden.value;
                    } else {
                        branch = branchSelect.value;
                    }
                    
                    // Validate employee number format
                    if (!/^[A-Z0-9\-]+$/.test(employeeNumber)) {
                        e.preventDefault();
                        toastError('Invalid Employee Number', 'Employee number must contain only uppercase letters, numbers, and hyphens');
                        return false;
                    }
                    
                    // Validate contact number format (11 digits starting with 09)
                    if (contactNumber.length !== 11 || !contactNumber.startsWith('09')) {
                        e.preventDefault();
                        toastError('Invalid Contact Number', 'Contact number must be exactly 11 digits starting with 09');
                        return false;
                    }
                    
                    // Validate password match for all account types
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        toastError('Password Mismatch', 'Password and Confirm Password do not match');
                        return false;
                    }
                    
                    // Validate password length for all account types
                    if (password.length < 6) {
                        e.preventDefault();
                        toastError('Weak Password', 'Password must be at least 6 characters long');
                        return false;
                    }
                    
                    // Validate username for all account types
                    if (username.length < 3) {
                        e.preventDefault();
                        toastError('Invalid Username', 'Username must be at least 3 characters long');
                        return false;
                    }
                    
                    // Validate area for Collectors
                    if (accountType === 'Collector' && !area) {
                        e.preventDefault();
                        toastError('Area Required', 'Area is required for Collector accounts');
                        return false;
                    }
                    
                    // Validate branch selection
                    if (!branch) {
                        e.preventDefault();
                        toastError('Branch Required', 'Please select a branch');
                        return false;
                    }
                    
                    // Validate other required fields
                    const requiredFields = [
                        'employee_number', 'last_name', 'first_name', 'address', 'status', 'gender', 
                        'birth_date', 'place_of_birth', 'account_status'
                    ];
                    
                    for (const fieldId of requiredFields) {
                        const field = document.getElementById(fieldId);
                        if (!field.value) {
                            e.preventDefault();
                            toastError('Missing Information', `Please fill all required fields (${fieldId.replace('_', ' ')} is empty)`);
                            return false;
                        }
                    }
                    
                    return true;
                });

                // Flash messages coming from PHP (see #collector-registration-data)
                if (pageCfg.success) {
                    window.PCToast.show('success', 'Collector registered', pageCfg.success);
                }

                if (pageCfg.error) {
                    window.PCToast.show('error', 'Registration failed', pageCfg.error);
                }
            });
    // Functions the markup calls from inline handlers.
    window.toggleFields = toggleFields;
})();
