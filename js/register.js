/**
 * NURA AI LABS - Registration Logic
 * AJAX-driven account registration with client-side validation and CSRF protection
 */

$(document).ready(function() {
    const $form = $('#registerForm');
    const $btn = $('#registerBtn');
    const $btnSpinner = $('#btnSpinner');
    const $btnText = $('#btnText');
    const $alertContainer = $('#alertContainer');

    const $usernameInput = $('#username');
    const $emailInput = $('#email');
    const $passwordInput = $('#password');
    const $confirmPasswordInput = $('#confirm_password');
    const $meterFill = $('#passwordMeterFill');
    const $strengthText = $('#passwordStrengthText');

    // Helper: Read cookie by name
    function getCookie(name) {
        const matches = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
        return matches ? decodeURIComponent(matches[1]) : null;
    }

    // Helper: Ensure CSRF token is loaded from server
    function ensureCsrfToken() {
        return new Promise((resolve) => {
            const token = getCookie('nura_csrf_token');
            if (token) {
                return resolve(token);
            }
            $.ajax({
                url: 'php/csrf.php',
                method: 'GET',
                dataType: 'json',
                xhrFields: { withCredentials: true }
            }).done(function(res) {
                if (res && res.data && res.data.csrf_token) {
                    sessionStorage.setItem('nura_csrf_token', res.data.csrf_token);
                    resolve(res.data.csrf_token);
                } else {
                    resolve(null);
                }
            }).fail(function() {
                resolve(null);
            });
        });
    }

    // Preload CSRF token on page load
    ensureCsrfToken();

    // Show alert helper
    function showAlert(type, message) {
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        const alertHtml = `
            <div class="alert-custom alert-custom-${type} mb-4" role="alert">
                <i class="bi ${icon} fs-5"></i>
                <div class="flex-grow-1">${message}</div>
            </div>
        `;
        $alertContainer.html(alertHtml);
        $('html, body').animate({ scrollTop: $alertContainer.offset().top - 100 }, 200);
    }

    function clearAlert() {
        $alertContainer.empty();
    }

    // Password strength evaluator
    function evaluatePassword(pwd) {
        let score = 0;
        if (!pwd) return { score: 0, text: '', color: '' };
        if (pwd.length >= 8) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^A-Za-z0-9]/.test(pwd)) score++;

        if (score <= 1) return { score: 25, text: 'Weak', color: '#ef4444' };
        if (score === 2) return { score: 50, text: 'Fair', color: '#f59e0b' };
        if (score === 3) return { score: 75, text: 'Good', color: '#3b82f6' };
        return { score: 100, text: 'Strong', color: '#10b981' };
    }

    $passwordInput.on('input', function() {
        const val = $(this).val();
        const evalRes = evaluatePassword(val);
        $meterFill.css({
            width: evalRes.score + '%',
            backgroundColor: evalRes.color
        });
        $strengthText.text(evalRes.text).css('color', evalRes.color);
    });

    // Toggle password visibility
    $('.input-password-toggle').on('click', function() {
        const targetId = $(this).data('target');
        const $target = $('#' + targetId);
        const $icon = $(this).find('i');

        if ($target.attr('type') === 'password') {
            $target.attr('type', 'text');
            $icon.removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            $target.attr('type', 'password');
            $icon.removeClass('bi-eye-slash').addClass('bi-eye');
        }
    });

    // Form Submission via jQuery AJAX
    $form.on('submit', async function(e) {
        e.preventDefault();
        clearAlert();

        const username = $.trim($usernameInput.val());
        const email = $.trim($emailInput.val());
        const password = $passwordInput.val();
        const confirmPassword = $confirmPasswordInput.val();

        // Client-side Validations
        if (!username || !email || !password || !confirmPassword) {
            showAlert('danger', 'All fields are mandatory. Please fill in the complete form.');
            return;
        }

        if (username.length < 3 || username.length > 30) {
            showAlert('danger', 'Username must be between 3 and 30 characters.');
            return;
        }

        if (!/^[a-zA-Z0-9_\-\.]+$/.test(username)) {
            showAlert('danger', 'Username can only contain alphanumeric characters, underscores, dots, or hyphens.');
            return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            showAlert('danger', 'Please enter a valid email address.');
            return;
        }

        if (password.length < 8) {
            showAlert('danger', 'Password must be at least 8 characters long.');
            return;
        }

        if (password !== confirmPassword) {
            showAlert('danger', 'Password and confirmation password do not match.');
            return;
        }

        // Lock form & show spinner
        $btn.prop('disabled', true);
        $btnSpinner.removeClass('d-none');
        $btnText.text('Creating Account...');

        const csrfToken = await ensureCsrfToken();

        const payload = {
            username: username,
            email: email,
            password: password,
            confirm_password: confirmPassword,
            csrf_token: csrfToken
        };

        $.ajax({
            url: 'php/register.php',
            method: 'POST',
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json',
            data: JSON.stringify(payload),
            xhrFields: { withCredentials: true },
            headers: {
                'X-CSRF-Token': csrfToken
            }
        }).done(function(response) {
            if (response && response.success) {
                showAlert('success', 'Registration successful! Initializing your Nura AI profile and redirecting to login...');
                $form[0].reset();
                $meterFill.css('width', '0%');
                $strengthText.text('');
                setTimeout(function() {
                    window.location.href = 'login.html?registered=true';
                }, 1600);
            } else {
                showAlert('danger', response.message || 'Registration failed. Please try again.');
                $btn.prop('disabled', false);
                $btnSpinner.addClass('d-none');
                $btnText.text('Create Account');
            }
        }).fail(function(xhr) {
            let errorMsg = 'An error occurred during registration. Please verify your details.';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res && res.message) {
                    errorMsg = res.message;
                }
            } catch (err) {
                // Keep default message
            }
            showAlert('danger', errorMsg);
            $btn.prop('disabled', false);
            $btnSpinner.addClass('d-none');
            $btnText.text('Create Account');
        });
    });
});
