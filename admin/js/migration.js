/**
 * Hostney Migration Plugin - Admin JS
 *
 * Handles token submission and disconnect functionality.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        var i18n = hostneyMigration.i18n || {};

        // Connect button handler
        $('#hostney-connect-btn').on('click', function () {
            var token = $('#hostney-token').val().trim();
            var $btn = $(this);
            var $error = $('#hostney-connect-error');

            // Validate token format
            if (!token || token.length !== 96 || !/^[a-f0-9]+$/i.test(token)) {
                $error.text(i18n.invalidToken || 'Please enter a valid 96-character migration token.').show();
                return;
            }

            $error.hide();
            $btn.prop('disabled', true).text(i18n.connecting || 'Connecting...');

            $.ajax({
                url: hostneyMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_connect',
                    nonce: hostneyMigration.nonce,
                    token: token
                },
                success: function (response) {
                    if (response.success) {
                        // Reload page to show connected state
                        window.location.reload();
                    } else {
                        var msg = response.data && response.data.message
                            ? response.data.message
                            : (i18n.connectionFailed || 'Connection failed. Please check your token and try again.');
                        $error.text(msg).show();
                        $btn.prop('disabled', false).text(i18n.connect || 'Connect');
                    }
                },
                error: function () {
                    $error.text(i18n.networkError || 'Network error. Please check your connection and try again.').show();
                    $btn.prop('disabled', false).text(i18n.connect || 'Connect');
                }
            });
        });

        // Allow Enter key to submit token
        $('#hostney-token').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#hostney-connect-btn').trigger('click');
            }
        });

        // Disconnect button handler
        $('#hostney-disconnect-btn').on('click', function () {
            if (!confirm(i18n.confirmDisconnect || 'Are you sure you want to disconnect? You will need a new migration token to reconnect.')) {
                return;
            }

            $.ajax({
                url: hostneyMigration.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_disconnect',
                    nonce: hostneyMigration.nonce
                },
                success: function () {
                    window.location.reload();
                },
                error: function () {
                    window.location.reload();
                }
            });
        });
    });

})(jQuery);
