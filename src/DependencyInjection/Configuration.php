<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Strict configuration tree of `nowo_notify_kit` (contract 221-B §7). Every key exists from S0 so hosts can write the
 * final configuration now; S0 itself registers nothing.
 */
final class Configuration implements ConfigurationInterface
{
    /** Notification type keys, e.g. `invitation_accepted` or `issue.assigned`. */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** Category, channel and recipient-type keys. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * The `nowo_notify_kit` tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(NowoNotifyKitExtension::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('No route, listener or channel while false')->end()
                ->append(ConfigNodes::doctrine())
                ->arrayNode('recipient')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('type')->defaultValue('user')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(self::KEY_PATTERN, $value) !== 1)
                                ->thenInvalid('Expected a recipient type matching ^[a-z][a-z0-9_]{0,31}$, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('class')->defaultNull()->info('Host user class of the default Doctrine recipient resolver')->end()
                        ->scalarNode('id_field')->defaultValue('id')->cannotBeEmpty()->end()
                        ->append(ConfigNodes::service('resolver', 'Recipient resolver service; null = Doctrine resolver'))
                        ->append(ConfigNodes::service('directory', 'People directory service (name, avatar, e-mail, locale)'))
                    ->end()
                ->end()
                ->arrayNode('access')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('checker', 'RecipientAccessCheckerInterface service; null = personal rows only (Q-O6)'))
                        ->scalarNode('default_permission')->defaultNull()->end()
                    ->end()
                ->end()
                ->append($this->types())
                ->arrayNode('categories')
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('group')->defaultValue('general')->cannotBeEmpty()->end()
                            ->integerNode('order')->defaultValue(0)->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(
                            /** @param array<string, mixed> $categories */
                            static fn (array $categories): bool => self::firstInvalidKey($categories, self::KEY_PATTERN) !== null,
                        )
                        ->thenInvalid('Every category key must match ^[a-z][a-z0-9_]{0,31}$, got %s.')
                    ->end()
                ->end()
                ->arrayNode('channels')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('in_app')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('mail')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                                ->scalarNode('from')->defaultNull()->end()
                                ->scalarNode('template')->defaultValue('@NowoNotifyKit/email/notification.html.twig')->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                        ->arrayNode('push')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                                ->integerNode('ttl')->defaultValue(3600)->min(0)->max(2419200)->end()
                            ->end()
                        ->end()
                        ->arrayNode('slack')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('delivery')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('in_app')->values(['sync', 'async'])->defaultValue('sync')->end()
                        ->enumNode('channels')->values(['sync', 'async'])->defaultValue('async')->info('async falls back to sync without Messenger (Q-O3)')->end()
                        ->scalarNode('transport_hint')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('inbox')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('repeat_window_seconds')->defaultValue(600)->min(0)->max(86400)->end()
                        ->integerNode('menu_limit')->defaultValue(15)->min(1)->max(100)->end()
                        ->integerNode('page_size')->defaultValue(25)->min(1)->max(200)->end()
                    ->end()
                ->end()
                ->arrayNode('realtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('mode')->values(['auto', 'mercure', 'poll', 'off'])->defaultValue('auto')->info('auto = Mercure when installed, else poll')->end()
                        ->integerNode('poll_seconds')->defaultValue(60)->min(10)->max(3600)->end()
                        ->append(ConfigNodes::service('publisher', 'RealtimePublisherInterface service; null = bundle publisher'))
                    ->end()
                ->end()
                ->arrayNode('preferences')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('scopes')
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['all', 'involved'])
                            ->enumPrototype()->values(['all', 'involved'])->end()
                        ->end()
                        ->arrayNode('contexts')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append(ConfigNodes::service('provider', 'Context provider service (e.g. projects); null = no context switches'))
                                ->scalarNode('label')->defaultValue('notify.context.project')->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('quiet_hours')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::timezone('default_timezone', 'UTC'))
                        ->booleanNode('allow_urgent_bypass')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('digest')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('modes')
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['off', 'hourly', 'daily'])
                            ->enumPrototype()->values(['off', 'hourly', 'daily'])->end()
                        ->end()
                        ->integerNode('default_hour')->defaultValue(8)->min(0)->max(23)->end()
                    ->end()
                ->end()
                ->arrayNode('fan_out')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_recipients')->defaultValue(1000)->min(1)->max(100000)->end()
                        ->integerNode('chunk')->defaultValue(200)->min(1)->max(5000)->end()
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.rate_limiter')->cannotBeEmpty()->end()
                        ->append(ConfigNodes::rate('per_recipient_channel', 30, '10 minutes'))
                        ->append(ConfigNodes::rate('ui_writes', 120, '1 minute'))
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('read_days')->defaultValue(90)->min(1)->max(3650)->info('Read rows only: unread rows are never deleted by age (Q-O8)')->end()
                        ->integerNode('keep_per_recipient')->defaultValue(500)->min(1)->max(100000)->end()
                        ->integerNode('delivery_log_days')->defaultValue(30)->min(1)->max(3650)->end()
                        ->integerNode('batch_size')->defaultValue(500)->min(1)->max(5000)->end()
                    ->end()
                ->end()
                ->arrayNode('links')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::baseUrl('base_url'))
                    ->end()
                ->end()
                ->arrayNode('schedule')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::cron('digests', '*/15 * * * *'))
                        ->append(ConfigNodes::cron('retention', '15 4 * * *'))
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_seconds')->defaultValue(50)->min(1)->max(3600)->end()
                    ->end()
                ->end()
                ->arrayNode('bridges')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('user_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('chat_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('api_token', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('audit_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('mail_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('pwa', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('slack_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('brand', ConfigNodes::AUTO))
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::path('path_prefix', '/notifications'))
                        ->append(ConfigNodes::path('preferences_path', '/account/notifications'))
                        ->scalarNode('layout_template')->defaultNull()->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('bell')->defaultNull()->end()
                                ->scalarNode('menu')->defaultNull()->end()
                                ->scalarNode('inbox')->defaultNull()->end()
                                ->scalarNode('preferences')->defaultNull()->end()
                                ->scalarNode('email')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('security')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('access_roles')
                                    ->requiresAtLeastOneElement()
                                    ->defaultValue(['ROLE_USER'])
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append(ConfigNodes::adminUi('/admin/notifications'))
                ->append(ConfigNodes::api())
                ->append(ConfigNodes::service('settings_provider', 'Settings provider service; null = configuration values'))
            ->end()
            ->validate()
                ->always(
                    /**
                     * Deny by default (Q-O6): a type that names a permission needs an access checker.
                     *
                     * @param array{access: array{checker: string|null}, types: array<string, array{permission: string|null}>} $config
                     *
                     * @return array{access: array{checker: string|null}, types: array<string, array{permission: string|null}>}
                     */
                    static function (array $config): array {
                        if ($config['access']['checker'] === null) {
                            foreach ($config['types'] as $key => $type) {
                                if ($type['permission'] !== null) {
                                    throw new InvalidConfigurationException(sprintf('"nowo_notify_kit.types.%s.permission" needs "nowo_notify_kit.access.checker" (deny by default: the bundle never guesses who may open a target).', $key));
                                }
                            }
                        }

                        return $config;
                    },
                )
            ->end();

        return $treeBuilder;
    }

    /**
     * `types`: `{key: {category, icon, title_key, permission, channels, in_app_optional, locked_channels, urgency, coalesce, in_matrix}}`, merged with tagged providers.
     */
    private function types(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('types');
        $node
            ->useAttributeAsKey('key')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('category')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('icon')->defaultNull()->end()
                    ->scalarNode('title_key')->defaultNull()->info('null = notify.type.<key>.title')->end()
                    ->scalarNode('permission')->defaultNull()->info('Permission needed to open the target; needs access.checker')->end()
                    ->arrayNode('channels')
                        ->info('Default channels, e.g. {in_app: true, mail: false}')
                        ->useAttributeAsKey('channel')
                        ->booleanPrototype()->end()
                    ->end()
                    ->booleanNode('in_app_optional')->defaultFalse()->info('The bell row is locked on (always written) unless true (Q-O2)')->end()
                    ->arrayNode('locked_channels')
                        ->info('Further channels the person cannot turn off')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                    ->enumNode('urgency')->values(['low', 'normal', 'high'])->defaultValue('normal')->info('high may bypass quiet hours')->end()
                    ->booleanNode('coalesce')->defaultTrue()->end()
                    ->booleanNode('in_matrix')->defaultTrue()->info('Shown in the preferences matrix')->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(
                    /** @param array<string, mixed> $types */
                    static fn (array $types): bool => self::firstInvalidKey($types, self::TYPE_PATTERN) !== null,
                )
                ->thenInvalid('Every type key must match ^[a-z][a-z0-9_]*(.[a-z][a-z0-9_]*)*$ (e.g. "issue.assigned"), got %s.')
            ->end();

        return $node;
    }

    /**
     * The first key of $map that does not match $pattern (null = all valid).
     *
     * @param array<array-key, mixed> $map
     */
    private static function firstInvalidKey(array $map, string $pattern): int|string|null
    {
        foreach ($map as $key => $unused) {
            if (preg_match($pattern, (string) $key) !== 1) {
                return $key;
            }
        }

        return null;
    }
}
