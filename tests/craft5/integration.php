<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\fs\Local;
use craft\gql\interfaces\elements\Asset as AssetInterface;
use craft\helpers\FileHelper;
use craft\models\Volume;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use nystudio107\transcoder\jobs\EncodeGif;
use nystudio107\transcoder\jobs\EncodeVideo;
use nystudio107\transcoder\jobs\GenerateVideoPosters;
use nystudio107\transcoder\jobs\InspectMediaAsset;
use nystudio107\transcoder\migrations\Install;
use nystudio107\transcoder\Transcoder;
use nystudio107\transcoder\utilities\EncodingUtility;

$app = require __DIR__ . '/bootstrap.php';
$app->getQueue()->releaseAll();
$run = 'audit' . bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/transcoder-craft5-test/' . $run;
$videoName = $run . '-video.mp4';
$gifName = $run . '-animated.gif';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function jobs(?string $class = null): array
{
    $result = [];
    foreach ((new Query())->select(['id', 'job'])->from('{{%queue}}')->all() as $row) {
        $job = Craft::$app->getQueue()->serializer->unserialize($row['job']);
        if ($class === null || $job instanceof $class) {
            $result[$row['id']] = $job;
        }
    }
    return $result;
}

function upload(string $path, Volume $volume): Asset
{
    $asset = new Asset([
        'tempFilePath' => $path,
        'newFilename' => basename($path),
        'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
        'volumeId' => $volume->id,
        'scenario' => Asset::SCENARIO_CREATE,
    ]);
    check(Craft::$app->getElements()->saveElement($asset), 'Save uploaded ' . basename($path) . ': ' . json_encode($asset->getErrors()));
    return $asset;
}

$migration = new Install();
$migration->safeUp();
$migration->safeUp();
check($app->getDb()->tableExists('{{%transcoder_runtime_settings}}'), 'Install migration is idempotent');
check(!$app->getDb()->tableExists('{{%transcoder_video_sources}}'), 'No source-generation table is created');

$plugin = new Transcoder('transcoder', $app, array_merge(Transcoder::config(), [
    'basePath' => dirname(__DIR__, 2) . '/src',
    'settings' => [
        'queueVideosOnSave' => true,
        'queueGifsOnSave' => true,
        'autoCropVideoBlackBars' => true,
        'enableVideoWatermark' => true,
        'videoWatermarkPath' => $root . '/watermark.png',
        'videoWatermarkWidth' => 40,
        'videoWatermarkHeight' => 40,
        'createSubfolders' => false,
        'videoQueueDelaySeconds' => 0,
        'gifQueueDelaySeconds' => 0,
        'videoPosterQueueDelaySeconds' => 0,
        'videoPosterFormats' => [['handle' => 'wide', 'width' => 160, 'height' => 90, 'timeInSecs' => 3]],
        'transcoderPaths' => ['default' => $root . '/output/'],
        'transcoderUrls' => ['default' => 'https://transcoder.test/' . $run . '/output/'],
    ],
]));
check($plugin->getSettings()->validate(), 'Settings validate on Craft 5');
check(in_array(EncodingUtility::class, $app->getUtilities()->getAllUtilityTypes(), true), 'Utility registers through the Craft 5 event');
$plugin->runtimeSettings->setEncodingEnabled(false);
check(!$plugin->runtimeSettings->isEncodingEnabled(), 'Runtime disable persists');
$plugin->runtimeSettings->setEncodingEnabled(true);
check($plugin->runtimeSettings->isEncodingEnabled(), 'Runtime enable persists');

FileHelper::createDirectory($root);
$fs = new Local(['name' => 'Audit filesystem', 'handle' => $run, 'path' => $root, 'hasUrls' => true, 'url' => 'https://transcoder.test/' . $run]);
$saved = $app->getFs()->saveFilesystem($fs);
check($saved, 'Save local filesystem: ' . json_encode($fs->getErrors()));
$volume = new Volume(['name' => 'Audit videos ' . $run, 'handle' => $run, 'fsHandle' => $run, 'subpath' => 'volume-subpath']);
$saved = $app->getVolumes()->saveVolume($volume);
check($saved, 'Save volume with filesystem subpath: ' . json_encode($volume->getErrors()));

