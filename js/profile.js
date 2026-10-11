/**
 * NURA AI LABS - Profile Logic
 * Redis session validation, MongoDB profile data fetching, profile editing, and session termination
 */

$(document).ready(function() {
    const $loadingState = $('#loadingState');
    const $profileContent = $('#profileContent');
    const $alertContainer = $('#alertContainer');
    const $modalAlertContainer = $('#modalAlertContainer');

    const $editProfileForm = $('#editProfileForm');
    const $saveProfileBtn = $('#saveProfileBtn');
    const $saveSpinner = $('#saveSpinner');
    const $saveBtnText = $('#saveBtnText');
    const $logoutBtn = $('#logoutBtn');

    // Helper: Read cookie by name
    function getCookie(name) {
        const matches = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
        return matches ? decodeURIComponent(matches[1]) : null;
    }

    // Helper: Ensure CSRF token
    function ensureCsrfToken() {
        return new Promise((resolve) => {
            const token = getCookie('nura_csrf_token');
            if (token) return resolve(token);
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

    function showAlert(type, message, targetContainer = $alertContainer) {
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        const alertHtml = `
            <div class="alert-custom alert-custom-${type} mb-4" role="alert">
                <i class="bi ${icon} fs-5"></i>
                <div class="flex-grow-1">${message}</div>
            </div>
        `;
        targetContainer.html(alertHtml);
    }

    function clearAlert(targetContainer = $alertContainer) {
        targetContainer.empty();
    }

    // Setup global AJAX configuration (Relies purely on secure HttpOnly cookies for auth)
    $.ajaxSetup({
        xhrFields: { withCredentials: true },
        beforeSend: function(xhr) {
            const csrf = getCookie('nura_csrf_token') || sessionStorage.getItem('nura_csrf_token');
            if (csrf) {
                xhr.setRequestHeader('X-CSRF-Token', csrf);
            }
        }
    });

    // 1. Fetch Profile Data from MongoDB (Validates Redis session on backend)
    function loadProfile() {
        $.ajax({
            url: 'php/profile.php',
            method: 'GET',
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success && res.data) {
                renderProfile(res.data);
                $loadingState.addClass('d-none');
                $profileContent.removeClass('d-none');
            } else {
                window.location.href = 'login.html';
            }
        }).fail(function(xhr) {
            if (xhr.status === 401) {
                // Unauthorized - Redirect to login
                sessionStorage.removeItem('nura_session_id');
                window.location.href = 'login.html';
            } else {
                $loadingState.addClass('d-none');
                showAlert('danger', 'Failed to retrieve profile data from MongoDB. Please check server status.');
            }
        });
    }

    // 2. Render Profile in DOM
    function renderProfile(data) {
        const account = data.account || {};
        const profile = data.profile || {};
        const session = data.session || {};

        // Avatar Initial
        const initial = (profile.name || account.username || 'U').charAt(0).toUpperCase();
        $('#avatarInitial').text(initial);

        // Header
        $('#displayName').text(profile.name || account.username);
        $('#displayRole').text(profile.role || 'Member');
        $('#displayOrg').text(profile.organization || 'Nura AI Labs');

        // Account Details (MySQL)
        $('#valUsername').text(account.username || '—');
        $('#valEmail').text(account.email || '—');
        $('#valAccountId').text('#' + (account.id || '—'));
        const createdDate = account.created_at ? new Date(account.created_at).toLocaleString() : '—';
        $('#valMemberSince').text(createdDate);

        // Profile Details (MongoDB)
        $('#valFullName').text(profile.name || account.username);
        $('#valAge').text(profile.age ? profile.age + ' years old' : 'Not specified');
        $('#valPhone').text(profile.phone || 'Not provided');
        $('#valLocation').text(profile.location || 'Not provided');
        $('#valBio').text(profile.bio || 'No biography written yet.');

        // Interests (MongoDB Array - Safe Text Node DOM Creation)
        const $interestsContainer = $('#interestsContainer');
        $interestsContainer.empty();
        if (profile.interests && profile.interests.length > 0) {
            profile.interests.forEach(function(item) {
                const $tag = $('<span>').addClass('interest-tag');
                $tag.append($('<i>').addClass('bi bi-tag me-1'));
                $tag.append(document.createTextNode(String(item)));
                $interestsContainer.append($tag);
            });
        } else {
            $interestsContainer.html('<span class="text-muted small">No research areas or interests added yet.</span>');
        }

        // Redis Session Metadata
        $('#valSessionToken').text(session.status || 'Active');
        $('#valClientIp').text(session.ip_address || '127.0.0.1');

        // Populate Modal Fields for Editing
        $('#editName').val(profile.name || account.username);
        $('#editAge').val(profile.age || '');
        $('#editPhone').val(profile.phone || '');
        $('#editOrganization').val(profile.organization || '');
        $('#editRole').val(profile.role || '');
        $('#editLocation').val(profile.location || '');
        $('#editBio').val(profile.bio || '');
        $('#editInterests').val((profile.interests || []).join(', '));
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 3. Save Profile Edits (Updates MongoDB)
    $editProfileForm.on('submit', async function(e) {
        e.preventDefault();
        clearAlert($modalAlertContainer);

        $saveProfileBtn.prop('disabled', true);
        $saveSpinner.removeClass('d-none');
        $saveBtnText.text('Saving to MongoDB...');

        const csrfToken = await ensureCsrfToken();

        const payload = {
            name: $.trim($('#editName').val()),
            age: $('#editAge').val() ? parseInt($('#editAge').val(), 10) : null,
            phone: $.trim($('#editPhone').val()),
            organization: $.trim($('#editOrganization').val()),
            role: $.trim($('#editRole').val()),
            location: $.trim($('#editLocation').val()),
            bio: $.trim($('#editBio').val()),
            interests: $.trim($('#editInterests').val()),
            csrf_token: csrfToken
        };

        $.ajax({
            url: 'php/profile.php',
            method: 'POST',
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json',
            data: JSON.stringify(payload),
            headers: {
                'X-CSRF-Token': csrfToken
            }
        }).done(function(res) {
            if (res && res.success) {
                showAlert('success', 'Profile updated in MongoDB successfully!', $modalAlertContainer);
                setTimeout(function() {
                    const editModal = bootstrap.Modal.getInstance(document.getElementById('editProfileModal'));
                    if (editModal) {
                        editModal.hide();
                    }
                    clearAlert($modalAlertContainer);
                    loadProfile();
                }, 1000);
            } else {
                showAlert('danger', res.message || 'Failed to update profile.', $modalAlertContainer);
            }
        }).fail(function(xhr) {
            let errorMsg = 'An error occurred while updating profile.';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res && res.message) {
                    errorMsg = res.message;
                }
            } catch (e) {}
            showAlert('danger', errorMsg, $modalAlertContainer);
        }).always(function() {
            $saveProfileBtn.prop('disabled', false);
            $saveSpinner.addClass('d-none');
            $saveBtnText.text('Save Changes');
        });
    });

    // 4. Handle Logout (Destroys Redis session & clears cookie)
    $logoutBtn.on('click', async function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to log out of your session?')) {
            const csrfToken = await ensureCsrfToken();
            $.ajax({
                url: 'php/logout.php',
                method: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            }).always(function() {
                sessionStorage.clear();
                window.location.href = 'login.html?logout=true';
            });
        }
    });

    // Initial load
    loadProfile();
});
