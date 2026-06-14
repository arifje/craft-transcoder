<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\gql;

use Craft;
use craft\elements\Asset;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\gql\GqlEntityRegistry;
use craft\gql\TypeManager;
use craft\helpers\Json as JsonHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use nystudio107\transcoder\Transcoder;
use Throwable;
use yii\base\Event;

/**
 * Registers Transcoder fields on Craft's GraphQL asset interface.
 */
class TranscoderGql
{
    /**
     * Register GraphQL hooks.
     */
    public static function register(): void
    {
        Event::on(
            TypeManager::class,
            TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS,
            [self::class, 'defineTypeFields']
        );
    }

    /**
     * Add Transcoder fields to AssetInterface.
     *
     * @param DefineGqlTypeFieldsEvent $event
     */
    public static function defineTypeFields(DefineGqlTypeFieldsEvent $event): void
    {
        if ($event->typeName !== 'AssetInterface') {
            return;
        }

        $event->fields['transcoderVideoStatus'] = [
            'name' => 'transcoderVideoStatus',
            'type' => self::getVideoStatusType(),
            'args' => self::getVideoStatusArgs(),
            'description' => 'Returns queue-aware Transcoder video status data for this asset.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return null;
                }

                $videoOptions = self::decodeOptions($arguments['videoOptions'] ?? null, 'videoOptions');
                $encodingOptions = self::decodeOptions($arguments['encodingOptions'] ?? null, 'encodingOptions');
                $status = Transcoder::$plugin->transcode->getVideoStatus(
                    $source,
                    $videoOptions,
                    $encodingOptions,
                    (bool)($arguments['queueIfMissing'] ?? false),
                    (bool)($arguments['includeDebug'] ?? false)
                );

                return self::decodeStatus($status);
            },
        ];

        $event->fields['transcoderVideoStatusUrl'] = [
            'name' => 'transcoderVideoStatusUrl',
            'type' => Type::string(),
            'args' => self::getVideoOptionsArgs(),
            'description' => 'Returns a URL that can be polled for queue-aware Transcoder video status.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return null;
                }

                return Transcoder::$plugin->transcode->getVideoStatusUrl(
                    $source,
                    self::decodeOptions($arguments['videoOptions'] ?? null, 'videoOptions'),
                    self::decodeOptions($arguments['encodingOptions'] ?? null, 'encodingOptions')
                );
            },
        ];

        $event->fields['transcoderVideoPosterUrl'] = [
            'name' => 'transcoderVideoPosterUrl',
            'type' => Type::string(),
            'args' => [
                'formatHandle' => [
                    'name' => 'formatHandle',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The configured video poster format handle.',
                ],
                'generate' => [
                    'name' => 'generate',
                    'type' => Type::boolean(),
                    'defaultValue' => false,
                    'description' => 'Whether the poster may be generated during this request.',
                ],
            ],
            'description' => 'Returns a configured Transcoder video poster URL for this asset.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return null;
                }

                return Transcoder::$plugin->transcode->getVideoPosterUrl(
                    $source,
                    (string)($arguments['formatHandle'] ?? ''),
                    (bool)($arguments['generate'] ?? false)
                );
            },
        ];

        $event->fields['transcoderVideoPosterUrls'] = [
            'name' => 'transcoderVideoPosterUrls',
            'type' => Type::listOf(Type::nonNull(self::getPosterUrlType())),
            'args' => [
                'generate' => [
                    'name' => 'generate',
                    'type' => Type::boolean(),
                    'defaultValue' => false,
                    'description' => 'Whether missing posters may be generated during this request.',
                ],
            ],
            'description' => 'Returns all configured Transcoder video poster URLs for this asset.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return [];
                }

                return self::normalizePosterUrls(Transcoder::$plugin->transcode->getVideoPosterUrls(
                    $source,
                    (bool)($arguments['generate'] ?? false)
                ));
            },
        ];

        $event->fields['transcoderGifStatus'] = [
            'name' => 'transcoderGifStatus',
            'type' => self::getGifStatusType(),
            'args' => self::getGifStatusArgs(),
            'description' => 'Returns queue-aware Transcoder GIF status data for this asset.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return null;
                }

                $status = Transcoder::$plugin->transcode->getGifStatus(
                    $source,
                    self::decodeOptions($arguments['gifOptions'] ?? null, 'gifOptions'),
                    (bool)($arguments['queueIfMissing'] ?? false),
                    (bool)($arguments['includeDebug'] ?? false)
                );

                return self::decodeStatus($status);
            },
        ];

        $event->fields['transcoderGifStatusUrl'] = [
            'name' => 'transcoderGifStatusUrl',
            'type' => Type::string(),
            'args' => self::getGifOptionsArgs(),
            'description' => 'Returns a URL that can be polled for queue-aware Transcoder GIF status.',
            'resolve' => function($source, array $arguments) {
                if (!$source instanceof Asset) {
                    return null;
                }

                return Transcoder::$plugin->transcode->getGifStatusUrl(
                    $source,
                    self::decodeOptions($arguments['gifOptions'] ?? null, 'gifOptions')
                );
            },
        ];
    }

    /**
     * Return GraphQL args common to video status/url fields.
     *
     * @return array
     */
    private static function getVideoOptionsArgs(): array
    {
        return [
            'videoOptions' => [
                'name' => 'videoOptions',
                'type' => Type::string(),
                'description' => 'JSON encoded Transcoder video options.',
            ],
            'encodingOptions' => [
                'name' => 'encodingOptions',
                'type' => Type::string(),
                'description' => 'JSON encoded Transcoder encoding options.',
            ],
        ];
    }

    /**
     * Return GraphQL args for video status fields.
     *
     * @return array
     */
    private static function getVideoStatusArgs(): array
    {
        return array_merge(self::getVideoOptionsArgs(), self::getStatusArgs());
    }

    /**
     * Return GraphQL args common to GIF status/url fields.
     *
     * @return array
     */
    private static function getGifOptionsArgs(): array
    {
        return [
            'gifOptions' => [
                'name' => 'gifOptions',
                'type' => Type::string(),
                'description' => 'JSON encoded Transcoder GIF options.',
            ],
        ];
    }

    /**
     * Return GraphQL args for GIF status fields.
     *
     * @return array
     */
    private static function getGifStatusArgs(): array
    {
        return array_merge(self::getGifOptionsArgs(), self::getStatusArgs());
    }

    /**
     * Return queue/debug args for status fields.
     *
     * @return array
     */
    private static function getStatusArgs(): array
    {
        return [
            'queueIfMissing' => [
                'name' => 'queueIfMissing',
                'type' => Type::boolean(),
                'defaultValue' => false,
                'description' => 'Whether missing encodes may be queued during this request.',
            ],
            'includeDebug' => [
                'name' => 'includeDebug',
                'type' => Type::boolean(),
                'defaultValue' => false,
                'description' => 'Whether to include debug data as a JSON string.',
            ],
        ];
    }

    /**
     * Return the video status GraphQL type.
     *
     * @return ObjectType
     */
    private static function getVideoStatusType(): ObjectType
    {
        if ($type = GqlEntityRegistry::getEntity('TranscoderVideoStatus')) {
            return $type;
        }

        return GqlEntityRegistry::createEntity('TranscoderVideoStatus', new ObjectType([
            'name' => 'TranscoderVideoStatus',
            'description' => 'Queue-aware Transcoder video status data.',
            'fields' => array_merge(self::getCommonStatusFields(), [
                'posterStatus' => [
                    'name' => 'posterStatus',
                    'type' => Type::string(),
                ],
                'posterProgress' => [
                    'name' => 'posterProgress',
                    'type' => Type::int(),
                    'resolve' => fn(array $source) => self::nullableInt($source['posterProgress'] ?? null),
                ],
                'posterMessage' => [
                    'name' => 'posterMessage',
                    'type' => Type::string(),
                ],
                'posterError' => [
                    'name' => 'posterError',
                    'type' => Type::string(),
                ],
                'posterJobId' => [
                    'name' => 'posterJobId',
                    'type' => Type::string(),
                    'resolve' => fn(array $source) => isset($source['posterJobId']) ? (string)$source['posterJobId'] : null,
                ],
                'posterUrls' => [
                    'name' => 'posterUrls',
                    'type' => Type::listOf(Type::nonNull(self::getPosterUrlType())),
                    'resolve' => fn(array $source) => self::normalizePosterUrls($source['posterUrls'] ?? []),
                ],
            ]),
        ]));
    }

    /**
     * Return the GIF status GraphQL type.
     *
     * @return ObjectType
     */
    private static function getGifStatusType(): ObjectType
    {
        if ($type = GqlEntityRegistry::getEntity('TranscoderGifStatus')) {
            return $type;
        }

        return GqlEntityRegistry::createEntity('TranscoderGifStatus', new ObjectType([
            'name' => 'TranscoderGifStatus',
            'description' => 'Queue-aware Transcoder GIF status data.',
            'fields' => self::getCommonStatusFields(),
        ]));
    }

    /**
     * Return poster URL GraphQL type.
     *
     * @return ObjectType
     */
    private static function getPosterUrlType(): ObjectType
    {
        if ($type = GqlEntityRegistry::getEntity('TranscoderVideoPosterUrl')) {
            return $type;
        }

        return GqlEntityRegistry::createEntity('TranscoderVideoPosterUrl', new ObjectType([
            'name' => 'TranscoderVideoPosterUrl',
            'description' => 'A configured Transcoder video poster URL.',
            'fields' => [
                'handle' => [
                    'name' => 'handle',
                    'type' => Type::nonNull(Type::string()),
                ],
                'url' => [
                    'name' => 'url',
                    'type' => Type::string(),
                ],
            ],
        ]));
    }

    /**
     * Return fields shared by status types.
     *
     * @return array
     */
    private static function getCommonStatusFields(): array
    {
        return [
            'status' => [
                'name' => 'status',
                'type' => Type::string(),
            ],
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
            ],
            'progress' => [
                'name' => 'progress',
                'type' => Type::int(),
                'resolve' => fn(array $source) => self::nullableInt($source['progress'] ?? null),
            ],
            'error' => [
                'name' => 'error',
                'type' => Type::string(),
            ],
            'info' => [
                'name' => 'info',
                'type' => Type::string(),
            ],
            'warning' => [
                'name' => 'warning',
                'type' => Type::string(),
            ],
            'filename' => [
                'name' => 'filename',
                'type' => Type::string(),
            ],
            'jobId' => [
                'name' => 'jobId',
                'type' => Type::string(),
                'resolve' => fn(array $source) => isset($source['jobId']) ? (string)$source['jobId'] : null,
            ],
            'key' => [
                'name' => 'key',
                'type' => Type::string(),
            ],
            'updatedAt' => [
                'name' => 'updatedAt',
                'type' => Type::int(),
                'resolve' => fn(array $source) => self::nullableInt($source['updatedAt'] ?? null),
            ],
            'debugJson' => [
                'name' => 'debugJson',
                'type' => Type::string(),
                'resolve' => fn(array $source) => isset($source['debug']) ? JsonHelper::encode($source['debug']) : null,
            ],
            'rawJson' => [
                'name' => 'rawJson',
                'type' => Type::string(),
                'resolve' => fn(array $source) => JsonHelper::encode($source),
            ],
        ];
    }

    /**
     * Decode a JSON status response.
     *
     * @param string $status
     * @return array
     */
    private static function decodeStatus(string $status): array
    {
        $decoded = JsonHelper::decodeIfJson($status, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode JSON encoded options from GraphQL args.
     *
     * @param mixed $value
     * @param string $argumentName
     * @return array
     */
    private static function decodeOptions(mixed $value, string $argumentName): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        try {
            $decoded = JsonHelper::decode($value);
        } catch (Throwable $e) {
            Craft::warning('Invalid Transcoder GraphQL ' . $argumentName . ' JSON: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize poster URL map into GraphQL list rows.
     *
     * @param mixed $posterUrls
     * @return array
     */
    private static function normalizePosterUrls(mixed $posterUrls): array
    {
        if (!is_array($posterUrls)) {
            return [];
        }

        $result = [];
        foreach ($posterUrls as $handle => $url) {
            $result[] = [
                'handle' => (string)$handle,
                'url' => $url !== null ? (string)$url : '',
            ];
        }

        return $result;
    }

    /**
     * Return an integer when a status value is numeric.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
