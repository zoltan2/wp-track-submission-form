/**
 * TSF Country Select - Searchable country dropdown with ISO codes
 * @package TrackSubmissionForm
 * @since 3.2.0
 */

const TSF_COUNTRIES = [
    // Priority countries (top music markets)
    { code: 'US', name: 'United States', flag: '🇺🇸' },
    { code: 'GB', name: 'United Kingdom', flag: '🇬🇧' },
    { code: 'CA', name: 'Canada', flag: '🇨🇦' },
    { code: 'AU', name: 'Australia', flag: '🇦🇺' },

    // EU Countries (alphabetical)
    { code: 'AT', name: 'Austria', flag: '🇦🇹' },
    { code: 'BE', name: 'Belgium', flag: '🇧🇪' },
    { code: 'BG', name: 'Bulgaria', flag: '🇧🇬' },
    { code: 'HR', name: 'Croatia', flag: '🇭🇷' },
    { code: 'CY', name: 'Cyprus', flag: '🇨🇾' },
    { code: 'CZ', name: 'Czech Republic', flag: '🇨🇿' },
    { code: 'DK', name: 'Denmark', flag: '🇩🇰' },
    { code: 'EE', name: 'Estonia', flag: '🇪🇪' },
    { code: 'FI', name: 'Finland', flag: '🇫🇮' },
    { code: 'FR', name: 'France', flag: '🇫🇷' },
    { code: 'DE', name: 'Germany', flag: '🇩🇪' },
    { code: 'GR', name: 'Greece', flag: '🇬🇷' },
    { code: 'HU', name: 'Hungary', flag: '🇭🇺' },
    { code: 'IE', name: 'Ireland', flag: '🇮🇪' },
    { code: 'IT', name: 'Italy', flag: '🇮🇹' },
    { code: 'LV', name: 'Latvia', flag: '🇱🇻' },
    { code: 'LT', name: 'Lithuania', flag: '🇱🇹' },
    { code: 'LU', name: 'Luxembourg', flag: '🇱🇺' },
    { code: 'MT', name: 'Malta', flag: '🇲🇹' },
    { code: 'NL', name: 'Netherlands', flag: '🇳🇱' },
    { code: 'PL', name: 'Poland', flag: '🇵🇱' },
    { code: 'PT', name: 'Portugal', flag: '🇵🇹' },
    { code: 'RO', name: 'Romania', flag: '🇷🇴' },
    { code: 'SK', name: 'Slovakia', flag: '🇸🇰' },
    { code: 'SI', name: 'Slovenia', flag: '🇸🇮' },
    { code: 'ES', name: 'Spain', flag: '🇪🇸' },
    { code: 'SE', name: 'Sweden', flag: '🇸🇪' },

    // Other major countries (alphabetical)
    { code: 'AR', name: 'Argentina', flag: '🇦🇷' },
    { code: 'BR', name: 'Brazil', flag: '🇧🇷' },
    { code: 'CL', name: 'Chile', flag: '🇨🇱' },
    { code: 'CN', name: 'China', flag: '🇨🇳' },
    { code: 'CO', name: 'Colombia', flag: '🇨🇴' },
    { code: 'IN', name: 'India', flag: '🇮🇳' },
    { code: 'ID', name: 'Indonesia', flag: '🇮🇩' },
    { code: 'IL', name: 'Israel', flag: '🇮🇱' },
    { code: 'JP', name: 'Japan', flag: '🇯🇵' },
    { code: 'MY', name: 'Malaysia', flag: '🇲🇾' },
    { code: 'MX', name: 'Mexico', flag: '🇲🇽' },
    { code: 'NZ', name: 'New Zealand', flag: '🇳🇿' },
    { code: 'NO', name: 'Norway', flag: '🇳🇴' },
    { code: 'PH', name: 'Philippines', flag: '🇵🇭' },
    { code: 'RU', name: 'Russia', flag: '🇷🇺' },
    { code: 'SG', name: 'Singapore', flag: '🇸🇬' },
    { code: 'ZA', name: 'South Africa', flag: '🇿🇦' },
    { code: 'KR', name: 'South Korea', flag: '🇰🇷' },
    { code: 'CH', name: 'Switzerland', flag: '🇨🇭' },
    { code: 'TH', name: 'Thailand', flag: '🇹🇭' },
    { code: 'TR', name: 'Turkey', flag: '🇹🇷' },
    { code: 'AE', name: 'United Arab Emirates', flag: '🇦🇪' },
    { code: 'VN', name: 'Vietnam', flag: '🇻🇳' },
];

class TSFCountrySelect {
    constructor(element) {
        this.wrapper = element;
        this.searchInput = element.querySelector('.tsf-country-search');
        this.hiddenInput = element.querySelector('.tsf-country-value');
        this.dropdown = element.querySelector('.tsf-country-dropdown');
        this.selected = element.querySelector('.tsf-country-selected');
        this.focusedIndex = -1;

        if (!this.searchInput || !this.hiddenInput || !this.dropdown || !this.selected) {
            // VUL-22 FIX: Remove console.error from production
            return;
        }

        this.init();
    }

    init() {
        this.renderDropdown(TSF_COUNTRIES);
        this.bindEvents();
        this.detectUserCountry();
    }

