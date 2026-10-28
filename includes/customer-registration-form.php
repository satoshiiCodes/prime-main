<?php
/**
 * Prime City - Customer registration form fields.
 *
 * Rendered by customer_registration.php (full page) and by the "Add New
 * Customer" modal in customer_list.php, so both stay in step.
 *
 * Expects:
 *   @var array<int, string> $available_branches Branch options.
 *   @var bool               $branch_disabled    Branch locked to the user's own.
 *   @var string             $selected_branch    Locked branch name.
 */
?>
<!-- Hidden field for registration date -->
<input type="hidden" name="registration_date" value="<?php echo date('Y-m-d H:i:s'); ?>">

<!-- Personal Information Section -->
<div class="form-section">
    <h5 class="section-title">Personal Information</h5>

    <div class="profile-upload">
        <span class="upload__label" id="profileImageLabel">Profile Image</span>
        <label class="upload" for="profile_image" id="profileImageZone" aria-labelledby="profileImageLabel">
            <input
                type="file"
                class="upload__input"
                id="profile_image"
                name="profile_image"
                accept="image/*"
            >
            <img id="profile_image_preview" class="preview-image" src="#" alt="Selected profile photo">
            <span class="upload__icon"><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i></span>
            <span class="upload__title">Drop a photo here or click to browse</span>
            <span class="upload__hint">JPG, JPEG, PNG or GIF &middot; up to 5 MB</span>
        </label>
        <div class="upload__meta">
            <span class="upload__name" id="profileImageName">No file selected</span>
            <button type="button" class="upload__clear" id="profileImageClear" hidden>Remove</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last Name" required>
                <label for="last_name" class="required-field">Last Name</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" required>
                <label for="first_name" class="required-field">First Name</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Middle Name">
                <label for="middle_name">Middle Name</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <select class="form-select" id="suffix" name="suffix">
                    <option value="None">None</option>
                    <option value="Jr">Jr</option>
                    <option value="Sr">Sr</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                </select>
                <label for="suffix">Suffix</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <select class="form-select" id="gender" name="gender">
                    <option value="">Select Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
                <label for="gender">Gender</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <select class="form-select" id="marital_status" name="marital_status">
                    <option value="">Select Status</option>
                    <option value="Single">Single</option>
                    <option value="Married">Married</option>
                    <option value="Widow">Widow</option>
                    <option value="Separated">Separated</option>
                </select>
                <label for="marital_status">Status</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" placeholder="Date of Birth">
                <label for="date_of_birth">Date of Birth</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" placeholder="Place of Birth">
                <label for="place_of_birth">Place of Birth</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" id="spouse_name" name="spouse_name" placeholder="Spouse Name">
                <label for="spouse_name">Spouse Name</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" id="spouse_occupation" name="spouse_occupation" placeholder="Spouse Occupation">
                <label for="spouse_occupation">Spouse Occupation</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating">
                <select class="form-select" id="area" name="area" required>
                    <option value="">Select Area</option>
                    <option value="Area 1">Area 1</option>
                    <option value="Area 2">Area 2</option>
                    <option value="Area 3">Area 3</option>
                    <option value="Area 4">Area 4</option>
                    <option value="Area 5">Area 5</option>
                    <option value="Area 6">Area 6</option>
                    <option value="Area 7">Area 7</option>
                    <option value="Area 8">Area 8</option>
                    <option value="Area 9">Area 9</option>
                    <option value="Area 10">Area 10</option>
                </select>
                <label for="area">Area</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating">
                <select class="form-select" id="branch" name="branch" 
                    <?php echo $branch_disabled ? 'disabled' : ''; ?> required>
                    <option value="">Select Branch</option>
                    <?php foreach ($available_branches as $branch): ?>
                        <option value="<?php echo $branch; ?>" 
                            <?php echo ($branch_disabled && $branch === $selected_branch) ? 'selected' : ''; ?>>
                            <?php echo $branch; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($branch_disabled): ?>
                    <input type="hidden" name="branch" value="<?php echo $selected_branch; ?>">
                <?php endif; ?>
                <label for="branch" class="required-field">Branch</label>
                <?php if ($branch_disabled): ?>
                    <small class="text-muted">Branch is automatically set to your assigned branch (<?php echo $selected_branch; ?>)</small>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating">
                <select class="form-select" id="status" name="status">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Pending">Pending</option>
                    <option value="Suspended">Suspended</option>
                </select>
                <label for="status">Account Status</label>
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-floating">
                <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                    pattern="[0-9]{11}" maxlength="11" 
                    oninput="this.value = this.value.replace(/[^0-9]/g, ''); validateContactNumber(this.value);" 
                    placeholder="Contact Number" required>
                <label for="contact_number" class="required-field">Contact Number</label>
            </div>
            <small class="text-muted">Must be 11 digits starting with "09"</small>
            <div id="contactNumberError" class="contact-number-error">
                Contact number must be 11 digits starting with 09
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-floating">
                <input type="tel" class="form-control" id="contact_number_2" name="contact_number_2" 
                    pattern="[0-9]{11}" maxlength="11" 
                    oninput="this.value = this.value.replace(/[^0-9]/g, ''); validateContactNumber2(this.value);"
                    placeholder="Contact Number #2">
                <label for="contact_number_2">Contact Number #2</label>
            </div>
            <small class="text-muted">Optional - must be 11 digits starting with "09"</small>
            <div id="contactNumber2Error" class="contact-number-error">
                Contact number #2 must be 11 digits starting with 09
            </div>
        </div>

        <!-- Upload Requirements Section -->
        <div class="col-12">
            <span class="upload__label" id="requirementsLabel">Requirements</span>
            <label class="upload" for="requirements" id="requirementsZone" aria-labelledby="requirementsLabel">
                <input
                    type="file"
                    class="upload__input"
                    id="requirements"
                    name="requirements[]"
                    multiple
                    accept=".doc,.docx,.xls,.xlsx,.pdf,image/*"
                >
                <span class="upload__icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                <span class="upload__title">Drop documents here or click to browse</span>
                <span class="upload__hint">Word, Excel, PDF or images &middot; up to 20 MB each</span>
            </label>
            <div class="upload__meta">
                <span class="upload__name" id="requirementsName">No files selected</span>
                <button type="button" class="upload__clear" id="requirementsClear" hidden>Clear all</button>
            </div>
            <div id="fileList" class="file-list"></div>
        </div>
    </div>
