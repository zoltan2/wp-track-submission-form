/**
 * Track Submission Form - Modern ES6+ JavaScript
 *
 * Features:
 * - Real-time validation
 * - Progress indicator
 * - Autosave to localStorage
 * - Accessibility improvements
 * - Loading states
 * - Error handling
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

(function() {
    'use strict';

    class TrackSubmissionForm {
        constructor(formElement) {
            this.form = formElement;
            this.submitButton = this.form.querySelector('#tsf-submit-btn');
            this.messageContainer = this.form.querySelector('#tsf-message');
            this.fields = {};
            this.validationRules = {};
            this.isSubmitting = false;

            this.init();
        }

        init() {
            this.setupFields();
            this.setupValidation();
            this.setupAutosave();
            this.setupSubmitHandler();
            this.setupAccessibility();
            this.loadDraft();
            this.updateProgress();
        }

        setupFields() {
            const fieldIds = [
                'artist', 'track_title', 'genre', 'duration', 'instrumental',
                'release_date', 'email', 'phone', 'platform', 'track_url',
                'social_url', 'type', 'label', 'country', 'description', 'optin'
            ];

            fieldIds.forEach(id => {
                const element = this.form.querySelector(`#${id}`);
                if (element) {
                    this.fields[id] = element;
                }
            });
        }

        setupValidation() {
            this.validationRules = {
                artist: {
                    required: true,
                    minLength: 2,
                    maxLength: 200,
                    pattern: /^[^<>{}]+$/,
                    message: tsfData.messages?.invalid_artist || 'Invalid artist name'
                },
                track_title: {
                    required: true,
                    minLength: 2,
                    maxLength: 200,
                    pattern: /^[^<>{}]+$/,
                    message: tsfData.messages?.invalid_track || 'Invalid track title'
                },
                email: {
                    required: true,
                    pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                    message: tsfData.messages?.invalid_email || 'Invalid email address'
                },
                phone: {
                    required: false,
                    pattern: /^[\d\s\-\(\)\+]{8,20}$/,
                    message: tsfData.messages?.invalid_phone || 'Invalid phone number'
                },
                duration: {
                    required: true,
                    pattern: /^[0-9]{1,3}:[0-5][0-9]$/,
                    message: tsfData.messages?.invalid_duration || 'Duration must be in mm:ss format'
                },
                track_url: {
                    required: true,
                    url: true,
                    message: tsfData.messages?.invalid_url || 'Invalid URL'
                },
                social_url: {
                    required: false,
                    url: true,
                    message: tsfData.messages?.invalid_url || 'Invalid URL'
                },
                description: {
                    required: true,
                    minLength: 10,
                    maxLength: 2000,
                    message: tsfData.messages?.invalid_description || 'Description must be 10-2000 characters'
                }
            };

            // Add real-time validation listeners
            Object.keys(this.fields).forEach(fieldId => {
                const field = this.fields[fieldId];
                if (!field) return;

                field.addEventListener('blur', () => this.validateField(fieldId));
                field.addEventListener('input', () => {
                    this.clearFieldError(fieldId);
                    this.updateProgress();
                });
            });
        }

        validateField(fieldId) {
            const field = this.fields[fieldId];
            const rules = this.validationRules[fieldId];

            if (!field || !rules) return true;

            const value = field.value.trim();

            // Required check
            if (rules.required && !value) {
                this.showFieldError(fieldId, `${this.getFieldLabel(fieldId)} is required`);
                return false;
            }

            // Skip other validations if field is empty and not required
            if (!value && !rules.required) return true;

            // Length checks
            if (rules.minLength && value.length < rules.minLength) {
                this.showFieldError(fieldId, `Minimum ${rules.minLength} characters required`);
                return false;
            }

            if (rules.maxLength && value.length > rules.maxLength) {
                this.showFieldError(fieldId, `Maximum ${rules.maxLength} characters allowed`);
                return false;
            }

            // Pattern check
            if (rules.pattern && !rules.pattern.test(value)) {
                this.showFieldError(fieldId, rules.message);
                return false;
            }

            // URL check
            if (rules.url && !this.isValidUrl(value)) {
                this.showFieldError(fieldId, rules.message);
                return false;
            }

            this.clearFieldError(fieldId);
            return true;
        }

        validateAll() {
            let isValid = true;

            Object.keys(this.validationRules).forEach(fieldId => {
                if (!this.validateField(fieldId)) {
                    isValid = false;
                }
            });

            return isValid;
        }

        showFieldError(fieldId, message) {
            const field = this.fields[fieldId];
            if (!field) return;

            // Remove existing error
            this.clearFieldError(fieldId);

            // Add error class
            field.classList.add('tsf-field-error');
            field.setAttribute('aria-invalid', 'true');

            // Create and add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'tsf-error-message';
            errorDiv.id = `${fieldId}-error`;
            errorDiv.textContent = message;
            errorDiv.setAttribute('role', 'alert');

            field.parentNode.appendChild(errorDiv);
            field.setAttribute('aria-describedby', `${fieldId}-error`);
        }

        clearFieldError(fieldId) {
            const field = this.fields[fieldId];
            if (!field) return;

            field.classList.remove('tsf-field-error');
            field.removeAttribute('aria-invalid');
            field.removeAttribute('aria-describedby');

            const errorDiv = field.parentNode.querySelector('.tsf-error-message');
            if (errorDiv) {
                errorDiv.remove();
            }
        }

        getFieldLabel(fieldId) {
            const field = this.fields[fieldId];
            if (!field) return fieldId;

            const label = this.form.querySelector(`label[for="${fieldId}"]`);
            return label ? label.textContent.replace('*', '').trim() : fieldId;
        }

        isValidUrl(string) {
            try {
                const url = new URL(string);
                return url.protocol === 'http:' || url.protocol === 'https:';
            } catch {
                return false;
            }
        }

        setupAutosave() {
            let autosaveTimeout;

            Object.values(this.fields).forEach(field => {
                if (!field) return;

                field.addEventListener('input', () => {
                    clearTimeout(autosaveTimeout);
                    autosaveTimeout = setTimeout(() => this.saveDraft(), 2000);
                });
            });
        }

        saveDraft() {
            const formData = this.getFormData();
            localStorage.setItem('tsf_draft', JSON.stringify(formData));
            this.showMessage('Draft saved automatically', 'info', 2000);
        }

        loadDraft() {
            const draft = localStorage.getItem('tsf_draft');
            if (!draft) return;

            try {
                const data = JSON.parse(draft);

                Object.keys(data).forEach(key => {
                    const field = this.fields[key];
                    if (!field) return;

                    if (field.type === 'checkbox') {
                        field.checked = data[key] === 1 || data[key] === true;
                    } else {
                        field.value = data[key] || '';
                    }
                });

                this.showMessage('Draft loaded from previous session', 'info', 3000);
            } catch (e) {
                // VUL-22 FIX: Remove console.error from production
            }
        }

        clearDraft() {
            localStorage.removeItem('tsf_draft');
        }

        getFormData() {
            const data = {
                action: 'tsf_submit',
                nonce: tsfData.nonce
            };

            Object.keys(this.fields).forEach(key => {
                const field = this.fields[key];
                if (!field) return;

                if (field.type === 'checkbox') {
                    data[key] = field.checked ? 1 : 0;
                } else {
                    data[key] = field.value;
                }
            });

            // Add honeypot
            const honeypot = this.form.querySelector('input[name="tsf_hp"]');
            if (honeypot) {
                data.tsf_hp = honeypot.value;
            }

            return data;
        }

        setupSubmitHandler() {
            this.form.addEventListener('submit', async (e) => {
                e.preventDefault();

                if (this.isSubmitting) return;

                // Validate all fields
                if (!this.validateAll()) {
                    this.showMessage('Please correct the errors above', 'error');
                    this.focusFirstError();
                    return;
                }

                this.isSubmitting = true;
                this.showLoading();

                try {
                    const response = await this.submitForm();

                    if (response.success) {
                        this.showMessage(response.data.message || 'Submission successful!', 'success');
                        this.clearDraft();

                        // Redirect if provided
                        if (response.data.redirect) {
                            setTimeout(() => {
                                window.location.href = response.data.redirect;
                            }, 1500);
                        } else {
                            this.form.reset();
                        }
                    } else {
                        this.showMessage(response.data.message || 'Submission failed', 'error');
                    }
                } catch (error) {
                    // VUL-22 FIX: Remove console.error from production
                    this.showMessage(error.message || 'An error occurred. Please try again.', 'error');
                } finally {
                    this.isSubmitting = false;
                    this.hideLoading();
                }
            });
        }

        async submitForm() {
            const formData = this.getFormData();

            const response = await fetch(tsfData.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(formData)
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            return await response.json();
        }

        showLoading() {
            this.submitButton.disabled = true;
            this.submitButton.classList.add('loading');
            this.submitButton.setAttribute('aria-busy', 'true');

            const originalText = this.submitButton.textContent;
            this.submitButton.setAttribute('data-original-text', originalText);
            this.submitButton.textContent = tsfData.messages.loading || 'Processing...';
        }

        hideLoading() {
            this.submitButton.disabled = false;
            this.submitButton.classList.remove('loading');
            this.submitButton.removeAttribute('aria-busy');

            const originalText = this.submitButton.getAttribute('data-original-text');
            if (originalText) {
                this.submitButton.textContent = originalText;
            }
        }

        showMessage(message, type = 'info', duration = 5000) {
            if (!this.messageContainer) return;

            this.messageContainer.textContent = message;
            this.messageContainer.className = `tsf-message tsf-message-${type}`;
            this.messageContainer.style.display = 'block';
            this.messageContainer.setAttribute('role', type === 'error' ? 'alert' : 'status');

            // Auto-hide for info messages
            if (type === 'info' && duration > 0) {
                setTimeout(() => {
                    this.messageContainer.style.display = 'none';
                }, duration);
            }

            // Scroll to message
            this.messageContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        updateProgress() {
            let progressBar = this.form.querySelector('.tsf-progress-bar');

            if (!progressBar) {
                progressBar = document.createElement('div');
                progressBar.className = 'tsf-progress-bar';
                // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
                const progressFill = document.createElement('div');
                progressFill.className = 'tsf-progress-fill';
                progressBar.appendChild(progressFill);
                this.form.insertBefore(progressBar, this.form.firstChild);
            }

            const requiredFields = Object.keys(this.validationRules).filter(
                key => this.validationRules[key].required
            );

            const filledFields = requiredFields.filter(key => {
                const field = this.fields[key];
                return field && field.value.trim() !== '';
            });

            const progress = (filledFields.length / requiredFields.length) * 100;
            const fill = progressBar.querySelector('.tsf-progress-fill');
            if (fill) {
                fill.style.width = `${progress}%`;
                fill.setAttribute('aria-valuenow', progress);
            }
        }

        focusFirstError() {
            const firstError = this.form.querySelector('.tsf-field-error');
            if (firstError) {
                firstError.focus();
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        setupAccessibility() {
            // Add ARIA labels
            this.form.setAttribute('novalidate', 'novalidate'); // Use custom validation
            this.form.setAttribute('aria-label', 'Track submission form');

            // Mark required fields
            Object.keys(this.validationRules).forEach(fieldId => {
                const field = this.fields[fieldId];
                const rules = this.validationRules[fieldId];

                if (field && rules.required) {
                    field.setAttribute('aria-required', 'true');
                }
            });
        }
    }

    // Initialize form when DOM is ready
    function initForm() {
        const form = document.getElementById('tsf-submission-form');
        if (form) {
            new TrackSubmissionForm(form);
        }
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initForm);
    } else {
        initForm();
    }

})();
