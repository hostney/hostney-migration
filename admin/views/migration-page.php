<?php
/**
 * Admin page template for Hostney Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hostney_token        = get_option( 'hostney_migration_token' );
$hostney_status       = get_option( 'hostney_migration_status', '' );
$hostney_is_connected = ! empty( $hostney_token ) && $hostney_status === 'connected';
?>

<div class="wrap">
    <div class="hostney-page-heading">
        <h1><span class="hostney-brand">HOSTNEY</span> <span class="hostney-brand-subtitle">&ndash; <?php esc_html_e( 'Migration plugin', 'hostney-migration' ); ?></span></h1>
    </div>

    <div id="hostney-migration-container">
        <?php if ( ! $hostney_is_connected ) : ?>

            <!-- Token entry form -->
            <div id="hostney-connect-section" class="hostney-card hostney-card-accent">
                <h2><?php esc_html_e( 'Connect to Hostney', 'hostney-migration' ); ?></h2>
                <p><?php esc_html_e( 'Paste the migration token from your Hostney control panel to connect this site.', 'hostney-migration' ); ?></p>

                <p class="hostney-disclosure">
                    <?php
                    printf(
                        /* translators: %s: link to Hostney privacy policy */
                        esc_html__( 'When you connect, this plugin sends your site URL, WordPress version, PHP version, and database/file size estimates to Hostney servers (%s) to register the migration. No file contents or database data are sent during this step.', 'hostney-migration' ),
                        '<a href="https://www.hostney.com/documents/privacy-policy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy policy', 'hostney-migration' ) . '</a>'
                    );
                    ?>
                </p>

                <div class="hostney-form-group">
                    <label for="hostney-token"><?php esc_html_e( 'Migration token', 'hostney-migration' ); ?></label>
                    <input type="text" id="hostney-token" placeholder="<?php esc_attr_e( 'Paste your 96-character migration token', 'hostney-migration' ); ?>" maxlength="96" autocomplete="off" />
                </div>

                <button id="hostney-connect-btn" class="hostney-btn hostney-btn-primary"><?php esc_html_e( 'Connect', 'hostney-migration' ); ?></button>

                <div id="hostney-connect-error" class="hostney-error" style="display: none;"></div>
            </div>

            <!-- Pre-flight checks -->
            <div class="hostney-card">
                <h2><?php esc_html_e( 'System requirements', 'hostney-migration' ); ?></h2>
                <table class="hostney-checks-table">
                    <tr>
                        <td><?php esc_html_e( 'PHP version', 'hostney-migration' ); ?></td>
                        <td>
                            <?php if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) : ?>
                                <span class="hostney-check-pass"><?php echo esc_html( PHP_VERSION ); ?></span>
                            <?php else : ?>
                                <span class="hostney-check-fail"><?php echo esc_html( PHP_VERSION ); ?> (<?php esc_html_e( '7.4+ required', 'hostney-migration' ); ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'WordPress version', 'hostney-migration' ); ?></td>
                        <td><span class="hostney-check-pass"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'REST API', 'hostney-migration' ); ?></td>
                        <td>
                            <?php if ( function_exists( 'rest_url' ) ) : ?>
                                <span class="hostney-check-pass"><?php esc_html_e( 'Available', 'hostney-migration' ); ?></span>
                            <?php else : ?>
                                <span class="hostney-check-fail"><?php esc_html_e( 'Not available', 'hostney-migration' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'HTTPS', 'hostney-migration' ); ?></td>
                        <td>
                            <?php if ( is_ssl() || strpos( get_option( 'siteurl' ), 'https' ) === 0 ) : ?>
                                <span class="hostney-check-pass"><?php esc_html_e( 'Enabled', 'hostney-migration' ); ?></span>
                            <?php else : ?>
                                <span class="hostney-check-warn"><?php esc_html_e( 'Not detected (migration may still work)', 'hostney-migration' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Multisite', 'hostney-migration' ); ?></td>
                        <td>
                            <?php if ( ! is_multisite() ) : ?>
                                <span class="hostney-check-pass"><?php esc_html_e( 'No (single site)', 'hostney-migration' ); ?></span>
                            <?php else : ?>
                                <span class="hostney-check-fail"><?php esc_html_e( 'Multisite not supported', 'hostney-migration' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

        <?php else : ?>

            <!-- Connected state -->
            <div id="hostney-connected-section" class="hostney-card hostney-card-accent">
                <span class="hostney-status-badge hostney-status-badge-connected"><?php esc_html_e( 'Connected', 'hostney-migration' ); ?></span>
                <h2><?php esc_html_e( 'Ready for migration', 'hostney-migration' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %1$s and %2$s wrap "Hostney control panel", %3$s and %4$s wrap "Start migration" */
                        esc_html__( 'This site is connected to Hostney. Go to your %1$sHostney control panel%2$s and click %3$s"Start migration"%4$s to begin.', 'hostney-migration' ),
                        '<strong>',
                        '</strong>',
                        '<strong>',
                        '</strong>'
                    );
                    ?>
                </p>

                <div class="hostney-info-box">
                    <strong><?php esc_html_e( 'What happens next:', 'hostney-migration' ); ?></strong>
                    <ol>
                        <li><?php esc_html_e( 'The Hostney worker connects to this plugin via REST API', 'hostney-migration' ); ?></li>
                        <li><?php esc_html_e( 'Your database tables are exported row-by-row', 'hostney-migration' ); ?></li>
                        <li><?php esc_html_e( 'Your WordPress files are transferred in chunks', 'hostney-migration' ); ?></li>
                        <li><?php esc_html_e( 'Database credentials and URLs are updated automatically', 'hostney-migration' ); ?></li>
                        <li><?php esc_html_e( 'Your site is live on Hostney', 'hostney-migration' ); ?></li>
                    </ol>
                </div>

                <hr class="hostney-divider" />

                <p class="hostney-disconnect-hint"><?php esc_html_e( 'Disconnecting will revoke the token. You will need a new token to reconnect.', 'hostney-migration' ); ?></p>
                <button id="hostney-disconnect-btn" class="hostney-btn hostney-btn-outline"><?php esc_html_e( 'Disconnect', 'hostney-migration' ); ?></button>
            </div>

        <?php endif; ?>
    </div>
</div>
