<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

use WCOSR\Core\EndpointValidator;

/**
 * Plugin settings (one option row). The shared secrets can instead be pinned in
 * wp-config.php so they never touch the database:
 *
 *   define( 'WCOSR_ERP_SECRET', '...' );
 *   define( 'WCOSR_ERP_PREVIOUS_SECRET', '...' ); // optional, during rotation
 */
final class Settings
{
    public const OPTION = 'wcosr_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled'         => false,
            'erp_url'         => '',
            'secret'          => '',
            'previous_secret' => '',
            'allow_insecure'  => false,
            'statuses'        => ['processing', 'completed', 'cancelled', 'refunded'],
            'max_attempts'    => 5,
            'timeout'         => 15,
            'retention_days'  => 90,
            'delete_on_uninstall' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $saved = get_option(self::OPTION, []);

        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->all()[$key] ?? null;
    }

    public function secret(): string
    {
        if (defined('WCOSR_ERP_SECRET') && is_string(WCOSR_ERP_SECRET) && WCOSR_ERP_SECRET !== '') {
            return WCOSR_ERP_SECRET;
        }

        return (string) $this->get('secret');
    }

    public function previousSecret(): string
    {
        if (defined('WCOSR_ERP_PREVIOUS_SECRET') && is_string(WCOSR_ERP_PREVIOUS_SECRET)) {
            return WCOSR_ERP_PREVIOUS_SECRET;
        }

        return (string) $this->get('previous_secret');
    }

    public function secretIsFromConstant(): bool
    {
        return defined('WCOSR_ERP_SECRET') && WCOSR_ERP_SECRET !== '';
    }

    /**
     * Secrets the inbound webhook accepts (current first, previous only while rotating).
     *
     * @return string[]
     */
    public function webhookSecrets(): array
    {
        return array_values(array_filter([$this->secret(), $this->previousSecret()], 'strlen'));
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== ''
            && EndpointValidator::isAllowed((string) $this->get('erp_url'), (bool) $this->get('allow_insecure'));
    }

    /**
     * Settings API sanitize callback. A blank secret field means "keep the stored one",
     * so the secret is never echoed back into the page.
     *
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize($input): array
    {
        $old   = $this->all();
        $input = is_array($input) ? $input : [];

        $url = esc_url_raw(trim((string) ($input['erp_url'] ?? '')), ['http', 'https']);
        $allowInsecure = ! empty($input['allow_insecure']);

        if ($url !== '' && ! EndpointValidator::isAllowed($url, $allowInsecure)) {
            add_settings_error(
                self::OPTION,
                'wcosr_url',
                __('ERP URL rejected: it must use HTTPS (plain HTTP is only allowed for localhost or when "Allow insecure HTTP" is ticked), and must not contain credentials.', 'wc-order-sync-recovery')
            );
            $url = (string) $old['erp_url'];
        }

        $secret = trim((string) ($input['secret'] ?? ''));
        if ($secret !== '' && strlen($secret) < 16) {
            add_settings_error(self::OPTION, 'wcosr_secret', __('Shared secret must be at least 16 characters; the previous value was kept.', 'wc-order-sync-recovery'));
            $secret = '';
        }

        $previous = array_key_exists('previous_secret', $input)
            ? trim((string) $input['previous_secret'])
            : (string) $old['previous_secret'];
        if (! empty($input['clear_previous_secret'])) {
            $previous = '';
        }

        $valid    = array_map(static fn(string $k): string => (string) preg_replace('/^wc-/', '', $k), array_keys(wc_get_order_statuses()));
        $statuses = array_values(array_intersect($valid, array_map('sanitize_key', (array) ($input['statuses'] ?? []))));

        return [
            'enabled'         => ! empty($input['enabled']),
            'erp_url'         => untrailingslashit($url),
            'secret'          => $secret !== '' ? $secret : (string) $old['secret'],
            'previous_secret' => $previous,
            'allow_insecure'  => $allowInsecure,
            'statuses'        => $statuses,
            'max_attempts'    => max(1, min(20, (int) ($input['max_attempts'] ?? 5))),
            'timeout'         => max(3, min(60, (int) ($input['timeout'] ?? 15))),
            'retention_days'  => max(0, min(3650, (int) ($input['retention_days'] ?? 90))),
            'delete_on_uninstall' => ! empty($input['delete_on_uninstall']),
        ];
    }
}
