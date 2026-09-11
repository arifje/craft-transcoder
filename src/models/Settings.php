<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\models;

use craft\base\Model;
use craft\validators\ArrayValidator;

/**
 * Transcoder Settings model
 *
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * The path to the ffmpeg binary
     *
     * @var string
     */

    public string $ffmpegPath = '/usr/bin/ffmpeg';

    /**
     * The path to the ffprobe binary
     *
     * @var string
     */
    public string $ffprobePath = '/usr/bin/ffprobe';

    /**
     * The options to use for ffprobe
     *
     * @var string
     */
    public string $ffprobeOptions = '-v quiet -print_format json -show_format -show_streams';

    /**
     * The path where the transcoded videos are stored; must have a trailing /
     * Yii2 aliases are supported here
     *
     * @var array
     */
    public array $transcoderPaths = [
        'default' => '@webroot/transcoder/',
        'video' => '@webroot/transcoder/',
        'audio' => '@webroot/transcoder/',
        'thumbnail' => '@webroot/transcoder/',
        'gif' => '@webroot/transcoder/',
    ];

    /**
     * The URL where the transcoded videos are stored; must have a trailing /
     * Yii2 aliases are supported here
     *
     * @var array
     */
    public array $transcoderUrls = [
        'default' => '@web/transcoder/',
        'video' => '@web/transcoder/',
        'audio' => '@web/transcoder/',
        'thumbnail' => '@web/transcoder/',
        'gif' => '@web/transcoder/',
    ];

    /**
     * @var bool Determines whether the download file endpoint should be enabled for anonymous frontend access
     */
    public bool $enableDownloadFileEndpoint = false;

    /**
     * @var bool Determines whether video encoding should be enabled
     */
    public bool $enableVideoEncoding = true;

    /**
     * @var bool Detect and crop black bars from uploaded videos before encoding
     */
    public bool $autoCropVideoBlackBars = false;

    /**
     * @var bool Determines whether encoded videos should receive a watermark
     */
    public bool $enableVideoWatermark = false;

    /**
     * @var int|string|array|null Legacy selected Craft asset ID fallback for the video watermark image
     */
    public int|string|array|null $videoWatermarkAsset = null;

    /**
     * @var string|bool|null Optional local path/alias used before the URL and legacy asset fallback
     */
    public string|bool|null $videoWatermarkPath = '';

    /**
     * @var string|bool|null Optional remote URL used before the legacy asset fallback
     */
    public string|bool|null $videoWatermarkUrl = '';

    /**
     * @var int|string Watermark width at 720px video width, or empty for original width at that baseline
     */
    public int|string $videoWatermarkWidth = '';

    /**
     * @var int|string Watermark height at 720px video width, or empty for original height at that baseline
     */
    public int|string $videoWatermarkHeight = '';

    /**
     * @var string Watermark placement on the encoded video
     */
    public string $videoWatermarkPosition = 'bottom-right';

    /**
     * @var int Watermark top padding in pixels
     */
    public int $videoWatermarkPaddingTop = 24;

    /**
     * @var int Watermark right padding in pixels
     */
    public int $videoWatermarkPaddingRight = 24;

    /**
     * @var int Watermark bottom padding in pixels
     */
    public int $videoWatermarkPaddingBottom = 24;

    /**
     * @var int Watermark left padding in pixels
     */
    public int $videoWatermarkPaddingLeft = 24;

    /**
     * @var int Watermark opacity percentage
     */
    public int $videoWatermarkOpacity = 100;

    /**
     * @var string Watermark animation style
     */
    public string $videoWatermarkAnimation = 'none';

    /**
     * @var bool Move the watermark between positions during the video
     */
    public bool $videoWatermarkReposition = false;

    /**
     * @var int Seconds between watermark position changes
     */
    public int $videoWatermarkRepositionInterval = 10;

    /**
     * @var array Positions to cycle through when watermark repositioning is enabled
     */
    public array $videoWatermarkRepositionPositions = [
        'top-left',
        'top-right',
        'bottom-right',
        'bottom-left',
    ];

    /**
     * @var bool Determines whether video poster generation should be enabled
     */
    public bool $enableVideoPosters = true;

    /**
     * @var bool Adds a blurred cover background behind fitted video posters to avoid black bars
     */
    public bool $preventVideoPosterBlackBars = false;

    /**
     * @var bool Determines whether GIF encoding should be enabled
     */
    public bool $enableGifEncoding = true;

    /**
     * @var array|string Server names allowed to start encode work from web requests.
     */
    public array|string $encodingServerNames = [];

    /**
     * Use a md5 hash for the filenames instead of parameterized naming
     *
     * @var bool
     */
    public bool $useHashedNames = false;

    /**
     * How encoded video filenames should be generated: source or options.
     *
     * @var string
     */
    public string $videoFilenameStrategy = 'source';

    /**
     * if a upload location has a subfolder defined, add this to the transcoder
     * paths too
     *
     * @var bool
     */
    public bool $createSubfolders = true;
	
	/**
	 * get the subfolder from an url segment if a url is pased as argument instead of an asset object 
	 * set to false if disabled
	 * @var bool
	 */
	public int|bool $subfolderUrlSegment = false;
	
    /**
     * clear caches when somebody clears all caches from the CP?
     *
     * @var bool
     */
    public bool $clearCaches = false;

    /**
     * Queue video encoding when new video assets are uploaded outside a Craft
     * bulk resave or upgrade operation.
     *
     * @var bool
     */
    public bool $queueVideosOnSave = false;

    /**
     * Deprecated alias for queueVideosOnSave.
     *
     * This alias only enables new asset upload processing; Entry saves are not
     * inspected automatically.
     *
     * @var bool
     */
    public bool $queueVideosOnEntrySave = false;

    /**
     * Number of retries after the initial asynchronous asset inspection.
     *
     * @var int
     */
    public int $mediaInspectionMaxRetries = 5;

    /**
     * Seconds to wait before retrying asynchronous source inspection.
     *
     * @var int
     */
    public int $mediaInspectionRetryDelaySeconds = 10;

    /**
     * Seconds Craft should reserve for video-related queue jobs before timing them out.
     *
     * @var int
     */
    public int $videoQueueTtrSeconds = 1800;

    /**
     * Seconds to delay video encode jobs queued after asynchronous upload inspection.
     *
     * @var int
     */
    public int $videoQueueDelaySeconds = 10;

    /**
     * Maximum active video/poster FFmpeg jobs on each encoding server.
     *
     * @var int
     */
    public int $videoMaxConcurrentJobs = 1;

    /**
     * Seconds before a capacity-limited queue job tries again.
     *
     * @var int
     */
    public int $encodingConcurrencyRetryDelaySeconds = 15;

    /**
     * Number of retries after the initial video encode attempt fails.
     *
     * @var int
     */
    public int $videoEncodeMaxRetries = 2;

    /**
     * Seconds to wait before retrying a failed video encode job.
     *
     * @var int
     */
    public int $videoEncodeRetryDelaySeconds = 120;

    /**
     * Entry field handles used by explicit element-scanning API calls. Leave
     * empty to inspect all custom fields recursively when those methods are
     * called manually. Entry saves never invoke them automatically.
     *
     * @var array
     */
    public array $autoEncodeVideoFieldHandles = [];

    /**
     * Default options used when an asset upload queues video encoding.
     *
     * @var array
     */
    public array $autoEncodeVideoOptions = [];

    /**
     * Extra options recorded with queued encodes. These are included in the
     * status key so future output-affecting options can be distinguished.
     *
     * @var array
     */
    public array $autoEncodeEncodingOptions = [];

    /**
     * Queue GIF encoding when new GIF assets are uploaded outside a Craft bulk
     * resave or upgrade operation.
     *
     * @var bool
     */
    public bool $queueGifsOnSave = false;

    /**
     * Deprecated alias for queueGifsOnSave.
     *
     * This alias only enables new asset upload processing; Entry saves are not
     * inspected automatically.
     *
     * @var bool
     */
    public bool $queueGifsOnEntrySave = false;

    /**
     * Entry field handles used by explicit element-scanning API calls. Leave
     * empty to inspect all custom fields recursively when those methods are
     * called manually. Entry saves never invoke them automatically.
     *
     * @var array
     */
    public array $autoEncodeGifFieldHandles = [];

    /**
     * Default options used when an asset upload queues GIF encoding.
     *
     * @var array
     */
    public array $autoEncodeGifOptions = [];

    /**
     * Seconds to delay each queued GIF job after the previous one.
     *
     * @var int
     */
    public int $gifQueueDelaySeconds = 15;

    /**
     * Maximum active GIF FFmpeg jobs on each encoding server.
     *
     * @var int
     */
    public int $gifMaxConcurrentJobs = 4;

    /**
     * Number of retries after the initial video poster generation attempt fails.
     *
     * @var int
     */
    public int $videoPosterMaxRetries = 2;

    /**
     * Seconds to wait before retrying a failed video poster generation job.
     *
     * @var int
     */
    public int $videoPosterRetryDelaySeconds = 120;

    /**
     * Seconds to wait before starting newly queued video poster generation jobs.
     *
     * @var int
     */
    public int $videoPosterQueueDelaySeconds = 5;

    /**
     * Poster formats generated when videos are queued.
     *
     * @var array
     */
    public array $videoPosterFormats = [
        '16_9' => [
            'width' => 800,
            'height' => 450,
            'timeInSecs' => 3,
        ],
        'original_3s' => [
            'timeInSecs' => 3,
        ],
        'original_1s' => [
            'timeInSecs' => 1,
        ],
    ];

    /**
     * Preset video encoders
     *
     * @var array
     */
    public array $videoEncoders = [
        'h264' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-vprofile high -preset slow -crf 22',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'webm' => [
            'fileSuffix' => '.webm',
            'fileFormat' => 'webm',
            'videoCodec' => 'libvpx',
            'videoCodecOptions' => '-quality good -cpu-used 0',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'gif' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-pix_fmt yuv420p -movflags +faststart -filter:v crop=\'floor(in_w/2)*2:floor(in_h/2)*2\' ',
            'threads' => '0',
        ],
    ];

    /**
     * Preset audio encoders
     *
     * @var array
     */
    public array $audioEncoders = [
        'mp3' => [
            'fileSuffix' => '.mp3',
            'fileFormat' => 'mp3',
            'audioCodec' => 'libmp3lame',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
        'aac' => [
            'fileSuffix' => '.m4a',
            'fileFormat' => 'aac',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '',
            'threads' => '0',

        ],
        'ogg' => [
            'fileSuffix' => '.ogg',
            'fileFormat' => 'ogg',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
    ];

    /**
     * Default options for encoded videos
     *
     * @var array
     */
    public array $defaultVideoOptions = [
        // Video settings
        'videoEncoder' => 'h264',
        'videoBitRate' => '800k',
        'videoFrameRate' => 15,
        // Audio settings
        'audioBitRate' => '',
        'audioSampleRate' => '',
        'audioChannels' => '',
        // Spatial settings
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ];

    /**
     * Default options for video thumbnails
     *
     * @var array
     */
    public array $defaultThumbnailOptions = [
        'fileSuffix' => '.jpg',
        'timeInSecs' => 10,
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ];

    /**
     * Default options for encoded videos
     *
     * @var array
     */
    public array $defaultAudioOptions = [
        'audioEncoder' => 'mp3',
        'audioBitRate' => '128k',
        'audioSampleRate' => '44100',
        'audioChannels' => '2',
        'synchronous' => false,
        'stripMetadata' => false
    ];

    /**
     * Default options for encoded GIF
     *
     * @var array
     */
    public array $defaultGifOptions = [
        'videoEncoder' => 'gif',
        'fileSuffix' => '',
        'fileFormat' => '',
        'videoCodec' => '',
        'videoCodecOptions' => '',
    ];

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        // Unset any deprecated properties
        if (!empty($config)) {
            // If the old properties are set, remap them to the default
            if (isset($config['transcoderPath'])) {
                $config['transcoderPaths']['default'] = $config['transcoderPath'];
                unset($config['transcoderPath']);
            }
            if (isset($config['transcoderUrl'])) {
                $config['transcoderUrls']['default'] = $config['transcoderUrl'];
                unset($config['transcoderUrl']);
            }
        }
        parent::__construct($config);
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['ffmpegPath', 'string'],
            ['ffmpegPath', 'required'],
            ['ffprobePath', 'string'],
            ['ffprobePath', 'required'],
            ['ffprobeOptions', 'string'],
            ['ffprobeOptions', 'safe'],
            ['transcoderPaths', ArrayValidator::class],
            ['transcoderPaths', 'required'],
            ['transcoderUrls', ArrayValidator::class],
            ['enableDownloadFileEndpoint', 'boolean'],
            ['enableVideoEncoding', 'boolean'],
            ['autoCropVideoBlackBars', 'boolean'],
            ['enableVideoWatermark', 'boolean'],
            ['videoWatermarkAsset', 'safe'],
            [['videoWatermarkPath', 'videoWatermarkUrl'], 'safe'],
            [['videoWatermarkWidth', 'videoWatermarkHeight'], 'safe'],
            ['videoWatermarkPosition', 'in', 'range' => [
                'top-left',
                'top-center',
                'top-right',
                'center-left',
                'center',
                'center-right',
                'bottom-left',
                'bottom-center',
                'bottom-right',
            ]],
            [[
                'videoWatermarkPaddingTop',
                'videoWatermarkPaddingRight',
                'videoWatermarkPaddingBottom',
                'videoWatermarkPaddingLeft',
                'videoWatermarkOpacity',
                'videoWatermarkRepositionInterval',
            ], 'integer'],
            [[
                'videoWatermarkPaddingTop',
                'videoWatermarkPaddingRight',
                'videoWatermarkPaddingBottom',
                'videoWatermarkPaddingLeft',
                'videoWatermarkRepositionInterval',
            ], 'number', 'min' => 0],
            ['videoWatermarkOpacity', 'number', 'min' => 0, 'max' => 100],
            ['videoWatermarkAnimation', 'in', 'range' => [
                'none',
                'fade-in',
                'fade-out',
                'fade-in-out',
                'rotate',
                'pulse',
            ]],
            ['videoWatermarkReposition', 'boolean'],
            ['videoWatermarkRepositionPositions', ArrayValidator::class],
            ['enableVideoPosters', 'boolean'],
            ['preventVideoPosterBlackBars', 'boolean'],
            ['enableGifEncoding', 'boolean'],
            ['encodingServerNames', 'safe'],
            ['useHashedNames', 'boolean'],
            ['videoFilenameStrategy', 'in', 'range' => ['source', 'options']],
            ['createSubfolders', 'boolean'],
            ['clearCaches', 'boolean'],
            [['queueVideosOnSave', 'queueVideosOnEntrySave'], 'boolean'],
            [['mediaInspectionMaxRetries', 'mediaInspectionRetryDelaySeconds'], 'integer'],
            [['mediaInspectionMaxRetries', 'mediaInspectionRetryDelaySeconds'], 'number', 'min' => 0],
            [[
                'videoQueueTtrSeconds',
                'videoQueueDelaySeconds',
                'videoMaxConcurrentJobs',
                'gifMaxConcurrentJobs',
                'encodingConcurrencyRetryDelaySeconds',
            ], 'integer'],
            ['videoQueueTtrSeconds', 'number', 'min' => 1],
            [[
                'videoMaxConcurrentJobs',
                'gifMaxConcurrentJobs',
                'encodingConcurrencyRetryDelaySeconds',
            ], 'number', 'min' => 1],
            ['videoQueueDelaySeconds', 'number', 'min' => 0],
            [['videoEncodeMaxRetries', 'videoEncodeRetryDelaySeconds'], 'integer'],
            [['videoEncodeMaxRetries', 'videoEncodeRetryDelaySeconds'], 'number', 'min' => 0],
            ['autoEncodeVideoFieldHandles', ArrayValidator::class],
            ['autoEncodeVideoOptions', ArrayValidator::class],
            ['autoEncodeEncodingOptions', ArrayValidator::class],
            [['queueGifsOnSave', 'queueGifsOnEntrySave'], 'boolean'],
            ['autoEncodeGifFieldHandles', ArrayValidator::class],
            ['autoEncodeGifOptions', ArrayValidator::class],
            ['gifQueueDelaySeconds', 'integer'],
            ['gifQueueDelaySeconds', 'number', 'min' => 0],
            [['videoPosterMaxRetries', 'videoPosterRetryDelaySeconds', 'videoPosterQueueDelaySeconds'], 'integer'],
            [['videoPosterMaxRetries', 'videoPosterRetryDelaySeconds', 'videoPosterQueueDelaySeconds'], 'number', 'min' => 0],
            ['videoPosterFormats', ArrayValidator::class],
            ['videoEncoders', 'required'],
            ['audioEncoders', 'required'],
            ['defaultVideoOptions', 'required'],
            ['defaultThumbnailOptions', 'required'],
            ['defaultAudioOptions', 'required'],
        ];
    }
}
