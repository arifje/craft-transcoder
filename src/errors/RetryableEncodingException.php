<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\errors;

use RuntimeException;

/**
 * Thrown by queue jobs for transient failures that should be retried,
 * independent of how the message is translated.
 */
class RetryableEncodingException extends RuntimeException
{
}
