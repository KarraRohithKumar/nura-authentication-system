/**
 * NURA AI LABS - Login Logic
 * AJAX-driven authentication with Redis session establishment and secure redirection
 */

$(document).ready(function() {
    const $form = $('#loginForm');
    const $btn = $('#loginBtn');
    const $btnSpinner = $('#btnSpinner');
    const $btnText = $('#btnText');
    const $alertContainer = $('#alertContainer');

    const $identifierInput = $('#identifier');
    const $passwordInput = $('#password');

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

    // Check if query params contain ?registered=true or ?logout=true
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('registered') === 'true') {
        showAlert('success', 'Account created successfully! Please sign in with your credentials.');
    } else if (urlParams.get('logout') === 'true') {
        showAlert('info', 'You have been safely signed out.');
    }

    // Preload CSRF token
    ensureCsrfToken();

    // Show alert helper
    function showAlert(type, message) {
        const icon = type === 'success' ? 'bi-check-circle-fill' : (type === 'info' ? 'bi-info-circle-fill' : 'bi-exclamation-triangle-fill');
        const alertHtml = `
            <div class="alert-custom alert-custom-${type} mb-4" role="alert">
                <i class="bi ${icon} fs-5"></i>
                <div class="flex-grow-1">${message}</div>
            </div>
        `;
        $alertContainer.html(alertHtml);
    }

    function clearAlert() {
        $alertContainer.empty();
    }

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

    // Form submission
    $form.on('submit', async function(e) {
        e.preventDefault();
        clearAlert();

        const identifier = $.trim($identifierInput.val());
        const password = $passwordInput.val();

        if (!identifier || !password) {
            showAlert('danger', 'Please enter both your username/email and password.');
            return;
        }

        // Lock form & show spinner
        $btn.prop('disabled', true);
        $btnSpinner.removeClass('d-none');
        $btnText.text('Authenticating...');

        const csrfToken = await ensureCsrfToken();

        const payload = {
            identifier: identifier,
            password: password,
            csrf_token: csrfToken
        };

        $.ajax({
            url: 'php/login.php',
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
                showAlert('success', 'Authentication verified! Establishing Redis session...');
                
                // Store non-sensitive display username only
                if (response.data && response.data.username) {
                    sessionStorage.setItem('nura_username', response.data.username);
                }

                setTimeout(function() {
                    window.location.href = 'profile.html';
                }, 1000);
            } else {
                showAlert('danger', response.message || 'Authentication failed. Please verify credentials.');
                $btn.prop('disabled', false);
                $btnSpinner.addClass('d-none');
                $btnText.text('Sign In');
            }
        }).fail(function(xhr) {
            let errorMsg = 'Invalid username/email or password.';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res && res.message) {
                    errorMsg = res.message;
                }
            } catch (err) {
                // Keep default
            }
            showAlert('danger', errorMsg);
            $btn.prop('disabled', false);
            $btnSpinner.addClass('d-none');
            $btnText.text('Sign In');
        });
    });
});
