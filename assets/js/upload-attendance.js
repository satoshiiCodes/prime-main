/* ==========================================================================
   Prime City - Attendance upload behaviour
   DataTable setup, the employee picker, late-minute and status previews, the
   edit modal and flash alerts. Page data lives in #upload-attendance-data.
   ========================================================================== */

(function () {
    var pageConfigCache = null;

    var attendanceDataTable = null;

    /**
     * Page configuration from #upload-attendance-data.
     */
    function pageConfig() {
        if (pageConfigCache) { return pageConfigCache; }

        var node = document.getElementById('upload-attendance-data');

        if (!node) { pageConfigCache = {}; return pageConfigCache; }

        try {
            pageConfigCache = JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('[upload-attendance] Could not parse page configuration.', error);
            pageConfigCache = {};
        }

        return pageConfigCache;
    }

    const pageCfg = pageConfig();

    document.addEventListener('DOMContentLoaded', function() {
        const attendanceTable = document.getElementById('attendanceTable');
        if (attendanceTable) {
            const tbody = attendanceTable.querySelector('tbody');
            const hasDataRows = tbody && tbody.children.length > 0;
            
            let hasActualData = false;
            if (hasDataRows) {
                const firstRow = tbody.children[0];
                const firstCell = firstRow.querySelector('td');
                hasActualData = !(firstCell && firstCell.textContent.includes('No attendance records found'));
            }
            
            if (hasDataRows && hasActualData) {
                try {
                    const rows = attendanceTable.querySelectorAll('tbody tr');
                    const headerCols = attendanceTable.querySelectorAll('thead th').length;
                    
                    rows.forEach((row, index) => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length !== headerCols) {
                            console.warn(`Row ${index} has ${cells.length} columns, expected ${headerCols}`);
                        }
                    });
                    
                    const dataTable = new simpleDatatables.DataTable(attendanceTable, {
                        perPage: 10,
                        perPageSelect: [5, 10, 15, 20, 31],
                        searchable: true,
                        sortable: true,
                        fixedHeight: false,
                        labels: {
                            placeholder: "Search records...",
                            perPage: "{select} entries per page",
                            noRows: "No records found",
                            info: "Showing {start} to {end} of {rows} entries"
                        }
                    });
                    attendanceDataTable = dataTable;
                } catch (error) {
                    console.error('DataTable initialization error:', error);
                    const cardBody = attendanceTable.closest('.card-body');
                    if (cardBody) {
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-warning mt-3';
                        alertDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Table display optimized for better performance.';
                        cardBody.appendChild(alertDiv);
                    }
                }
            } else {
                console.log('No data rows to initialize DataTable');
            }
        }
        
        function employeeOption(option) {
            if (!option.id) { return option.text; }

            var $option = $(option.element);
            var name = $option.data('name') || option.text;
            var bio = $option.data('bio') || '';
            var type = $option.data('type') || '';
            var branch = $option.data('branch') || '';

            var bioLine = 'Bio#: ' + bio;

            if (type) { bioLine += ' - ' + type; }

            var $wrapper = $('<div class="pc-opt"></div>');
            $('<span class="pc-opt__name"></span>').text(name).appendTo($wrapper);

            if (bio) {
                $('<span class="pc-opt__meta"></span>').text(bioLine).appendTo($wrapper);
            }

            if (branch) {
                $('<span class="pc-opt__meta"></span>').text('Branch: ' + branch).appendTo($wrapper);
            }

            return $wrapper;
        }

        $('#employee_select').select2({
            dropdownParent: $('#addAttendanceModal'),
            placeholder: 'Search for an employee...',
            allowClear: true,
            width: '100%',
            containerCssClass: 'select2-container--bootstrap',
            templateResult: employeeOption,
            templateSelection: function (option) {
                if (!option.id) { return option.text; }

                var $option = $(option.element);

                return $option.data('name') || option.text;
            },
            matcher: function(params, data) {
                if ($.trim(params.term) === '') {
                    return data;
                }
                var term = params.term.toLowerCase();
                var element = data.element ? $(data.element) : null;
                var haystack = (data.text || '').toLowerCase();

                if (element) {
                    haystack += ' ' + String(element.data('bio') || '').toLowerCase();
                    haystack += ' ' + String(element.data('type') || '').toLowerCase();
                    haystack += ' ' + String(element.data('branch') || '').toLowerCase();
                }

                if (haystack.indexOf(term) > -1) {
                    return data;
                }
                return null;
            }
        });
        
        $('#employee_select').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var employeeNumber = $(this).val();
            var branch = selectedOption.data('branch') || '';
            
            $('#employee_number').val(employeeNumber);
            $('#department').val(branch);
            calculateLateMinutes();
        });
        
        function calculateLateMinutes() {
            const timeInValue = document.getElementById('time_in').value;
            const department = document.getElementById('department').value;
            const lateMinutesInput = document.getElementById('late_minutes');
            const lateMinutesInfo = document.getElementById('lateMinutesInfo');
            
            if (!timeInValue) {
                lateMinutesInput.value = 0;
                lateMinutesInfo.innerHTML = '';
                return;
            }
            
            const [hours, minutes] = timeInValue.split(':').map(Number);
            const timeInDate = new Date();
            timeInDate.setHours(hours, minutes, 0, 0);
            const cutoffDate = new Date();
            
            if (department.trim().toLowerCase() === 'abulug') {
                cutoffDate.setHours(8, 40, 0, 0);
            } else {
                cutoffDate.setHours(8, 30, 0, 0);
            }
            
            if (timeInDate > cutoffDate) {
                const diffMs = timeInDate - cutoffDate;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                lateMinutesInput.value = diffMinutes;
                const lateHours = Math.floor(diffMinutes / 60);
                const lateMins = diffMinutes % 60;
                if (lateHours > 0) {
                    lateMinutesInfo.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Late by ${lateHours} hour(s) and ${lateMins} minute(s)`;
                } else {
                    lateMinutesInfo.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Late by ${diffMinutes} minute(s)`;
                }
                lateMinutesInfo.style.color = '#dc3545';
            } else {
                lateMinutesInput.value = 0;
                const cutoffLabel = department.trim().toLowerCase() === 'abulug' ? '8:40 AM' : '8:30 AM';
                lateMinutesInfo.innerHTML = `<i class="fas fa-check-circle me-1" style="color: #28a745;"></i>On time (before or at ${cutoffLabel})`;
                lateMinutesInfo.style.color = '#28a745';
            }
        }
        
        function validateTimes() {
            const timeIn = document.getElementById('time_in').value;
            const timeOut = document.getElementById('time_out').value;
            const timeValidationError = document.getElementById('timeValidationError');
            const saveButton = document.getElementById('saveRecordBtn');
            const timeInField = document.getElementById('time_in');
            const timeOutField = document.getElementById('time_out');
            
            timeInField.classList.remove('is-invalid-time');
            timeOutField.classList.remove('is-invalid-time');
            
            if (!timeIn || !timeOut) {
                timeValidationError.style.display = 'none';
                saveButton.disabled = false;
                return true;
            }
            
            if (timeOut < timeIn) {
                timeValidationError.style.display = 'block';
                saveButton.disabled = true;
                timeInField.classList.add('is-invalid-time');
                timeOutField.classList.add('is-invalid-time');
                return false;
            } else {
                timeValidationError.style.display = 'none';
                saveButton.disabled = false;
                return true;
            }
        }
        
        const timeInInput = document.getElementById('time_in');
        const timeOutInput = document.getElementById('time_out');
        const statusBadge = document.getElementById('status_badge');
        
        function isHalfDayTimeJS(timeStr) {
            if (!timeStr) return false;
            const parts = timeStr.split(':');
            if (parts.length < 2) return false;
            const hours = parseInt(parts[0], 10);
            const minutes = parseInt(parts[1], 10);
            const total = (hours * 60) + minutes;
            return (total >= 720 && total <= 780);
        }
        
        function updateStatusDisplay() {
            const timeInVal = timeInInput.value;
            const timeOutVal = timeOutInput.value;
            const hasTimeIn = timeInVal !== '';
            const hasTimeOut = timeOutVal !== '';
            
            const timeInIsHalfday = isHalfDayTimeJS(timeInVal);
            const timeOutIsHalfday = isHalfDayTimeJS(timeOutVal);
            
            if (timeInIsHalfday || timeOutIsHalfday) {
                statusBadge.textContent = 'Halfday';
                statusBadge.className = 'badge badge-halfday';
            } else if (hasTimeIn && hasTimeOut) {
                statusBadge.textContent = 'Present';
                statusBadge.className = 'badge bg-success';
            } else if (hasTimeIn || hasTimeOut) {
                statusBadge.textContent = 'Halfday';
                statusBadge.className = 'badge badge-halfday';
            } else {
                statusBadge.textContent = 'Absent';
                statusBadge.className = 'badge bg-danger';
            }
            calculateLateMinutes();
            validateTimes();
        }
        
        if (timeInInput && timeOutInput) {
            timeInInput.addEventListener('change', updateStatusDisplay);
            timeInInput.addEventListener('blur', updateStatusDisplay);
            timeOutInput.addEventListener('change', updateStatusDisplay);
            timeOutInput.addEventListener('blur', updateStatusDisplay);
            timeInInput.addEventListener('input', validateTimes);
            timeOutInput.addEventListener('input', validateTimes);
        }
        
        document.getElementById('addAttendanceForm').addEventListener('submit', function(e) {
            if (!validateTimes()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Time Validation Error',
                    text: 'Time Out cannot be earlier than Time In when both are provided. Please adjust the times or clear one of them.',
                    confirmButtonText: 'OK'
                });
            }
        });
        
        if (pageCfg.isEditing) {
            document.getElementById('modalTitle').textContent = 'Edit Attendance Record';
            document.getElementById('saveBtnText').textContent = 'Update Record';
            var editModal = new bootstrap.Modal(document.getElementById('addAttendanceModal'));
            editModal.show();
            $('#employee_number').val(pageCfg.editUserId);
            updateStatusDisplay();
            calculateLateMinutes();
        } else {
            const dateInput = document.getElementById('date');
            if (dateInput && !dateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
        }
        
        $('#addAttendanceModal').on('hidden.bs.modal', function () {
            if (!pageCfg.isEditing) {
                $('#employee_select').val(null).trigger('change');
                $('#employee_number').val('');
                $('#department').val('');
                $('#time_in').val('');
                $('#time_out').val('');
                $('#late_minutes').val('0');
                document.getElementById('lateMinutesInfo').innerHTML = '';
                document.getElementById('timeValidationError').style.display = 'none';
                document.getElementById('time_in').classList.remove('is-invalid-time');
                document.getElementById('time_out').classList.remove('is-invalid-time');
                document.getElementById('saveRecordBtn').disabled = false;
                document.getElementById('modalTitle').textContent = 'Add Attendance Record';
                document.getElementById('saveBtnText').textContent = 'Save Record';
                updateStatusDisplay();
            } else {
                window.location.href = 'upload_attendance.php';
            }
        });
        
        if (pageCfg.alert) {
            Swal.fire({
                icon: pageCfg.alert.type,
                title: pageCfg.alert.title,
                text: pageCfg.alert.text,
                confirmButtonText: 'OK'
            }).then((result) => {
                if (pageCfg.alert.reload && result.isConfirmed) {
                    window.location.reload();
                }
            });
        }
        
        calculateLateMinutes();
    });

    function confirmDelete(id, name, date) {
        Swal.fire({
            title: 'Are you sure?',
            html: `You are about to delete the attendance record for <strong>${name}</strong> on <strong>${date}</strong>.<br><br>This action cannot be undone!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'upload_attendance.php?delete=' + id;
            }
        });
    }

    function clearTimeField(fieldId) {
        document.getElementById(fieldId).value = '';
        const event = new Event('change', { bubbles: true });
        document.getElementById(fieldId).dispatchEvent(event);
        const inputEvent = new Event('input', { bubbles: true });
        document.getElementById(fieldId).dispatchEvent(inputEvent);
    }

    // ---- Toolbar search ---------------------------------------------------

    (function initAttendanceSearch() {
        var field = document.getElementById('attendanceSearch');

        if (!field) { return; }

        field.addEventListener('input', function () {
            if (attendanceDataTable && typeof attendanceDataTable.search === 'function') {
                attendanceDataTable.search(this.value);
            }
        });
    })();
    // ---- Upload drop zone -------------------------------------------------

    (function initUploadDrop() {
        var drop = document.getElementById('uploadDrop');
        var input = document.getElementById('attendance_file');
        var chip = document.getElementById('uploadFileName');
        var clear = document.getElementById('uploadClear');

        if (!drop || !input) { return; }

        function showFile() {
            var file = input.files && input.files[0];
            var name = chip ? chip.querySelector('.upload-drop__name') : null;

            if (!file) {
                if (chip) { chip.hidden = true; }
                drop.classList.remove('has-file');
                return;
            }

            if (name) { name.textContent = file.name; }
            if (chip) { chip.hidden = false; }
            drop.classList.add('has-file');
        }

        input.addEventListener('change', showFile);

        ['dragenter', 'dragover'].forEach(function (evt) {
            drop.addEventListener(evt, function (e) {
                e.preventDefault();
                drop.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (evt) {
            drop.addEventListener(evt, function () {
                drop.classList.remove('is-dragover');
            });
        });

        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            drop.classList.remove('is-dragover');

            var files = e.dataTransfer && e.dataTransfer.files;

            if (!files || !files.length) { return; }

            try {
                var bucket = new DataTransfer();
                bucket.items.add(files[0]);
                input.files = bucket.files;
            } catch (err) {
                return;
            }

            showFile();
        });

        if (clear) {
            clear.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                input.value = '';
                showFile();
            });
        }

        showFile();
    })();

    // Called from inline handlers in the markup.
    window.confirmDelete = confirmDelete;
})();
