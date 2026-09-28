/**
 * E-Bursary Management System - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // Mobile Sidebar Toggle
    const mobileToggle = document.getElementById('mobileToggleBtn');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function () {
            sidebar.classList.toggle('show');
        });

        // Close sidebar when clicking outside on small screens
        document.addEventListener('click', function (e) {
            if (window.innerWidth < 992 &&
                !sidebar.contains(e.target) &&
                !mobileToggle.contains(e.target) &&
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    }

    // Auto-dismiss alerts after 6 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            } catch (err) {
                // fallback
                alert.style.display = 'none';
            }
        }, 6000);
    });

    // Education Level Dynamic Form Field Toggle
    const eduLevelSelect = document.getElementById('education_level');
    if (eduLevelSelect) {
        function toggleEduFields() {
            const level = eduLevelSelect.value;
            const primaryFields = document.getElementById('fields-primary');
            const highFields = document.getElementById('fields-high_school');
            const uniFields = document.getElementById('fields-university');

            if (primaryFields) {
                primaryFields.style.display = (level === 'primary') ? 'block' : 'none';
                primaryFields.querySelectorAll('input, select').forEach(el => el.disabled = (level !== 'primary'));
            }
            if (highFields) {
                highFields.style.display = (level === 'high_school') ? 'block' : 'none';
                highFields.querySelectorAll('input, select').forEach(el => el.disabled = (level !== 'high_school'));
            }
            if (uniFields) {
                uniFields.style.display = (level === 'university') ? 'block' : 'none';
                uniFields.querySelectorAll('input, select').forEach(el => el.disabled = (level !== 'university'));
            }
        }

        eduLevelSelect.addEventListener('change', toggleEduFields);
        toggleEduFields(); // Run on page load
    }

    // Guardian Relationship "Other" Toggle
    const guardianRelSelect = document.getElementById('relationship');
    const otherRelContainer = document.getElementById('other-relationship-container');
    if (guardianRelSelect && otherRelContainer) {
        function toggleOtherRel() {
            if (guardianRelSelect.value === 'Other') {
                otherRelContainer.style.display = 'block';
            } else {
                otherRelContainer.style.display = 'none';
            }
        }
        guardianRelSelect.addEventListener('change', toggleOtherRel);
        toggleOtherRel();
    }

    // Confirmation Prompts
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const message = btn.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
});