</div>

<!-- Contact Information Section -->
<div class="form-section">
    <h5 class="section-title">Contact Information</h5>
    
    <!-- Permanent Address Section -->
    <div class="address-section">
        <div class="address-title">Permanent Address</div>
        <div class="row g-3">
            <div class="col-md-2">
                <div class="form-floating">
                    <input type="text" class="form-control" id="house_number" name="house_number" placeholder="No.">
                    <label for="house_number">No.</label>
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-floating">
                    <input type="text" class="form-control" id="street" name="street" placeholder="Street" required>
                    <label for="street" class="required-field">Street</label>
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-floating">
                    <input type="text" class="form-control" id="building" name="building" placeholder="Floor/Building">
                    <label for="building">Floor/Building</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="barangay" name="barangay" placeholder="Barangay" required>
                    <label for="barangay" class="required-field">Barangay</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="municipality" name="municipality" placeholder="Municipality/City" required>
                    <label for="municipality" class="required-field">Municipality/City</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="province" name="province" placeholder="Province" required>
                    <label for="province" class="required-field">Province</label>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Present Address Section -->
    <div class="address-section">
        <div class="form-check mb-2">
            <label class="form-check-label address-title" for="sameAsPermanent">
                Present Address (if different)
            </label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="sameAsPermanent" name="sameAsPermanent">
            <div class="calculation-note">(Same as Permanent Address)</div>
        </div>
        
        <div class="row g-3" id="presentAddressFields">
            <div class="col-md-2">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_house_number" name="present_house_number" placeholder="No.">
                    <label for="present_house_number">No.</label>
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_street" name="present_street" placeholder="Street">
                    <label for="present_street">Street</label>
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_building" name="present_building" placeholder="Floor/Building">
                    <label for="present_building">Floor/Building</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_barangay" name="present_barangay" placeholder="Barangay">
                    <label for="present_barangay">Barangay</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_municipality" name="present_municipality" placeholder="Municipality/City">
                    <label for="present_municipality">Municipality/City</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="present_province" name="present_province" placeholder="Province">
                    <label for="present_province">Province</label>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Employment Information Section -->
<div class="form-section">
    <h5 class="section-title">Employment Information</h5>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" id="occupation" name="occupation" placeholder="Occupation">
                <label for="occupation">Occupation</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" id="employer_business_name" name="employer_business_name" placeholder="Employer/Business Name">
                <label for="employer_business_name">Employer/Business Name</label>
            </div>
        </div>
        <div class="col-12">
            <div class="form-floating">
                <input type="text" class="form-control" id="nature_of_work" name="nature_of_work" placeholder="Nature of Work">
                <label for="nature_of_work">Nature of Work</label>
            </div>
        </div>
    </div>
</div>

<!-- Account Credentials Section -->
<div class="form-section">
    <h5 class="section-title">Account Credentials</h5>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                <label for="username" class="required-field">Username</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating password-container">
                <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                <label for="password" class="required-field">Password</label>
                <span class="password-toggle" onclick="togglePassword('password')">
                    <i class="fas fa-eye"></i>
                </span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-floating password-container">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                <label for="confirm_password" class="required-field">Confirm Password</label>
                <span class="password-toggle" onclick="togglePassword('confirm_password')">
                    <i class="fas fa-eye"></i>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Notes Section -->
<div class="form-section">
    <h5 class="section-title">Additional Notes</h5>
    <div class="row g-3">
        <div class="col-12">
            <div class="form-floating">
                <textarea class="form-control" id="notes" name="notes" placeholder="Notes" style="height: 100px"></textarea>
                <label for="notes">Notes</label>
            </div>
        </div>
    </div>
</div>
