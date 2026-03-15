<?php
/**
 * Admin page template for Hostney Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$token = get_option( 'hostney_migration_token' );
$status = get_option( 'hostney_migration_status', '' );
$is_connected = ! empty( $token ) && $status === 'connected';
?>

<div class="wrap">
    <div class="hostney-page-heading">
        <h1><span class="hostney-brand">HOSTNEY</span> <span class="hostney-brand-subtitle">&ndash; Migration plugin</span></h1>
    </div>

    <div id="hostney-migration-container">
        <?php if ( ! $is_connected ) : ?>

            <!-- Token entry form -->
            <div id="hostney-connect-section" class="hostney-card hostney-card-accent">
                <h2>Connect to Hostney</h2>
                <p>Paste the migration token from your Hostney control panel to connect this site.</p>

                <div class="hostney-form-group">
                    <label for="hostney-token">Migration token</label>
                    <input type="text" id="hostney-token" placeholder="Paste your 96-character migration token" maxlength="96" autocomplete="off" />
                </div>

                <button id="hostney-connect-btn" class="hostney-btn hostney-btn-primary">Connect</button>

                <div id="hostney-connect-error" class="hostney-error" style="display: none;"></div>
            </div>

            <!-- Pre-flight checks -->
            <div class="hostney-card">
                <h2>System requirements</h2>
                <table class="hostney-checks-table">
                    <tr>
                        <td>PHP version</td>
                        <td>
                            <?php if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) : ?>
                                <span class="hostney-check-pass"><?php echo esc_html( PHP_VERSION ); ?></span>
                            <?php else : ?>
                                <span class="hostney-check-fail"><?php echo esc_html( PHP_VERSION ); ?> (7.4+ required)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WordPress version</td>
                        <td><span class="hostney-check-pass"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td>
                    </tr>
                    <tr>
                        <td>REST API</td>
                        <td>
                            <?php if ( function_exists( 'rest_url' ) ) : ?>
                                <span class="hostney-check-pass">Available</span>
                            <?php else : ?>
                                <span class="hostney-check-fail">Not available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>HTTPS</td>
                        <td>
                            <?php if ( is_ssl() || strpos( get_option( 'siteurl' ), 'https' ) === 0 ) : ?>
                                <span class="hostney-check-pass">Enabled</span>
                            <?php else : ?>
                                <span class="hostney-check-warn">Not detected (migration may still work)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Multisite</td>
                        <td>
                            <?php if ( ! is_multisite() ) : ?>
                                <span class="hostney-check-pass">No (single site)</span>
                            <?php else : ?>
                                <span class="hostney-check-fail">Multisite not supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

        <?php else : ?>

            <!-- Connected state -->
            <div id="hostney-connected-section" class="hostney-card hostney-card-accent">
                <span class="hostney-status-badge hostney-status-badge-connected">Connected</span>
                <h2>Ready for migration</h2>
                <p>This site is connected to Hostney. Go to your <strong>Hostney control panel</strong> and click <strong>"Start migration"</strong> to begin.</p>

                <div class="hostney-info-box">
                    <strong>What happens next:</strong>
                    <ol>
                        <li>The Hostney worker connects to this plugin via REST API</li>
                        <li>Your database tables are exported row-by-row</li>
                        <li>Your WordPress files are transferred in chunks</li>
                        <li>Database credentials and URLs are updated automatically</li>
                        <li>Your site is live on Hostney</li>
                    </ol>
                </div>

                <hr class="hostney-divider" />

                <p class="hostney-disconnect-hint">Disconnecting will revoke the token. You'll need a new token to reconnect.</p>
                <button id="hostney-disconnect-btn" class="hostney-btn hostney-btn-outline">Disconnect</button>
            </div>

        <?php endif; ?>
    </div>
</div>