exec('ffmpeg -v error -f lavfi -i testsrc2=size=320x180:rate=10 -vf pad=320:320:0:70:black -t 2 -c:v libx264 -pix_fmt yuv420p -y ' . escapeshellarg($root . '/' . $videoName), $output, $exit);
check($exit === 0, 'Generate video fixture');
exec('ffmpeg -v error -f lavfi -i color=red:size=32x32 -frames:v 1 -threads 1 -y ' . escapeshellarg($root . '/watermark.png'), $output, $exit);
check($exit === 0, 'Generate watermark fixture');
exec('ffmpeg -v error -i ' . escapeshellarg($root . '/' . $videoName) . ' -y ' . escapeshellarg($root . '/' . $gifName), $output, $exit);
check($exit === 0, 'Generate GIF fixture');

$video = upload($root . '/' . $videoName, $volume);
check(count(jobs(InspectMediaAsset::class)) === 1, 'One inspection job after video upload');
check(count(jobs(EncodeVideo::class)) === 0, 'Upload does not inspect output or enqueue encoders directly');
$video->trigger(Asset::EVENT_AFTER_SAVE, new ModelEvent(['isNew' => true]));
check(count(jobs(InspectMediaAsset::class)) === 1, 'Duplicate callback does not add an inspection job');
$video->title = 'Changed metadata';
$video->setScenario(Asset::SCENARIO_DEFAULT);
check($app->getElements()->saveElement($video), 'Save existing video metadata');
check(count(jobs(InspectMediaAsset::class)) === 1, 'Metadata save does not enqueue');
$video->resaving = true;
check($app->getElements()->saveElement($video), 'Save asset during bulk resave');
check(count(jobs(InspectMediaAsset::class)) === 1, 'Bulk resave does not enqueue');
$video->resaving = false;

$inspection = array_values(jobs(InspectMediaAsset::class))[0];
$inspection->execute($app->getQueue());
check(count(jobs(EncodeVideo::class)) === 1, 'Inspection queues EncodeVideo');
check(count(jobs(GenerateVideoPosters::class)) === 1, 'Inspection queues posters');
$inspection->execute($app->getQueue());
check(count(jobs(EncodeVideo::class)) === 1 && count(jobs(GenerateVideoPosters::class)) === 1, 'Repeated inspection preserves queue deduplication');

$gif = upload($root . '/' . $gifName, $volume);
check(count(jobs(InspectMediaAsset::class)) === 2, 'GIF upload queues one inspection');
foreach (jobs(InspectMediaAsset::class) as $job) {
    if ($job->assetId === $gif->id) {
        $job->execute($app->getQueue());
    }
}
check(count(jobs(EncodeGif::class)) === 1, 'GIF inspection queues EncodeGif');
file_put_contents($root . '/document.txt', 'Non-media fixture');
upload($root . '/document.txt', $volume);
check(count(jobs(InspectMediaAsset::class)) === 2, 'Non-media upload does not enqueue');

// Run real FFmpeg jobs while retaining the DB queue rows for deduplication assertions.
foreach ([EncodeVideo::class, GenerateVideoPosters::class, EncodeGif::class] as $class) {
    foreach (jobs($class) as $job) {
        $job->execute($app->getQueue());
    }
    echo "PASS: Executed $class\n";
}
$status = json_decode($plugin->transcode->getVideoStatus($video), true);
check($status['status'] === 'ok', 'Video output discovered after encoding');
check(basename(parse_url($status['url'], PHP_URL_PATH)) === $videoName, 'Source naming remains unchanged');
$encodedInfo = $plugin->transcode->getFileInfo($root . '/output/' . $videoName);
check($encodedInfo['streams'][0]['height'] < 320, 'Black-bar crop and watermark filters complete successfully');
check(json_decode($plugin->transcode->getGifStatus($gif), true)['status'] === 'ok', 'GIF output discovered');
check($plugin->transcode->getVideoPosterUrl($video, 'wide') !== '', 'Short video poster is generated');
exec('ffmpeg -v error -f lavfi -i sine=frequency=440:duration=1 -y ' . escapeshellarg($root . '/tone.wav'), $output, $exit);
check($exit === 0, 'Generate audio fixture');
$audioUrl = $plugin->transcode->getAudioUrl($root . '/tone.wav', ['audioEncoder' => 'mp3', 'synchronous' => true]);
check($audioUrl !== '' && is_file($root . '/output/' . basename($audioUrl)), 'Audio helper honors default output path fallback');

