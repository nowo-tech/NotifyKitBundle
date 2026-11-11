<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\DependencyInjection;

use Nowo\NotifyKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsMatchTheContract(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['enabled']);
        self::assertSame(['entity_manager' => 'default', 'table_prefix' => 'nowo_', 'auto_mapping' => true, 'mapping' => 'xml'], $config['doctrine']);
        self::assertSame(['type' => 'user', 'class' => null, 'id_field' => 'id', 'resolver' => null, 'directory' => null], $config['recipient']);
        self::assertSame(['checker' => null, 'default_permission' => null], $config['access']);
        self::assertSame([], $config['types']);
        self::assertSame([], $config['categories']);
        self::assertSame([
            'in_app' => ['enabled' => true],
            'mail'   => ['enabled' => 'auto', 'from' => null, 'template' => '@NowoNotifyKit/email/notification.html.twig'],
            'push'   => ['enabled' => 'auto', 'ttl' => 3600],
            'slack'  => ['enabled' => 'auto'],
        ], $config['channels']);
        self::assertSame(['in_app' => 'sync', 'channels' => 'async', 'transport_hint' => null], $config['delivery']);
        self::assertSame(['repeat_window_seconds' => 600, 'menu_limit' => 15, 'page_size' => 25], $config['inbox']);
        self::assertSame(['mode' => 'auto', 'poll_seconds' => 60, 'publisher' => null], $config['realtime']);
        self::assertSame(['scopes' => ['all', 'involved'], 'contexts' => ['provider' => null, 'label' => 'notify.context.project']], $config['preferences']);
        self::assertSame(['default_timezone' => 'UTC', 'allow_urgent_bypass' => true], $config['quiet_hours']);
        self::assertSame(['modes' => ['off', 'hourly', 'daily'], 'default_hour' => 8], $config['digest']);
        self::assertSame(['max_recipients' => 1000, 'chunk' => 200], $config['fan_out']);
        self::assertSame([
            'cache_pool'            => 'cache.rate_limiter',
            'per_recipient_channel' => ['limit' => 30, 'interval' => '10 minutes'],
            'ui_writes'             => ['limit' => 120, 'interval' => '1 minute'],
        ], $config['limits']);
        self::assertSame(['read_days' => 90, 'keep_per_recipient' => 500, 'delivery_log_days' => 30, 'batch_size' => 500], $config['retention']);
        self::assertSame(['base_url' => null], $config['links']);
        self::assertSame(['enabled' => false, 'digests' => '*/15 * * * *', 'retention' => '15 4 * * *'], $config['schedule']);
        self::assertSame(['max_seconds' => 50], $config['messenger']);
        self::assertSame(['user_kit' => 'auto', 'chat_kit' => 'auto', 'api_token' => 'auto', 'audit_kit' => 'auto', 'mail_kit' => 'auto', 'pwa' => 'auto', 'slack_kit' => 'auto', 'brand' => 'auto'], $config['bridges']);
        self::assertSame([
            'enabled'          => false,
            'path_prefix'      => '/notifications',
            'preferences_path' => '/account/notifications',
            'layout_template'  => null,
            'templates'        => ['bell' => null, 'menu' => null, 'inbox' => null, 'preferences' => null, 'email' => null],
            'security'         => ['access_roles' => ['ROLE_USER']],
        ], $config['web_ui']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/admin/notifications', 'security' => ['access_roles' => ['ROLE_ADMIN'], 'access_checker' => null, 'allow_unauthenticated' => false]], $config['admin_ui']);
        self::assertSame(['enabled' => false, 'prefix' => '/api'], $config['api']);
        self::assertNull($config['settings_provider']);
    }

    public function testAFullHostConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'enabled'   => true,
            'recipient' => ['type' => 'member', 'resolver' => 'app.recipients'],
            'access'    => ['checker' => 'app.access_checker', 'default_permission' => 'project.view'],
            'types'     => [
                'invitation_accepted' => ['category' => 'account', 'icon' => 'tabler:mail-check', 'channels' => ['in_app' => true, 'mail' => false]],
                'issue.assigned'      => ['category' => 'issues', 'permission' => 'issue.view', 'urgency' => 'high', 'in_app_optional' => true, 'locked_channels' => ['mail']],
            ],
            'categories'  => ['account' => ['label' => 'notify.category.account', 'order' => 10], 'issues' => ['label' => 'Issues', 'group' => 'work']],
            'channels'    => ['mail' => ['enabled' => true, 'from' => 'noreply@example.com'], 'push' => ['enabled' => false]],
            'realtime'    => ['mode' => 'poll', 'poll_seconds' => 30],
            'preferences' => ['scopes' => ['all']],
            'quiet_hours' => ['default_timezone' => 'Europe/Madrid'],
            'digest'      => ['modes' => ['off', 'daily'], 'default_hour' => 7],
            'limits'      => ['ui_writes' => ['limit' => 60, 'interval' => '30 seconds']],
            'links'       => ['base_url' => 'https://app.example.com/base'],
            'schedule'    => ['enabled' => true, 'digests' => '0 * * * *'],
            'bridges'     => ['slack_kit' => false, 'pwa' => true],
            'web_ui'      => ['enabled' => true, 'path_prefix' => '/inbox', 'security' => ['access_roles' => ['ROLE_MEMBER']]],
            'admin_ui'    => ['enabled' => true, 'security' => ['access_checker' => 'app.ops']],
            'api'         => ['enabled' => true, 'prefix' => '/v-api'],
        ]);

        self::assertSame(['category' => 'issues', 'permission' => 'issue.view', 'urgency' => 'high', 'in_app_optional' => true, 'locked_channels' => ['mail'], 'icon' => null, 'title_key' => null, 'channels' => [], 'coalesce' => true, 'in_matrix' => true], $config['types']['issue.assigned']);
        self::assertSame(['in_app' => true, 'mail' => false], $config['types']['invitation_accepted']['channels']);
        self::assertSame('https://app.example.com/base', $config['links']['base_url']);
        self::assertSame('Europe/Madrid', $config['quiet_hours']['default_timezone']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidValuesAreRefusedWithThePath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown key' => [['bell' => true], 'Unrecognized option "bell"'];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'Bad-']], 'nowo_notify_kit.doctrine.table_prefix'];
        yield 'recipient type' => [['recipient' => ['type' => 'User']], 'nowo_notify_kit.recipient.type'];
        yield 'empty service id' => [['recipient' => ['directory' => '']], 'Expected a service id or null'];
        yield 'type key' => [['types' => ['Issue.Assigned' => ['category' => 'issues']]], 'Every type key must match'];
        yield 'type without category' => [['types' => ['issue.assigned' => []]], 'The child config "category" under "nowo_notify_kit.types.issue.assigned" must be configured'];
        yield 'permission without checker' => [['types' => ['issue.assigned' => ['category' => 'issues', 'permission' => 'issue.view']]], 'needs "nowo_notify_kit.access.checker"'];
        yield 'category key' => [['categories' => ['Account' => ['label' => 'Account']]], 'Every category key must match'];
        yield 'tri-state' => [['channels' => ['slack' => ['enabled' => 'sometimes']]], 'Expected true, false or "auto"'];
        yield 'urgency' => [['types' => ['issue.assigned' => ['category' => 'issues', 'urgency' => 'urgent']]], 'nowo_notify_kit.types.issue.assigned.urgency'];
        yield 'delivery' => [['delivery' => ['channels' => 'later']], 'nowo_notify_kit.delivery.channels'];
        yield 'realtime mode' => [['realtime' => ['mode' => 'sse']], 'nowo_notify_kit.realtime.mode'];
        yield 'scope' => [['preferences' => ['scopes' => ['mine']]], 'nowo_notify_kit.preferences.scopes'];
        yield 'time zone' => [['quiet_hours' => ['default_timezone' => 'Mars/Olympus']], 'Expected a time zone identifier'];
        yield 'digest hour' => [['digest' => ['default_hour' => 24]], 'nowo_notify_kit.digest.default_hour'];
        yield 'rate interval' => [['limits' => ['per_recipient_channel' => ['interval' => 'soon']]], 'Expected an interval such as "1 minute"'];
        yield 'base url' => [['links' => ['base_url' => 'ftp://example.com']], 'Expected null or an absolute http(s) URL'];
        yield 'base url slash' => [['links' => ['base_url' => 'https://example.com/']], 'Expected null or an absolute http(s) URL'];
        yield 'cron' => [['schedule' => ['retention' => 'nightly']], 'Expected a five-field cron expression'];
        yield 'path' => [['web_ui' => ['preferences_path' => '/account/notifications/']], 'Expected an absolute path without a trailing slash'];
        yield 'api prefix' => [['api' => ['prefix' => 'api']], 'Expected an absolute path without a trailing slash'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
