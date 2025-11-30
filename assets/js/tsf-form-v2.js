/**
 * TSF Form V2 - Multi-Step Form Handler
 * @since 3.1.0
 */

(function() {
    'use strict';

    // VUL-17 FIX: Security utility functions
    const TSFSecurity = {
        /**
         * Escape HTML to prevent XSS attacks
         * @param {string} text - Text to escape
         * @returns {string} Escaped HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Create element with safe text content
         * @param {string} tag - HTML tag name
         * @param {string} className - CSS class (optional)
         * @param {string} text - Text content (optional)
         * @returns {HTMLElement}
         */
        createElement(tag, className = '', text = '') {
            const el = document.createElement(tag);
            if (className) el.className = className;
            if (text) el.textContent = text;
            return el;
        }
    };

    class TSFFormV2 {
        constructor() {
            this.form = document.getElementById('tsf-multi-step-form');
            if (!this.form) return;

            this.currentStep = 1;
            this.totalSteps = 4;
            this.formData = {};
            this.autosaveTimer = null;

            // Multi-track upload state
            this.trackCount = 1;
            this.currentTrackUpload = 0;
            this.uploadedTracks = {};
            this.qcReport = null;

            this.init();
        }

        init() {
            this.setupNavigation();
            this.setupValidation();
            this.setupAutosave();
            this.checkAutosaveRestore();
            this.setupReleaseDatePicker();
            this.setupPlatformDetection();
            this.setupConditionalLogic();
            this.setupTrackRepeater();
            this.setupTrackVerification();
            this.setupMP3Upload();
            this.setupMultiTrackUpload();
            this.updateProgress();
        }

        // ==================== NAVIGATION ====================

        setupNavigation() {
            const prevBtn = document.getElementById('tsf-prev-btn');
            const nextBtn = document.getElementById('tsf-next-btn');
            const submitBtn = document.getElementById('tsf-submit-btn');

            if (prevBtn) prevBtn.addEventListener('click', () => this.prevStep());
            if (nextBtn) nextBtn.addEventListener('click', () => this.nextStep());
            if (submitBtn) submitBtn.addEventListener('click', (e) => this.submitForm(e));
        }

        async nextStep() {
            if (!await this.validateCurrentStep()) {
                return;
            }

            if (this.currentStep < this.totalSteps) {
                this.hideStep(this.currentStep);
                this.currentStep++;
                this.showStep(this.currentStep);
                this.updateProgress();
                this.updateNavigation();
                this.autosave();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        prevStep() {
            if (this.currentStep > 1) {
                this.hideStep(this.currentStep);
                this.currentStep--;
                this.showStep(this.currentStep);
                this.updateProgress();
                this.updateNavigation();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        showStep(step) {
            const stepEl = document.querySelector(`.tsf-form-step[data-step="${step}"]`);
            if (stepEl) {
                stepEl.classList.add('active');
                stepEl.style.display = 'block';
                // Focus first input
                const firstInput = stepEl.querySelector('input, select, textarea');
                if (firstInput) setTimeout(() => firstInput.focus(), 100);
            }

            // Update step indicators
            document.querySelectorAll('.tsf-step').forEach((el, index) => {
                if (index + 1 === step) {
                    el.classList.add('active');
                } else if (index + 1 < step) {
                    el.classList.add('completed');
                    el.classList.remove('active');
                } else {
                    el.classList.remove('active', 'completed');
                }
            });

            // Populate summary on step 4
            if (step === 4) {
                this.populateSummary();
            }
        }

        hideStep(step) {
            const stepEl = document.querySelector(`.tsf-form-step[data-step="${step}"]`);
            if (stepEl) {
                stepEl.classList.remove('active');
                stepEl.style.display = 'none';
            }
        }

        updateNavigation() {
            const prevBtn = document.getElementById('tsf-prev-btn');
            const nextBtn = document.getElementById('tsf-next-btn');
            const submitBtn = document.getElementById('tsf-submit-btn');

            if (prevBtn) prevBtn.style.display = this.currentStep > 1 ? 'inline-block' : 'none';
            if (nextBtn) nextBtn.style.display = this.currentStep < this.totalSteps ? 'inline-block' : 'none';
            if (submitBtn) submitBtn.style.display = this.currentStep === this.totalSteps ? 'inline-block' : 'none';
        }

        updateProgress() {
            const progress = (this.currentStep / this.totalSteps) * 100;
            const progressFill = document.querySelector('.tsf-progress-fill');
            const progressText = document.querySelector('.tsf-progress-text');
            const progressBar = document.querySelector('.tsf-progress-container');

            if (progressFill) progressFill.style.width = progress + '%';
            if (progressText) progressText.textContent = Math.round(progress) + '% ' + (tsfFormData.i18n.complete || 'Complete');
            if (progressBar) progressBar.setAttribute('aria-valuenow', progress);
        }

        // ==================== VALIDATION ====================

        setupValidation() {
            const inputs = this.form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('blur', () => this.validateField(input));
                input.addEventListener('input', () => {
                    clearTimeout(this.validationTimer);
                    this.validationTimer = setTimeout(() => this.validateField(input), 500);
                });
            });
        }

        async validateCurrentStep() {
            const currentStepEl = document.querySelector(`.tsf-form-step[data-step="${this.currentStep}"]`);
            if (!currentStepEl) return true;

            const inputs = currentStepEl.querySelectorAll('input[required], select[required], textarea[required]');
            let isValid = true;

            for (const input of inputs) {
                if (!await this.validateField(input)) {
                    isValid = false;
                }
            }

            return isValid;
        }

        async validateField(field) {
            const wrapper = field.closest('.tsf-field-wrapper');
            if (!wrapper) return true;

            const feedback = wrapper.querySelector('.tsf-validation-feedback');
            if (!feedback) return true;

            // VUL-17 FIX: Clear previous feedback safely
            feedback.textContent = '';
            wrapper.classList.remove('tsf-field-error', 'tsf-field-success', 'tsf-field-warning');

            // Check required
            if (field.hasAttribute('required') && !field.value.trim()) {
                const fieldName = field.closest('.tsf-field-wrapper')?.querySelector('.tsf-label')?.textContent?.replace('*', '').trim() || 'This field';
                this.showFieldError(wrapper, feedback, `${fieldName} is required to continue`);

                // Scroll to error if not visible
                if (!this.isElementInViewport(wrapper)) {
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                return false;
            }

            // Type-specific validation
            const type = field.type || field.tagName.toLowerCase();
            let isValid = true;

            switch(type) {
                case 'email':
                    isValid = this.validateEmail(field.value);
                    if (!isValid) this.showFieldError(wrapper, feedback, 'Please enter a valid email address (e.g., you@example.com)');
                    break;
                case 'url':
                    isValid = this.validateURL(field.value);
                    if (!isValid) this.showFieldError(wrapper, feedback, 'Please enter a complete URL starting with https://');
                    break;
                case 'tel':
                    if (field.value) {
                        isValid = this.validatePhone(field.value);
                        if (!isValid) this.showFieldError(wrapper, feedback, 'Please enter a valid phone number (at least 8 digits)');
                    }
                    break;
            }

            if (isValid && field.value) {
                this.showFieldSuccess(wrapper, feedback);
            }

            return isValid;
        }

        validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        validateURL(url) {
            try {
                new URL(url);
                return true;
            } catch {
                return false;
            }
        }

        validatePhone(phone) {
            return /^[\d\s\+\-\(\)]{8,}$/.test(phone);
        }

        showFieldError(wrapper, feedback, message) {
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            wrapper.classList.add('tsf-field-error');
            feedback.textContent = '';
            const span = TSFSecurity.createElement('span', 'tsf-error', `❌ ${message}`);
            feedback.appendChild(span);
        }

        showFieldSuccess(wrapper, feedback) {
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            wrapper.classList.add('tsf-field-success');
            feedback.textContent = '';
            const span = TSFSecurity.createElement('span', 'tsf-success', '✅ Looks good!');
            feedback.appendChild(span);
        }

        showFieldWarning(wrapper, feedback, message) {
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            wrapper.classList.add('tsf-field-warning');
            feedback.textContent = '';
            const span = TSFSecurity.createElement('span', 'tsf-warning', `⚠️ ${message}`);
            feedback.appendChild(span);
        }

        // ==================== AUTOSAVE ====================

        setupAutosave() {
            const inputs = this.form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', () => this.triggerAutosave());
            });
        }

        triggerAutosave() {
            clearTimeout(this.autosaveTimer);
            this.autosaveTimer = setTimeout(() => this.autosave(), 2000);
        }

        autosave() {
            const data = {
                step: this.currentStep,
                timestamp: Date.now(),
                fields: {}
            };

            // Save form fields, but skip file inputs
            const fields = this.form.querySelectorAll('input:not([type="file"]), select, textarea');
            fields.forEach(field => {
                const name = field.name;
                if (name && name !== 'tsf_hp' && name !== 'tsf_nonce') {
                    if (field.type === 'checkbox') {
                        data.fields[name] = field.checked ? '1' : '0';
                    } else if (field.type === 'radio') {
                        if (field.checked) {
                            data.fields[name] = field.value;
                        }
                    } else {
                        data.fields[name] = field.value;
                    }
                }
            });

            localStorage.setItem('tsf_autosave', JSON.stringify(data));

            // Show autosave indicator
            this.showAutosaveIndicator();
        }

        showAutosaveIndicator() {
            const indicator = document.getElementById('tsf-autosave-indicator');
            if (!indicator) return;

            // Show indicator
            indicator.classList.add('visible');

            // Hide after 2 seconds
            setTimeout(() => {
                indicator.classList.remove('visible');
            }, 2000);
        }

        checkAutosaveRestore() {
            const saved = localStorage.getItem('tsf_autosave');
            if (!saved) return;

            try {
                const data = JSON.parse(saved);
                const age = Date.now() - data.timestamp;

                // Only restore if less than 24 hours old
                if (age > 24 * 60 * 60 * 1000) {
                    localStorage.removeItem('tsf_autosave');
                    return;
                }

                if (confirm(tsfFormData.i18n.restore_draft || 'Restore your previous submission?')) {
                    this.restoreFormData(data);
                }
            } catch (e) {
                // VUL-22 FIX: Remove console.error from production
            }
        }

        restoreFormData(data) {
            Object.entries(data.fields).forEach(([key, value]) => {
                const field = this.form.querySelector(`[name="${key}"]`);
                if (field) {
                    // Skip file inputs - can't programmatically set file values for security reasons
                    if (field.type === 'file') {
                        return;
                    }

                    if (field.type === 'checkbox') {
                        field.checked = value === '1';
                    } else {
                        field.value = value;
                    }
                }
            });

            // Go to saved step
            if (data.step && data.step > 1) {
                this.currentStep = data.step;
                this.showStep(this.currentStep);
                this.updateProgress();
                this.updateNavigation();
            }
        }

        // ==================== RELEASE DATE PICKER ====================

        setupReleaseDatePicker() {
            this.currentMonth = new Date();
            this.selectedDate = null;
            this.selectedMethod = null;

            // Radio button listeners
            const statusRadios = document.querySelectorAll('[name="release_status"]');
            statusRadios.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    this.toggleQuickSelectGroup(e.target.value);
                });
            });

            // Quick select button listeners
            document.querySelectorAll('.tsf-quick-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    this.handleQuickSelect(e.target.closest('.tsf-quick-btn'));
                });
            });

            // Change date button
            const changeBtn = document.querySelector('.tsf-selected-date-change');
            if (changeBtn) {
                changeBtn.addEventListener('click', () => {
                    document.getElementById('tsf-selected-date-display').style.display = 'none';
                    const currentStatus = document.querySelector('[name="release_status"]:checked').value;
                    this.toggleQuickSelectGroup(currentStatus);
                });
            }

            // Initialize with first status selected
            this.toggleQuickSelectGroup('already_released');
        }

        toggleQuickSelectGroup(status) {
            // Hide all groups
            document.querySelectorAll('.tsf-quick-select-group').forEach(group => {
                group.style.display = 'none';
            });

            // Show selected group
            const selectedGroup = document.querySelector(`.tsf-quick-select-group[data-status="${status}"]`);
            if (selectedGroup) {
                selectedGroup.style.display = 'flex';
            }

            // Hide any open pickers
            this.closeDayPicker();
            this.closeMonthCalendar();
            this.closeDatePickerFallback();
        }

        handleQuickSelect(btn) {
            const action = btn.dataset.dateAction;
            const today = new Date();
            let selectedDate = null;
            let method = action;

            switch(action) {
                case 'today':
                    selectedDate = today;
                    break;
                case 'yesterday':
                    selectedDate = new Date(today);
                    selectedDate.setDate(selectedDate.getDate() - 1);
                    break;
                case 'tomorrow':
                    selectedDate = new Date(today);
                    selectedDate.setDate(selectedDate.getDate() + 1);
                    break;
                case 'this-week':
                    this.showDayPicker('this-week', today);
                    return;
                case 'next-week':
                    this.showDayPicker('next-week', today);
                    return;
                case 'this-month':
                    this.showMonthCalendar('this-month', today);
                    return;
                case 'last-month':
                    const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    this.showMonthCalendar('last-month', lastMonth);
                    return;
                case 'next-month':
                    const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, 1);
                    this.showMonthCalendar('next-month', nextMonth);
                    return;
                case '3-months':
                    selectedDate = new Date(today);
                    selectedDate.setMonth(selectedDate.getMonth() + 3);
                    break;
                case '6-months':
                    selectedDate = new Date(today);
                    selectedDate.setMonth(selectedDate.getMonth() + 6);
                    break;
                case '1-year':
                    selectedDate = new Date(today);
                    selectedDate.setFullYear(selectedDate.getFullYear() + 1);
                    break;
                case 'pick-date':
                    this.showDatePickerFallback();
                    return;
            }

            if (selectedDate) {
                this.confirmDate(selectedDate, method);
            }
        }

        showDayPicker(period, referenceDate) {
            const picker = document.getElementById('tsf-day-picker');
            const title = document.getElementById('tsf-day-picker-title');
            const daysContainer = document.getElementById('tsf-day-picker-days');

            let startDate, endDate;
            const today = new Date();

            if (period === 'this-week') {
                // Current week (Mon-Sun)
                const dayOfWeek = referenceDate.getDay();
                const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek; // Adjust to Monday
                startDate = new Date(referenceDate);
                startDate.setDate(startDate.getDate() + diff);
                endDate = new Date(startDate);
                endDate.setDate(endDate.getDate() + 6);
                title.textContent = 'Select a day this week';
            } else if (period === 'next-week') {
                const dayOfWeek = referenceDate.getDay();
                const diff = dayOfWeek === 0 ? 1 : 8 - dayOfWeek; // Next Monday
                startDate = new Date(referenceDate);
                startDate.setDate(startDate.getDate() + diff);
                endDate = new Date(startDate);
                endDate.setDate(endDate.getDate() + 6);
                title.textContent = 'Select a day next week';
            }

            // VUL-17 FIX: Generate day buttons safely
            daysContainer.textContent = '';
            const current = new Date(startDate);
            while (current <= endDate) {
                const dayBtn = document.createElement('button');
                dayBtn.type = 'button';
                dayBtn.className = 'tsf-day-btn';

                const dayName = current.toLocaleDateString('en-US', { weekday: 'short' });
                const dayNum = current.getDate();
                const isToday = current.toDateString() === today.toDateString();

                // VUL-17 FIX: Use safe DOM manipulation
                const nameSpan = TSFSecurity.createElement('span', 'tsf-day-name', dayName);
                const numSpan = TSFSecurity.createElement('span', 'tsf-day-num', dayNum.toString());
                dayBtn.appendChild(nameSpan);
                dayBtn.appendChild(numSpan);

                if (isToday) {
                    dayBtn.classList.add('today');
                }

                // Store date for click handler
                const dateToSelect = new Date(current);
                dayBtn.addEventListener('click', () => {
                    this.confirmDate(dateToSelect, period);
                    this.closeDayPicker();
                });

                daysContainer.appendChild(dayBtn);
                current.setDate(current.getDate() + 1);
            }

            picker.style.display = 'block';

            // Close button
            const closeBtn = picker.querySelector('.tsf-day-picker-close');
            if (closeBtn) {
                closeBtn.onclick = () => this.closeDayPicker();
            }
        }

        closeDayPicker() {
            const picker = document.getElementById('tsf-day-picker');
            if (picker) picker.style.display = 'none';
        }

        showMonthCalendar(period, referenceDate) {
            const calendar = document.getElementById('tsf-month-calendar');
            const title = document.getElementById('tsf-month-calendar-title');
            const grid = document.getElementById('tsf-month-calendar-grid');

            this.currentMonth = new Date(referenceDate.getFullYear(), referenceDate.getMonth(), 1);
            this.renderMonthCalendar();

            if (period === 'this-month') {
                title.textContent = 'Select a day this month';
            } else if (period === 'last-month') {
                title.textContent = 'Select a day last month';
            } else if (period === 'next-month') {
                title.textContent = 'Select a day next month';
            }

            calendar.style.display = 'block';

            // Navigation buttons
            calendar.querySelectorAll('.tsf-month-nav').forEach(btn => {
                btn.onclick = (e) => {
                    const nav = e.target.dataset.nav;
                    if (nav === 'prev') {
                        this.currentMonth.setMonth(this.currentMonth.getMonth() - 1);
                    } else {
                        this.currentMonth.setMonth(this.currentMonth.getMonth() + 1);
                    }
                    this.renderMonthCalendar();
                };
            });

            // Close button
            const closeBtn = calendar.querySelector('.tsf-month-calendar-close');
            if (closeBtn) {
                closeBtn.onclick = () => this.closeMonthCalendar();
            }
        }

        renderMonthCalendar() {
            const grid = document.getElementById('tsf-month-calendar-grid');
            const title = document.getElementById('tsf-month-calendar-title');

            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            const monthName = this.currentMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

            title.textContent = monthName;

            // VUL-17 FIX: Clear grid safely
            grid.textContent = '';

            // Add day headers
            const dayHeaders = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            dayHeaders.forEach(day => {
                const header = document.createElement('div');
                header.className = 'tsf-calendar-day-header';
                header.textContent = day;
                grid.appendChild(header);
            });

            // Calculate first day of month (0 = Sunday, adjust to Monday = 0)
            const firstDay = new Date(year, month, 1);
            let dayOfWeek = firstDay.getDay();
            dayOfWeek = dayOfWeek === 0 ? 6 : dayOfWeek - 1; // Adjust to Monday = 0

            // Add empty cells for days before month starts
            for (let i = 0; i < dayOfWeek; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'tsf-calendar-day empty';
                grid.appendChild(emptyCell);
            }

            // Add day cells
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();

            for (let day = 1; day <= daysInMonth; day++) {
                const dayBtn = document.createElement('button');
                dayBtn.type = 'button';
                dayBtn.className = 'tsf-calendar-day';
                dayBtn.textContent = day;

                const dateToCheck = new Date(year, month, day);
                if (dateToCheck.toDateString() === today.toDateString()) {
                    dayBtn.classList.add('today');
                }

                // Store date for click handler
                const dateToSelect = new Date(year, month, day);
                dayBtn.addEventListener('click', () => {
                    this.confirmDate(dateToSelect, 'calendar');
                    this.closeMonthCalendar();
                });

                grid.appendChild(dayBtn);
            }
        }

        closeMonthCalendar() {
            const calendar = document.getElementById('tsf-month-calendar');
            if (calendar) calendar.style.display = 'none';
        }

        showDatePickerFallback() {
            const fallback = document.getElementById('tsf-date-picker-fallback');
            const input = document.getElementById('tsf-date-picker-input');

            fallback.style.display = 'block';
            input.focus();

            // Confirm button
            const confirmBtn = fallback.querySelector('.tsf-date-picker-confirm');
            confirmBtn.onclick = () => {
                if (input.value) {
                    const date = new Date(input.value + 'T00:00:00');
                    this.confirmDate(date, 'pick-date');
                    this.closeDatePickerFallback();
                }
            };

            // Cancel button
            const cancelBtn = fallback.querySelector('.tsf-date-picker-cancel');
            cancelBtn.onclick = () => this.closeDatePickerFallback();
        }

        closeDatePickerFallback() {
            const fallback = document.getElementById('tsf-date-picker-fallback');
            if (fallback) fallback.style.display = 'none';
        }

        confirmDate(date, method) {
            this.selectedDate = date;
            this.selectedMethod = method;

            // Format date as YYYY-MM-DD
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const formattedDate = `${year}-${month}-${day}`;

            // Set hidden inputs
            document.getElementById('tsf-release-date-hidden').value = formattedDate;
            document.getElementById('tsf-release-date-method').value = method;

            // Update display
            const displayText = date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('tsf-selected-date-text').textContent = displayText;

            // Show selected date, hide quick select
            document.querySelectorAll('.tsf-quick-select-group').forEach(group => {
                group.style.display = 'none';
            });
            document.getElementById('tsf-selected-date-display').style.display = 'block';

            // Close any open pickers
            this.closeDayPicker();
            this.closeMonthCalendar();
            this.closeDatePickerFallback();
        }

        // ==================== CONDITIONAL LOGIC ====================

        setupConditionalLogic() {
            // Track repeater is always visible (no conditional logic needed)
            // Type is auto-determined

            // Show/hide label manager fields based on label type
            const labelField = document.querySelector('[name="label"]');
            if (labelField) {
                labelField.addEventListener('change', (e) => {
                    this.toggleLabelManager(e.target.value);
                });
                // Initialize on page load
                this.toggleLabelManager(labelField.value);
            }
        }

        toggleLabelManager(labelType) {
            const section = document.getElementById('tsf-label-manager-section');
            if (!section) return;

            const isLabel = labelType && labelType.toLowerCase() === 'label';
            section.style.display = isLabel ? 'block' : 'none';

            // Only label_manager_name and label_manager_email are required when visible
            const requiredFields = section.querySelectorAll('[name="label_manager_name"], [name="label_manager_email"]');
            const optionalFields = section.querySelectorAll('[name="label_manager_phone"], [name="label_manager_website"], [name="label_vat"]');

            if (isLabel) {
                // Make only name and email required
                requiredFields.forEach(field => field.setAttribute('required', 'required'));
                // Keep others optional
                optionalFields.forEach(field => field.removeAttribute('required'));
            } else {
                // Remove required from all fields when hidden
                section.querySelectorAll('[name^="label_manager_"]').forEach(field => {
                    field.removeAttribute('required');
                    field.value = ''; // Clear values when hidden
                });
            }
        }

        // ==================== TRACK REPEATER ====================

        setupTrackRepeater() {
            this.tracks = [];
            this.trackCount = 0;
            this.maxTracks = 20;

            const addBtn = document.getElementById('tsf-add-track-btn');
            if (addBtn) {
                addBtn.addEventListener('click', () => this.addTrack());
            }

            // Always start with 1 track
            this.addTrack();

            // Auto-classify type on form submission (before submit)
            this.form.addEventListener('submit', (e) => {
                this.autoClassifyReleaseType();
            });
        }

        // Auto-classify release type based on track count and duration
        autoClassifyReleaseType() {
            const tracks = document.querySelectorAll('.tsf-track-row');
            const trackCount = tracks.length;
            let totalDuration = 0;

            // Calculate total duration from all tracks
            // Note: Duration is auto-extracted from MP3 upload
            const durationField = document.getElementById('tsf-duration-hidden');
            if (durationField && durationField.value) {
                // Parse duration format "MM:SS" to seconds
                const parts = durationField.value.split(':');
                if (parts.length === 2) {
                    totalDuration = (parseInt(parts[0]) * 60) + parseInt(parts[1]);
                }
            }

            const totalDurationMinutes = totalDuration / 60;
            let releaseType = 'Single'; // Default

            /**
             * Classification Rules:
             * SINGLE: 1 track, total < 30 minutes
             * EP:
             *   - Case 1: 1-3 tracks with at least one ≥10min, total <30min
             *   - Case 2: 4-6 tracks, total <30min
             * ALBUM: 7+ tracks OR total ≥30min
             */

            if (trackCount >= 7 || totalDurationMinutes >= 30) {
                releaseType = 'Album';
            } else if (trackCount >= 4 && trackCount <= 6 && totalDurationMinutes < 30) {
                releaseType = 'EP';
            } else if (trackCount >= 1 && trackCount <= 3) {
                // Check if any track is ≥10 minutes
                // For now, assume EP if 2-3 tracks
                if (trackCount > 1) {
                    releaseType = 'EP';
                } else {
                    releaseType = 'Single';
                }
            }

            // Set hidden type field
            const typeField = document.getElementById('tsf-type-hidden');
            if (typeField) {
                typeField.value = releaseType;
            }

            // VUL-22 FIX: Remove console.log from production
        }

        addTrack() {
            if (this.trackCount >= this.maxTracks) {
                this.showMessage(`Maximum ${this.maxTracks} tracks allowed`, 'error');
                return;
            }

            this.trackCount++;
            const trackIndex = this.trackCount;

            const container = document.getElementById('tsf-tracks-container');
            if (!container) return;

            const trackRow = document.createElement('div');
            trackRow.className = 'tsf-track-row';
            trackRow.dataset.trackIndex = trackIndex;

            // VUL-17 FIX: Escape dynamic content to prevent XSS
            // trackIndex is a number but we escape it for safety
            const safeIndex = TSFSecurity.escapeHtml(trackIndex.toString());

            trackRow.innerHTML = `
                <div class="tsf-track-row-header">
                    <div class="tsf-track-row-title">
                        <span class="tsf-track-row-number">${safeIndex}</span>
                        Track ${safeIndex}
                    </div>
                    <button type="button" class="tsf-track-remove-btn" data-track-index="${safeIndex}">
                        <span>×</span> Remove
                    </button>
                </div>
                <div class="tsf-track-fields">
                    <div class="tsf-field-wrapper">
                        <label class="tsf-label">Track Title <span class="tsf-required">*</span></label>
                        <input
                            type="text"
                            name="tracks[${safeIndex}][title]"
                            class="tsf-input"
                            required
                            placeholder="Enter track title"
                        />
                    </div>
                    <div class="tsf-field-wrapper">
                        <label class="tsf-label">ISRC Code</label>
                        <input
                            type="text"
                            name="tracks[${safeIndex}][isrc]"
                            class="tsf-input"
                            placeholder="USXXX1234567"
                            pattern="[A-Z]{2}[A-Z0-9]{3}[0-9]{7}"
                        />
                        <div class="tsf-field-hint">Optional - International Standard Recording Code</div>
                    </div>
                    <div class="tsf-field-wrapper">
                        <label class="tsf-label">Instrumental? <span class="tsf-required">*</span></label>
                        <div class="tsf-radio-group">
                            <label class="tsf-radio-option">
                                <input
                                    type="radio"
                                    name="tracks[${safeIndex}][instrumental]"
                                    value="no"
                                    checked
                                    required
                                />
                                <span>No</span>
                            </label>
                            <label class="tsf-radio-option">
                                <input
                                    type="radio"
                                    name="tracks[${safeIndex}][instrumental]"
                                    value="yes"
                                    required
                                />
                                <span>Yes</span>
                            </label>
                        </div>
                        <div class="tsf-field-hint">Select Yes if this track has no vocals</div>
                    </div>
                </div>
            `;

            container.appendChild(trackRow);

            // Add remove event listener
            const removeBtn = trackRow.querySelector('.tsf-track-remove-btn');
            removeBtn.addEventListener('click', () => this.removeTrack(trackIndex));

            // Update UI
            this.updateTrackCount();
            this.updateAddButtonState();

            // Animate in
            trackRow.style.animation = 'tsf-slide-down 0.3s ease';

            return trackRow;
        }

        removeTrack(trackIndex) {
            const trackRow = document.querySelector(`.tsf-track-row[data-track-index="${trackIndex}"]`);
            if (!trackRow) return;

            // Animate out
            trackRow.style.animation = 'tsf-fade-out 0.2s ease';
            setTimeout(() => {
                trackRow.remove();
                this.trackCount--;
                this.renumberTracks();
                this.updateTrackCount();
                this.updateAddButtonState();
            }, 200);
        }

        renumberTracks() {
            const tracks = document.querySelectorAll('.tsf-track-row');
            tracks.forEach((track, index) => {
                const newIndex = index + 1;
                track.dataset.trackIndex = newIndex;

                // Update display number
                const numberEl = track.querySelector('.tsf-track-row-number');
                if (numberEl) numberEl.textContent = newIndex;

                const titleEl = track.querySelector('.tsf-track-row-title');
                if (titleEl) titleEl.lastChild.textContent = ` Track ${newIndex}`;

                // Update input names
                track.querySelectorAll('input').forEach(input => {
                    const name = input.name;
                    if (name) {
                        input.name = name.replace(/tracks\[\d+\]/, `tracks[${newIndex}]`);
                    }
                });

                // Update remove button
                const removeBtn = track.querySelector('.tsf-track-remove-btn');
                if (removeBtn) removeBtn.dataset.trackIndex = newIndex;
            });
        }

        updateTrackCount() {
            const badge = document.getElementById('tsf-track-count');
            if (badge) {
                badge.textContent = this.trackCount;
            }
        }

        updateAddButtonState() {
            const addBtn = document.getElementById('tsf-add-track-btn');
            const limitNotice = document.getElementById('tsf-track-limit-notice');

            if (this.trackCount >= this.maxTracks) {
                if (addBtn) addBtn.disabled = true;
                if (limitNotice) limitNotice.style.display = 'block';
            } else {
                if (addBtn) addBtn.disabled = false;
                if (limitNotice) limitNotice.style.display = 'none';
            }
        }

        // ==================== PLATFORM AUTO-DETECTION ====================

        setupPlatformDetection() {
            const urlField = document.querySelector('[name="track_url"]');
            if (!urlField) return;

            urlField.addEventListener('input', (e) => {
                this.detectPlatform(e.target.value);
            });

            urlField.addEventListener('blur', (e) => {
                this.detectPlatform(e.target.value);
            });
        }

        detectPlatform(url) {
            const platformField = document.getElementById('tsf-platform-hidden');
            const badge = document.getElementById('tsf-platform-badge');
            const iconEl = badge?.querySelector('.tsf-platform-icon');
            const nameEl = badge?.querySelector('.tsf-platform-name');

            if (!url || !platformField || !badge) return;

            const platforms = {
                'spotify': { pattern: /spotify\.com\/(?:intl-[a-z]{2}\/)?track/i, icon: '🎵', name: 'Spotify' },
                'soundcloud': { pattern: /soundcloud\.com/i, icon: '🔊', name: 'SoundCloud' },
                'youtube': { pattern: /youtube\.com\/watch|youtu\.be/i, icon: '▶️', name: 'YouTube' },
                'apple': { pattern: /music\.apple\.com/i, icon: '🍎', name: 'Apple Music' },
                'deezer': { pattern: /deezer\.com/i, icon: '🎧', name: 'Deezer' },
                'bandcamp': { pattern: /bandcamp\.com/i, icon: '🎸', name: 'Bandcamp' },
                'tidal': { pattern: /tidal\.com/i, icon: '🌊', name: 'TIDAL' }
            };

            let detected = null;
            for (const [key, platform] of Object.entries(platforms)) {
                if (platform.pattern.test(url)) {
                    detected = { key, ...platform };
                    break;
                }
            }

            if (detected) {
                platformField.value = detected.key;
                if (iconEl) iconEl.textContent = detected.icon;
                if (nameEl) nameEl.textContent = detected.name + ' detected';
                badge.style.display = 'inline-flex';
            } else {
                platformField.value = 'other';
                badge.style.display = 'none';
            }
        }

        // ==================== TRACK VERIFICATION ====================

        setupTrackVerification() {
            const verifyBtn = document.getElementById('tsf-verify-track');
            if (verifyBtn) {
                verifyBtn.addEventListener('click', () => this.verifyTrack());
            }
        }

        async verifyTrack() {
            const platformField = document.getElementById('tsf-platform-hidden');
            const urlField = document.querySelector('[name="track_url"]');

            if (!urlField || !urlField.value) {
                this.showMessage('Please enter a track URL first', 'error');
                return;
            }

            // Auto-detect platform if not already detected
            if (!platformField || !platformField.value) {
                this.detectPlatform(urlField.value);
            }

            // Check again after detection
            if (!platformField || !platformField.value) {
                this.showMessage('Could not detect platform from URL. Please check the URL format.', 'error');
                return;
            }

            // VUL-22 FIX: Remove console.log from production

            const verifyBtn = document.getElementById('tsf-verify-track');
            verifyBtn.disabled = true;
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            verifyBtn.textContent = '';
            const spinner = document.createElement('span');
            spinner.className = 'tsf-spinner';
            verifyBtn.appendChild(spinner);
            verifyBtn.appendChild(document.createTextNode(' ' + (tsfFormData.i18n.verifying || 'Verifying...')));

            try {
                const response = await fetch(tsfFormData.rest_url + 'verify-track', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': tsfFormData.rest_nonce
                    },
                    body: JSON.stringify({
                        platform: platformField.value,
                        url: urlField.value
                    })
                });

                // Check if response is OK before parsing
                if (!response.ok) {
                    const errorText = await response.text();
                    // VUL-22 FIX: Remove console.error from production
                    throw new Error(`API Error: ${response.status} - ${response.statusText}`);
                }

                const data = await response.json();
                // VUL-22 FIX: Remove console.log from production

                if (data.success) {
                    // VUL-22 FIX: Remove console.log from production
                    this.showTrackPreview(data.data);
                } else {
                    // VUL-22 FIX: Remove console.error from production
                    this.showMessage(data.message || 'Could not verify track', 'error');
                }
            } catch (error) {
                // VUL-22 FIX: Remove console.error from production
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                this.showMessage('Error verifying track: ' + errorMsg, 'error');
            } finally {
                verifyBtn.disabled = false;
                // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
                verifyBtn.textContent = '';
                const icon = document.createElement('span');
                icon.className = 'tsf-btn-icon';
                icon.textContent = '🔍';
                verifyBtn.appendChild(icon);
                verifyBtn.appendChild(document.createTextNode(' Verify Track'));
            }
        }

        showTrackPreview(data) {
            const preview = document.getElementById('tsf-track-preview');
            if (!preview) return;

            preview.style.display = 'block';
            preview.querySelector('.tsf-preview-cover').src = data.cover || '';
            preview.querySelector('.tsf-preview-title').textContent = data.title || '';
            preview.querySelector('.tsf-preview-artist').textContent = data.artist || '';
            preview.querySelector('.tsf-preview-album').textContent = data.album || '';

            // Store verified track title in hidden field for submission
            if (data.title) {
                let trackTitleInput = document.getElementById('tsf-verified-track-title');
                if (!trackTitleInput) {
                    trackTitleInput = document.createElement('input');
                    trackTitleInput.type = 'hidden';
                    trackTitleInput.id = 'tsf-verified-track-title';
                    trackTitleInput.name = 'verified_track_title';
                    document.querySelector('.tsf-form-v2').appendChild(trackTitleInput);
                }
                trackTitleInput.value = data.title;
            }

            // Auto-populate artist field if verified and field is empty
            if (data.artist) {
                const artistField = document.querySelector('[name="artist"]');
                if (artistField && !artistField.value) {
                    artistField.value = data.artist;
                }
            }

            const statusEl = preview.querySelector('.tsf-preview-status');
            if (data.match_score >= 80) {
                // VUL-17 FIX: Use textContent instead of innerHTML
                statusEl.textContent = '✅ Track verified!';
                statusEl.className = 'tsf-preview-status tsf-status-success';
            } else {
                // VUL-17 FIX: Use textContent instead of innerHTML
                statusEl.textContent = '⚠️ Info mismatch - please review';
                statusEl.className = 'tsf-preview-status tsf-status-warning';
            }
        }

        // ==================== MP3 UPLOAD ====================

        setupMP3Upload() {
            const uploadArea = document.getElementById('tsf-mp3-upload-area');
            const fileInput = document.getElementById('tsf-mp3-file');

            if (!uploadArea || !fileInput) return;

            // Drag & drop
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('tsf-upload-dragover');
            });

            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('tsf-upload-dragover');
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('tsf-upload-dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    this.analyzeMP3(files[0]);
                }
            });

            // Click to upload
            uploadArea.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    this.analyzeMP3(e.target.files[0]);
                }
            });
        }

        async analyzeMP3(file) {
            if (!file || !file.name.endsWith('.mp3')) {
                this.showMessage('Please upload an MP3 file', 'error');
                return;
            }

            if (file.size > 50 * 1024 * 1024) {
                this.showMessage('File too large (max 50MB)', 'error');
                return;
            }

            const uploadArea = document.getElementById('tsf-mp3-upload-area');
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            uploadArea.textContent = '';
            const spinnerDiv = document.createElement('div');
            spinnerDiv.className = 'tsf-spinner';
            const analyzingText = document.createElement('p');
            analyzingText.textContent = tsfFormData.i18n.analyzing || 'Analyzing...';
            uploadArea.appendChild(spinnerDiv);
            uploadArea.appendChild(analyzingText);

            try {
                const formData = new FormData();
                formData.append('mp3_file', file);

                const response = await fetch(tsfFormData.rest_url + 'analyze-mp3', {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': tsfFormData.rest_nonce
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    this.qcReport = data.data;
                    this.showQualityScore(data.data);

                    // Store MP3 file path for submission
                    if (data.data.temp_file_path && data.data.filename) {
                        // Make sure we're appending to the actual form element
                        const form = document.querySelector('form.tsf-form-v2') || document.querySelector('.tsf-form-v2');

                        let filePathInput = document.getElementById('tsf-mp3-file-path');
                        if (!filePathInput) {
                            filePathInput = document.createElement('input');
                            filePathInput.type = 'hidden';
                            filePathInput.id = 'tsf-mp3-file-path';
                            filePathInput.name = 'mp3_file_path';
                            if (form) form.appendChild(filePathInput);
                        }
                        filePathInput.value = data.data.temp_file_path;

                        let filenameInput = document.getElementById('tsf-mp3-filename');
                        if (!filenameInput) {
                            filenameInput = document.createElement('input');
                            filenameInput.type = 'hidden';
                            filenameInput.id = 'tsf-mp3-filename';
                            filenameInput.name = 'mp3_filename';
                            if (form) form.appendChild(filenameInput);
                        }
                        filenameInput.value = data.data.filename;

                        // Debug: log to verify fields are created
                        if (window.console && window.console.log) {
                            console.log('✅ MP3 fields created:', {
                                path: filePathInput.value,
                                filename: filenameInput.value,
                                inForm: form && form.contains(filePathInput)
                            });
                        }
                    }

                    // VUL-17 FIX: Use safe DOM manipulation + escape filename
                    uploadArea.textContent = '';
                    const successDiv = document.createElement('div');
                    successDiv.className = 'tsf-upload-success';
                    successDiv.textContent = '✅ File analyzed: ' + file.name;
                    uploadArea.appendChild(successDiv);
                } else {
                    throw new Error(data.message || 'Analysis failed');
                }
            } catch (error) {
                // VUL-22 FIX: Remove console.error from production
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                this.showMessage('Error analyzing MP3: ' + errorMsg, 'error');
                // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
                uploadArea.textContent = '';
                const iconDiv = document.createElement('div');
                iconDiv.className = 'tsf-upload-icon';
                iconDiv.textContent = '📁';
                const h3 = document.createElement('h3');
                h3.textContent = 'Upload MP3 for Analysis';
                const p = document.createElement('p');
                p.textContent = 'Try again';
                uploadArea.appendChild(iconDiv);
                uploadArea.appendChild(h3);
                uploadArea.appendChild(p);
            }
        }

        showQualityScore(data) {
            const scoreCard = document.getElementById('tsf-quality-score');
            if (!scoreCard) return;

            scoreCard.style.display = 'block';

            // Auto-populate hidden fields from MP3 analysis
            if (data.audio) {
                // Populate duration
                const durationField = document.getElementById('tsf-duration-hidden');
                if (durationField && data.audio.duration_formatted) {
                    durationField.value = data.audio.duration_formatted;
                }

                // Populate instrumental
                const instrumentalField = document.getElementById('tsf-instrumental-hidden');
                if (instrumentalField && typeof data.audio.instrumental !== 'undefined') {
                    instrumentalField.value = data.audio.instrumental ? 'Yes' : 'No';
                }
            }

            // Update score circle
            const circle = scoreCard.querySelector('.tsf-score-fill');
            const valueEl = scoreCard.querySelector('.tsf-score-value');
            const score = data.total_score || 0;

            if (circle) {
                const circumference = 2 * Math.PI * 45;
                const offset = circumference - (score / 100) * circumference;
                circle.style.strokeDasharray = circumference;
                circle.style.strokeDashoffset = offset;
            }

            if (valueEl) valueEl.textContent = score + '%';

            // Update categories
            const categories = scoreCard.querySelectorAll('.tsf-score-category');
            if (categories[0]) categories[0].querySelector('.tsf-score-points').textContent = (data.metadata_score || 0) + '/40';
            if (categories[1]) categories[1].querySelector('.tsf-score-points').textContent = (data.audio_score || 0) + '/30';
            if (categories[2]) categories[2].querySelector('.tsf-score-points').textContent = (data.professional_score || 0) + '/30';

            // Display audio information
            if (data.audio) {
                const audioInfoHtml = `
                    <div class="tsf-audio-info">
                        <h4>🎵 Audio Information</h4>
                        <div class="tsf-audio-details">
                            <div class="tsf-audio-detail">
                                <span class="tsf-label">Bitrate:</span>
                                <span class="tsf-value">${data.audio.bitrate} kbps ${data.audio.bitrate_mode ? '(' + data.audio.bitrate_mode.toUpperCase() + ')' : ''}</span>
                            </div>
                            <div class="tsf-audio-detail">
                                <span class="tsf-label">Duration:</span>
                                <span class="tsf-value">${data.audio.duration_formatted}</span>
                            </div>
                            <div class="tsf-audio-detail">
                                <span class="tsf-label">Sample Rate:</span>
                                <span class="tsf-value">${(data.audio.sample_rate / 1000).toFixed(1)} kHz</span>
                            </div>
                            <div class="tsf-audio-detail">
                                <span class="tsf-label">Channels:</span>
                                <span class="tsf-value">${data.audio.channels == 2 ? 'Stereo' : data.audio.channels == 1 ? 'Mono' : data.audio.channels}</span>
                            </div>
                            <div class="tsf-audio-detail">
                                <span class="tsf-label">File Size:</span>
                                <span class="tsf-value">${data.audio.filesize_formatted}</span>
                            </div>
                        </div>
                    </div>
                `;

                // Insert audio info before recommendations
                let audioInfoEl = scoreCard.querySelector('.tsf-audio-info');
                if (!audioInfoEl) {
                    const recommendationsEl = scoreCard.querySelector('.tsf-score-recommendations');
                    if (recommendationsEl) {
                        recommendationsEl.insertAdjacentHTML('beforebegin', audioInfoHtml);
                    } else {
                        scoreCard.insertAdjacentHTML('beforeend', audioInfoHtml);
                    }
                } else {
                    audioInfoEl.outerHTML = audioInfoHtml;
                }
            }

            // Display metadata information
            if (data.metadata) {
                const metadataInfoHtml = `
                    <div class="tsf-metadata-info">
                        <h4>📋 ID3 Tags</h4>
                        <div class="tsf-metadata-details">
                            <div class="tsf-metadata-detail ${data.metadata.artist ? 'tsf-has-value' : 'tsf-missing-value'}">
                                <span class="tsf-label">Artist:</span>
                                <span class="tsf-value">${data.metadata.artist || '❌ Missing'}</span>
                            </div>
                            <div class="tsf-metadata-detail ${data.metadata.title ? 'tsf-has-value' : 'tsf-missing-value'}">
                                <span class="tsf-label">Title:</span>
                                <span class="tsf-value">${data.metadata.title || '❌ Missing'}</span>
                            </div>
                            <div class="tsf-metadata-detail ${data.metadata.album ? 'tsf-has-value' : 'tsf-missing-value'}">
                                <span class="tsf-label">Album:</span>
                                <span class="tsf-value">${data.metadata.album || '❌ Missing'}</span>
                            </div>
                            <div class="tsf-metadata-detail ${data.metadata.year ? 'tsf-has-value' : 'tsf-missing-value'}">
                                <span class="tsf-label">Year:</span>
                                <span class="tsf-value">${data.metadata.year || '❌ Missing'}</span>
                            </div>
                            <div class="tsf-metadata-detail ${data.metadata.has_cover ? 'tsf-has-value' : 'tsf-missing-value'}">
                                <span class="tsf-label">Artwork:</span>
                                <span class="tsf-value">${data.metadata.has_cover ? '✅ Present' : '❌ Missing'}</span>
                            </div>
                        </div>
                    </div>
                `;

                // Insert metadata info before audio info
                let metadataInfoEl = scoreCard.querySelector('.tsf-metadata-info');
                const audioInfoEl = scoreCard.querySelector('.tsf-audio-info');
                if (!metadataInfoEl) {
                    if (audioInfoEl) {
                        audioInfoEl.insertAdjacentHTML('beforebegin', metadataInfoHtml);
                    } else {
                        const recommendationsEl = scoreCard.querySelector('.tsf-score-recommendations');
                        if (recommendationsEl) {
                            recommendationsEl.insertAdjacentHTML('beforebegin', metadataInfoHtml);
                        } else {
                            scoreCard.insertAdjacentHTML('beforeend', metadataInfoHtml);
                        }
                    }
                } else {
                    metadataInfoEl.outerHTML = metadataInfoHtml;
                }
            }

            // VUL-17 FIX: Show recommendations safely without innerHTML
            const recommendations = scoreCard.querySelector('.tsf-score-recommendations');
            if (recommendations) {
                recommendations.textContent = '';

                let hasContent = false;

                if (data.missing_tags && data.missing_tags.length > 0) {
                    hasContent = true;
                    const h4 = document.createElement('h4');
                    h4.textContent = '⚠️ Missing ID3 Tags:';
                    const ul = document.createElement('ul');
                    ul.className = 'tsf-missing-list';
                    data.missing_tags.forEach(tag => {
                        const li = document.createElement('li');
                        li.textContent = tag; // Safe - no HTML injection
                        ul.appendChild(li);
                    });
                    recommendations.appendChild(h4);
                    recommendations.appendChild(ul);
                }

                if (data.recommendations && data.recommendations.length > 0) {
                    hasContent = true;
                    const h4 = document.createElement('h4');
                    h4.textContent = '💡 Recommendations:';
                    const ul = document.createElement('ul');
                    ul.className = 'tsf-recommendations-list';
                    data.recommendations.forEach(rec => {
                        const li = document.createElement('li');
                        li.textContent = rec; // Safe - no HTML injection
                        ul.appendChild(li);
                    });
                    recommendations.appendChild(h4);
                    recommendations.appendChild(ul);
                }

                if (!hasContent) {
                    const successDiv = document.createElement('div');
                    successDiv.className = 'tsf-success-message';
                    successDiv.textContent = '✅ Your MP3 file is perfectly tagged and optimized!';
                    recommendations.appendChild(successDiv);
                }

                recommendations.style.display = 'block';
            }
        }

        // ==================== MULTI-TRACK UPLOAD ====================

        setupMultiTrackUpload() {
            const trackCountField = document.querySelector('[name="track_count"]');
            if (!trackCountField) return;

            // Listen for track count changes
            trackCountField.addEventListener('change', (e) => {
                this.trackCount = parseInt(e.target.value) || 1;
                // VUL-22 FIX: Remove console.log from production

                // Reset upload state when track count changes
                this.currentTrackUpload = 0;
                this.uploadedTracks = {};
            });

            // Initialize track count
            this.trackCount = parseInt(trackCountField.value) || 1;
        }

        showMultiTrackUploadProgress() {
            const uploadArea = document.getElementById('tsf-mp3-upload-area');
            if (!uploadArea) return;

            const progressHtml = `
                <div class="tsf-multi-track-progress">
                    <h4>Track ${this.currentTrackUpload} of ${this.trackCount}</h4>
                    <div class="tsf-progress-bar">
                        <div class="tsf-progress-fill" style="width: ${(this.currentTrackUpload / this.trackCount) * 100}%"></div>
                    </div>
                    <p>Upload MP3 for track ${this.currentTrackUpload}</p>
                </div>
            `;

            // Only show progress if multi-track (more than 1)
            if (this.trackCount > 1 && this.currentTrackUpload > 0) {
                uploadArea.insertAdjacentHTML('afterbegin', progressHtml);
            }
        }

        // ==================== FORM SUBMISSION ====================

        async submitForm(e) {
            e.preventDefault();

            if (!await this.validateCurrentStep()) {
                return;
            }

            // Enforce MP3 analysis if server requires it. If server allows a fallback,
            // prompt the user and mark the submission as skipped when confirmed.
            if (tsfFormData.require_mp3_analysis && !this.qcReport) {
                if (tsfFormData.allow_submission_without_mp3) {
                    const proceed = confirm('MP3 analysis has not completed. Do you want to proceed without analysis?');
                    if (!proceed) {
                        this.showMessage('Please upload and analyze your MP3 before submitting.', 'error');
                        return;
                    }

                    // Add a hidden flag to mark that the user chose to skip analysis
                    let skipInput = this.form.querySelector('input[name="mp3_analysis_skipped"]');
                    if (!skipInput) {
                        skipInput = document.createElement('input');
                        skipInput.type = 'hidden';
                        skipInput.name = 'mp3_analysis_skipped';
                        skipInput.value = '1';
                        this.form.appendChild(skipInput);
                    } else {
                        skipInput.value = '1';
                    }
                } else {
                    this.showMessage('Please upload and analyze your MP3 before submitting.', 'error');
                    return;
                }
            }

            const submitBtn = document.getElementById('tsf-submit-btn');
            submitBtn.disabled = true;
            // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
            submitBtn.textContent = '';
            const spinner = document.createElement('span');
            spinner.className = 'tsf-spinner';
            submitBtn.appendChild(spinner);
            submitBtn.appendChild(document.createTextNode(' ' + (tsfFormData.i18n.saving || 'Submitting...')));

            const formData = new FormData(this.form);
            formData.append('action', 'tsf_submit_v2');

            // Include QC report if available
            if (this.qcReport) {
                formData.append('qc_report', JSON.stringify(this.qcReport));
            }

            try {
                const response = await fetch(tsfFormData.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                // VUL-22 FIX: Remove console.log from production

                if (data.success) {
                    localStorage.removeItem('tsf_autosave');
                    const message = (data.data && data.data.message) || data.message || 'Track submitted successfully!';
                    this.showMessage(message, 'success');

                    const redirect = (data.data && data.data.redirect) || data.redirect;
                    if (redirect) {
                        setTimeout(() => {
                            window.location.href = redirect;
                        }, 1500);
                    }
                } else {
                    const errorMessage = (data.data && data.data.message) || data.message || 'Submission failed';
                    // If current user is allowed to bypass rate limiting, show rate-limit messages as info
                    if (tsfFormData.is_admin && /please wait/i.test(errorMessage)) {
                        this.showMessage(errorMessage, 'info');
                    } else {
                        this.showMessage(errorMessage, 'error');
                    }
                    submitBtn.disabled = false;
                    // VUL-17 FIX: Use textContent instead of innerHTML
                    submitBtn.textContent = 'Submit Track ✓';
                }
            } catch (error) {
                // VUL-22 FIX: Remove console.error from production
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                this.showMessage('Error: ' + errorMsg, 'error');
                submitBtn.disabled = false;
                // VUL-17 FIX: Use textContent instead of innerHTML
                submitBtn.textContent = 'Submit Track ✓';
            }
        }

        // ==================== SUMMARY ====================

        populateSummary() {
            const summaryContent = document.querySelector('.tsf-summary-content');
            if (!summaryContent) return;

            const formData = new FormData(this.form);
            const summary = [];

            // Track info
            const artist = formData.get('artist') || 'Not provided';
            // Try multiple sources for track title
            let trackTitle = formData.get('verified_track_title');
            if (!trackTitle || trackTitle.trim() === '') {
                trackTitle = formData.get('album_title');
            }
            if (!trackTitle || trackTitle.trim() === '') {
                // Try to get first track from tracks array (starts from 1, not 0)
                trackTitle = formData.get('tracks[1][title]');
            }
            if (!trackTitle || trackTitle.trim() === '') {
                // Fallback to checking DOM directly
                const firstTrackField = this.form.querySelector('input[name="tracks[1][title]"]');
                if (firstTrackField && firstTrackField.value.trim()) {
                    trackTitle = firstTrackField.value;
                }
            }
            trackTitle = trackTitle || 'Not provided';

            const genre = formData.get('genre') || 'Not provided';
            const platform = formData.get('platform') || 'Not detected';
            const trackUrl = formData.get('track_url') || 'Not provided';

            summary.push(`<div class="tsf-summary-section">
                <h4>📀 Track Information</h4>
                <p><strong>Artist:</strong> ${this.escapeHtml(artist)}</p>
                <p><strong>Track:</strong> ${this.escapeHtml(trackTitle)}</p>
                <p><strong>Genre:</strong> ${this.escapeHtml(genre)}</p>
                <p><strong>Platform:</strong> ${this.escapeHtml(platform)}</p>
                <p><strong>URL:</strong> <a href="${this.escapeHtml(trackUrl)}" target="_blank">${this.escapeHtml(trackUrl)}</a></p>
            </div>`);

            // Contact info
            const email = formData.get('email') || 'Not provided';
            const phone = formData.get('phone') || 'Not provided';
            const country = formData.get('country') || 'Not selected';
            const label = formData.get('label') || 'Not selected';

            summary.push(`<div class="tsf-summary-section">
                <h4>📧 Contact Information</h4>
                <p><strong>Email:</strong> ${this.escapeHtml(email)}</p>
                <p><strong>Phone:</strong> ${this.escapeHtml(phone)}</p>
                <p><strong>Country:</strong> ${this.escapeHtml(country)}</p>
                <p><strong>Label:</strong> ${this.escapeHtml(label)}</p>
            </div>`);

            // MP3 upload status and quality score
            const mp3Filename = formData.get('mp3_filename');
            const qcReportJson = formData.get('qc_report');

            if (mp3Filename) {
                let mp3Section = `<div class="tsf-summary-section">
                    <h4>🎵 MP3 File</h4>
                    <p><strong>Status:</strong> <span style="color: #059669;">✅ Uploaded & Analyzed</span></p>
                    <p><strong>File:</strong> ${this.escapeHtml(mp3Filename)}</p>`;

                // Add quality score if available (with proper validation to prevent XSS)
                if (qcReportJson) {
                    try {
                        const qcReport = JSON.parse(qcReportJson);
                        // Validate score is actually a number
                        const score = parseInt(qcReport.quality_score, 10);
                        if (!isNaN(score) && score >= 0 && score <= 100) {
                            let scoreColor = '#dc3232'; // Red for low scores
                            if (score >= 80) scoreColor = '#46b450'; // Green for high scores
                            else if (score >= 60) scoreColor = '#f0b23e'; // Yellow for medium scores

                            mp3Section += `<p><strong>Quality Score:</strong> <span style="color: ${scoreColor}; font-weight: 600; font-size: 16px;">${score}%</span></p>`;

                            // Add score breakdown with validation
                            const metaScore = parseInt(qcReport.metadata_score, 10);
                            const audioScore = parseInt(qcReport.audio_score, 10);
                            const profScore = parseInt(qcReport.professional_score, 10);

                            if (!isNaN(metaScore) && !isNaN(audioScore) && !isNaN(profScore)) {
                                mp3Section += `<p style="font-size: 13px; color: #666;">
                                    Metadata: ${metaScore}/40 |
                                    Audio: ${audioScore}/30 |
                                    Professional: ${profScore}/30
                                </p>`;
                            }
                        }
                    } catch (e) {
                        console.error('Error parsing QC report:', e);
                    }
                }

                mp3Section += `</div>`;
                summary.push(mp3Section);
            }

            summaryContent.innerHTML = summary.join('');
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ==================== UTILITIES ====================

        isElementInViewport(el) {
            const rect = el.getBoundingClientRect();
            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );
        }

        showMessage(message, type = 'info') {
            const messageEl = document.getElementById('tsf-form-message');
            if (!messageEl) return;

            messageEl.textContent = message;
            messageEl.className = 'tsf-form-message tsf-message-' + type;
            messageEl.style.display = 'block';

            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 5000);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new TSFFormV2());
    } else {
        new TSFFormV2();
    }

})();