// Craft 5 stores Matrix content as nested Entries, with per-layout field handles.
$mediaField = new craft\fields\Assets(['name' => $run, 'handle' => $run . 'Media']);
check($app->getFields()->saveField($mediaField), 'Save Assets field');
$nestedType = new craft\models\EntryType(['name' => $run . 'Media', 'handle' => $run . 'Media']);
$nestedLayout = new craft\models\FieldLayout(['type' => Entry::class]);
$nestedLayout->setTabs([['name' => 'Content', 'elements' => [
    new craft\fieldlayoutelements\entries\EntryTitleField(),
    new craft\fieldlayoutelements\CustomField($mediaField, ['handle' => 'clips']),
]]]);
$nestedType->setFieldLayout($nestedLayout);
check($app->getEntries()->saveEntryType($nestedType), 'Save nested entry type');
$matrix = new craft\fields\Matrix(['name' => $run . 'Content', 'handle' => $run . 'Content', 'entryTypes' => [$nestedType]]);
check($app->getFields()->saveField($matrix), 'Save Matrix field');
$articleType = new craft\models\EntryType(['name' => $run . 'Article', 'handle' => $run . 'Article']);
$layout = new craft\models\FieldLayout(['type' => Entry::class]);
$layout->setTabs([['name' => 'Content', 'elements' => [
    new craft\fieldlayoutelements\entries\EntryTitleField(),
    new craft\fieldlayoutelements\CustomField($matrix),
]]]);
$articleType->setFieldLayout($layout);
check($app->getEntries()->saveEntryType($articleType), 'Save article entry type');
$section = new craft\models\Section([
    'name' => $run, 'handle' => $run, 'type' => 'channel', 'entryTypes' => [$articleType],
    'siteSettings' => [new craft\models\Section_SiteSettings(['siteId' => $app->getSites()->getPrimarySite()->id, 'hasUrls' => false])],
]);
check($app->getEntries()->saveSection($section), 'Save article section');
$article = new Entry(['sectionId' => $section->id, 'typeId' => $articleType->id, 'title' => 'Containing article']);
check($app->getElements()->saveElement($article), 'Save new article');
$nested = new Entry(['fieldId' => $matrix->id, 'typeId' => $nestedType->id, 'title' => 'Nested media']);
$nested->setOwner($article);
$nested->setFieldValue('clips', [$video->id, $gif->id]);
check($app->getElements()->saveElement($nested), 'Save nested Matrix entry with media relations');
$article->title = 'Containing article updated';
check($app->getElements()->saveElement($article), 'Resave existing article');
check(count(jobs(InspectMediaAsset::class)) === 2, 'Entry and nested Entry saves do not queue inspections');
$article->resaving = true;
check($app->getElements()->saveElement($article), 'Bulk resave existing article');
check(count(jobs(InspectMediaAsset::class)) === 2, 'Entry bulk resave does not queue inspections');
$article->resaving = false;
check($plugin->transcode->getAssetOwnerTitle($video) === $article->title, 'Queue title resolves containing article through nested Matrix Entry');
$found = $plugin->transcode->getVideoAssetsForElement(Entry::find()->id($article->id)->one());
check(count($found) === 1 && $found[0]->id === $video->id, 'Explicit scanning supports Matrix and overridden field handle');

