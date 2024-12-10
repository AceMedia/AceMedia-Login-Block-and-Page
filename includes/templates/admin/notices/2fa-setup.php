<?php
/**
 * 2FA Setup Notice Template
 *
 * @package AceLoginBlock\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Overlay and Warning -->
<style>
    #wpwrap.acemedia-2fa-required::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        z-index: 999998;
    }
    .acemedia-modal {
        display: none;
        position: fixed;
        z-index: 1000000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .acemedia-modal-content {
        background-color: #fefefe;
        margin: 0px auto;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 600px;
        position: absolute;
        z-index: 1000001;
    }
</style>

<!-- Modal -->
<div id="acemedia-2fa-setup-modal" class="acemedia-modal">
    <div class="acemedia-modal-content">
        <h2><?php esc_html_e('Two-Factor Authentication Required', 'acemedia-login-block'); ?></h2>
        <p><?php esc_html_e('You must set up Two-Factor Authentication to continue using the admin area. Please choose your preferred authentication method:', 'acemedia-login-block'); ?></p>

        <table class="form-table">
            <tr>
                <th><label for="acemedia_2fa_method"><?php esc_html_e('Authentication Method', 'acemedia-login-block'); ?></label></th>
                <td>
                    <select name="acemedia_2fa_method" id="acemedia_2fa_method">
                        <option value="email"><?php esc_html_e('Email Code', 'acemedia-login-block'); ?></option>
                        <option value="auth_app"><?php esc_html_e('Authentication App', 'acemedia-login-block'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Email: Receive codes via email each time you log in.', 'acemedia-login-block'); ?><br>
                        <?php esc_html_e('Authentication App: Use an app like Google Authenticator for offline code generation.', 'acemedia-login-block'); ?>
                    </p>
                </td>
            </tr>
            <tr id="acemedia_2fa_qr_row" style="display: none;">
                <th><label for="acemedia_2fa_qr"><?php esc_html_e('QR Code', 'acemedia-login-block'); ?></label></th>
                <td>
                    <?php $qr_code_url = acemedia_generate_qr_code(get_current_user_id()); ?>
                    <img src="<?php echo esc_url($qr_code_url); ?>" alt="<?php esc_attr_e('2FA QR Code', 'acemedia-login-block'); ?>" />
                    <p class="description"><?php esc_html_e('Scan this QR code with your authentication app to get started.', 'acemedia-login-block'); ?></p>
                </td>
            </tr>
        </table>

        <div class="submit-wrapper" style="margin-top: 20px; text-align: right;">
            <button type="button" class="button" id="download-2fa-backup-codes" style="margin-right: 10px;">
                <?php esc_html_e('Download Backup Codes', 'acemedia-login-block'); ?>
            </button>
            <button type="button" class="button button-primary" onclick="acemediaSave2FASetup()">
                <?php esc_html_e('Save and Enable 2FA', 'acemedia-login-block'); ?>
            </button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Show modal immediately
    $('#acemedia-2fa-setup-modal').show();

    // Handle method change
    $('#acemedia_2fa_method').on('change', function() {
        const qrRow = $('#acemedia_2fa_qr_row');
        if (this.value === 'auth_app') {
            qrRow.show();
            // Refresh QR code
            const qrImage = qrRow.find('img');
            if (qrImage.length) {
                qrImage.attr('src', qrImage.attr('src').split('?')[0] + '?' + new Date().getTime());
            }
        } else {
            qrRow.hide();
        }
    });

    // Handle backup codes download
    $('#download-2fa-backup-codes').on('click', function() {
        $.post(ajaxurl, {
            action: 'get_or_generate_backup_codes',
            _ajax_nonce: '<?php echo wp_create_nonce("get_or_generate_backup_codes"); ?>',
            force_new: false
        }, function(response) {
            if (response.success) {
                const siteDomain = '<?php echo sanitize_file_name(parse_url(get_site_url(), PHP_URL_HOST)); ?>';
                const filename = siteDomain + '-2fa-backup-codes.txt';
                const blob = new Blob([response.data.codes.join('\n')], { type: 'text/plain' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
            }
        });
    });
});

function acemediaSave2FASetup() {
    const formData = new FormData();
    formData.append('action', 'acemedia_save_2fa_setup');
    formData.append('_ajax_nonce', '<?php echo wp_create_nonce("acemedia_2fa_setup"); ?>');
    formData.append('acemedia_2fa_enabled', '1');
    formData.append('acemedia_2fa_method', document.getElementById('acemedia_2fa_method').value);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.data.redirect;
        } else {
            alert(data.data.message || '<?php esc_html_e('Error saving 2FA settings', 'acemedia-login-block'); ?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php esc_html_e('Error saving 2FA settings', 'acemedia-login-block'); ?>');
    });
}
</script>