    renderDropdown(filteredCountries) {
        // VUL-17 FIX: Use safe DOM manipulation instead of innerHTML
        this.dropdown.textContent = '';

        if (filteredCountries.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'tsf-country-no-results';
            noResults.textContent = 'No countries found';
            this.dropdown.appendChild(noResults);
            return;
        }

        filteredCountries.forEach((c, index) => {
            const option = document.createElement('div');
            option.className = 'tsf-country-option';
            option.setAttribute('data-code', c.code);
            option.setAttribute('data-index', index);
            option.setAttribute('role', 'option');
            option.setAttribute('tabindex', '-1');

            const flag = document.createElement('span');
            flag.className = 'tsf-country-flag';
            flag.textContent = c.flag;

            const name = document.createElement('span');
            name.className = 'tsf-country-name';
            name.textContent = c.name;

            const code = document.createElement('span');
            code.className = 'tsf-country-code';
            code.textContent = c.code;

            option.appendChild(flag);
            option.appendChild(name);
            option.appendChild(code);

            this.dropdown.appendChild(option);
        });
    }

    bindEvents() {
        // Search/filter
        this.searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();

            if (query === '') {
                this.renderDropdown(TSF_COUNTRIES);
            } else {
                const filtered = TSF_COUNTRIES.filter(c =>
                    c.name.toLowerCase().includes(query) ||
                    c.code.toLowerCase().includes(query)
                );
                this.renderDropdown(filtered);
            }

            this.dropdown.classList.add('open');
            this.focusedIndex = -1;
        });

        // Focus: show dropdown
        this.searchInput.addEventListener('focus', () => {
            this.dropdown.classList.add('open');
        });

        // Click outside: close dropdown
        document.addEventListener('click', (e) => {
            if (!this.wrapper.contains(e.target)) {
                this.dropdown.classList.remove('open');
                this.focusedIndex = -1;
            }
        });

        // Select country (click)
        this.dropdown.addEventListener('click', (e) => {
            const option = e.target.closest('.tsf-country-option');
            if (option) {
                const code = option.dataset.code;
                const country = TSF_COUNTRIES.find(c => c.code === code);
                if (country) {
                    this.selectCountry(country);
                }
            }
        });

        // Clear selection
        const clearBtn = this.selected.querySelector('.tsf-country-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.clearSelection();
            });
        }

        // Keyboard navigation
        this.searchInput.addEventListener('keydown', (e) => {
            const options = this.dropdown.querySelectorAll('.tsf-country-option');

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.focusedIndex = Math.min(this.focusedIndex + 1, options.length - 1);
                    this.updateFocus(options);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    this.focusedIndex = Math.max(this.focusedIndex - 1, 0);
                    this.updateFocus(options);
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (this.focusedIndex >= 0 && options[this.focusedIndex]) {
                        const code = options[this.focusedIndex].dataset.code;
                        const country = TSF_COUNTRIES.find(c => c.code === code);
                        if (country) {
                            this.selectCountry(country);
                        }
                    }
                    break;

                case 'Escape':
                    this.dropdown.classList.remove('open');
                    this.focusedIndex = -1;
                    break;
            }
        });
    }

    updateFocus(options) {
        options.forEach((opt, idx) => {
            if (idx === this.focusedIndex) {
                opt.classList.add('focused');
                opt.scrollIntoView({ block: 'nearest' });
            } else {
                opt.classList.remove('focused');
            }
        });
    }

    selectCountry(country) {
        // Store ISO code in hidden input
        this.hiddenInput.value = country.code;

        // Show selected country
        const flagEl = this.selected.querySelector('.tsf-country-flag');
        const nameEl = this.selected.querySelector('.tsf-country-name');

        if (flagEl) flagEl.textContent = country.flag;
        if (nameEl) nameEl.textContent = country.name;

        this.selected.classList.add('active');

        // Clear search, hide dropdown
        this.searchInput.value = '';
        this.dropdown.classList.remove('open');
        this.focusedIndex = -1;

        // Trigger change event for validation
        this.hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));

        // Update search placeholder
        this.searchInput.placeholder = `Selected: ${country.flag} ${country.name}`;
    }

    clearSelection() {
        this.hiddenInput.value = '';
        this.selected.classList.remove('active');
        this.searchInput.value = '';
        this.searchInput.placeholder = '🔍 Search your country...';
        this.searchInput.focus();
    }

    async detectUserCountry() {
        // Optional: Auto-detect user's country via IP
        try {
            const response = await fetch('https://ipapi.co/json/', {
                timeout: 3000
            });

            if (!response.ok) return;

            const data = await response.json();
            const country = TSF_COUNTRIES.find(c => c.code === data.country_code);

            if (country) {
                this.searchInput.placeholder = `🔍 Search (detected: ${country.flag} ${country.name})`;
            }
        } catch (e) {
            // Silently fail - no big deal
        }
    }

    // Public method to set country programmatically (for autosave restore)
    setCountry(countryCode) {
        const country = TSF_COUNTRIES.find(c => c.code === countryCode);
        if (country) {
            this.selectCountry(country);
        }
    }
}

// Initialize all country selects when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const countrySelects = document.querySelectorAll('.tsf-country-select-wrapper');
    countrySelects.forEach(element => {
        new TSFCountrySelect(element);
    });
});