$consoleRequest = $app->getRequest();
$consoleResponse = $app->getResponse();
$app->set('request', new craft\web\Request(['cookieValidationKey' => 'test-only-key']));
$app->set('response', new craft\web\Response());
$app->getView()->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
$html = $app->getView()->renderTemplate('transcoder/settings', ['plugin' => $plugin, 'settings' => $plugin->getSettings()]);
check(str_contains($html, 'videoWatermarkPath') && str_contains($html, 'tab-video-posters'), 'Craft 5 settings template renders');
check(str_contains(EncodingUtility::contentHtml(), 'transcoder/runtime-settings/save'), 'Runtime utility template renders');
$app->set('request', $consoleRequest);
$app->set('response', $consoleResponse);

// Use the fields registered on Craft's actual Asset GraphQL interface.
$fields = AssetInterface::getType()->getFields();
check(isset($fields['transcoderVideoStatus'], $fields['transcoderGifStatus']), 'GraphQL fields register on AssetInterface');
$assetType = new ObjectType(['name' => 'AuditAsset', 'fields' => $fields]);
$schema = new Schema(['query' => new ObjectType(['name' => 'AuditQuery', 'fields' => [
    'asset' => ['type' => $assetType, 'resolve' => fn() => $video],
]])]);
$result = GraphQL::executeQuery($schema, '{ asset { transcoderVideoStatus { status url } transcoderVideoPosterUrls { handle url } } }')->toArray();
check(!isset($result['errors']) && $result['data']['asset']['transcoderVideoStatus']['status'] === 'ok', 'GraphQL executes status and poster resolvers: ' . json_encode($result));
$scopes = $app->getGql()->getAllSchemaComponents();
check(isset($scopes['queries']['Transcoder']['transcoder:encode'], $scopes['queries']['Transcoder']['transcoder:debug']), 'GraphQL action permissions register');
$app->getGql()->setActiveSchema(new craft\models\GqlSchema(['scope' => []]));
$denied = GraphQL::executeQuery($schema, '{ asset { transcoderVideoStatus(queueIfMissing: true) { status } transcoderGifStatus(includeDebug: true) { status } transcoderVideoPosterUrls(generate: true) { url } } }')->toArray();
check(count($denied['errors'] ?? []) === 3, 'Read-only schema cannot request encoding, posters, or diagnostics');
$decode = new ReflectionMethod(nystudio107\transcoder\gql\TranscoderGql::class, 'decodeStatus');
$publicFailure = $decode->invoke(null, json_encode(['status' => 'error', 'error' => 'ffmpeg /private/source.mp4', 'info' => '/private/source.mp4', 'ffmpegCommand' => 'ffmpeg /private/source.mp4']));
check(!str_contains(json_encode($publicFailure), '/private/'), 'Read-only rawJson cannot leak failure diagnostics');
$app->getGql()->setActiveSchema(new craft\models\GqlSchema(['scope' => ['transcoder:encode', 'transcoder:debug']]));
$allowed = GraphQL::executeQuery($schema, '{ asset { transcoderVideoStatus(queueIfMissing: true, includeDebug: true) { status debugJson } } }')->toArray();
check(!isset($allowed['errors']) && $allowed['data']['asset']['transcoderVideoStatus']['debugJson'] !== null, 'Explicitly trusted schema can request encoding and diagnostics');

$missing = new InspectMediaAsset(['assetId' => PHP_INT_MAX, 'maxRetries' => 1, 'retryDelaySeconds' => 5]);
$missing->execute($app->getQueue());
check(count(jobs(InspectMediaAsset::class)) === 3, 'Unavailable source schedules an asynchronous retry');
try {
    (new InspectMediaAsset(['assetId' => PHP_INT_MAX, 'attempt' => 2, 'maxRetries' => 1]))->execute($app->getQueue());
    throw new LogicException('Expected exhausted retry to fail');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'stopped after 2 attempts'), 'Retry limit fails visibly');
}

$migration->safeDown();
$app->getDb()->getSchema()->refresh();
check(!$app->getDb()->tableExists('{{%transcoder_runtime_settings}}'), 'Uninstall removes runtime table');
$migration->safeUp();
echo "Craft 5 integration checks passed.\n";
