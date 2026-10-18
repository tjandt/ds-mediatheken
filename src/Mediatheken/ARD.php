<?php

namespace TheiNaD\DSMediatheken\Mediatheken;

use TheiNaD\DSMediatheken\Utils\Mediathek;
use TheiNaD\DSMediatheken\Utils\Result;

/**
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2017-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
class ARD extends Mediathek
{
    public static $API_BASE_URL = 'https://api.ardmediathek.de/page-gateway/pages/ard/item/';
    /**
     * `mcV6` selects the current layout of the media collection (`streams[].media[]`), the previous one
     * (`_mediaArray[]._mediaStreamArray[]`) is not used by the ARD Mediathek anymore.
     */
    public static $API_QUERY = 'embedded=false&mcV6=true';
    protected static $SUPPORT_MATCHER = 'ardmediathek.de';
    protected static $PLAYER_WIDGET_TYPE = 'player_ondemand';
    protected static $MAIN_STREAM_KIND = 'main';
    protected static $DEFAULT_AUDIO_KINDS = ['', 'standard'];
    protected static $DEFAULT_AUDIO_LANGUAGE = 'deu';

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     *
     * @return Result|null
     */
    public function getDownloadInfo($url, $username = '', $password = '')
    {
        $documentId = $this->getDocumentIdFromUrl($url);
        if ($documentId === null) {
            $this->getLogger()->log('no documentId found in ' . $url);

            return null;
        }

        $apiData = $this->getApiData($documentId);
        if ($apiData === null) {
            $this->getLogger()->log('could not retrieve apiData');

            return null;
        }

        $widget = $this->getPlayerWidget($apiData);
        if ($widget === null) {
            $this->getLogger()->log('no playable video found, is this the url of a single video?');

            return null;
        }

        if (!empty($widget['blockedByFsk'])) {
            $this->getLogger()->log('video is only available for age verified users or after 22:00');

            return null;
        }

        $media = $this->getBestMedia($widget['mediaCollection']['embedded']['streams'] ?? []);
        if ($media === null) {
            $this->getLogger()->log('no progressive mp4 stream found');

            return null;
        }

        $result = new Result();
        $result->setQualityRating($media['height']);
        $result->setUri($this->getTools()->addProtocolFromUrlIfMissing($media['url'], $url));
        $this->addTitle($apiData, $widget, $result);

        $this->getLogger()->log(sprintf(
            'Url "%s" won with a height of "%s"',
            $result->getUri(),
            $result->getQualityRating()
        ));

        return $result;
    }

    /**
     * @param string $url
     *
     * @return string|null
     */
    protected function getDocumentIdFromUrl($url)
    {
        return $this->getTools()->pregMatchDefault('#/([^/?\#]+)/?(?:[?\#].*)?$#i', $url, null);
    }

    /**
     * @param string $documentId
     *
     * @return array|null
     */
    protected function getApiData($documentId)
    {
        $response = $this->getTools()->curlRequest(self::$API_BASE_URL . $documentId . '?' . self::$API_QUERY);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array $apiData
     *
     * @return array|null
     */
    protected function getPlayerWidget(array $apiData)
    {
        foreach ($apiData['widgets'] ?? [] as $widget) {
            if (($widget['type'] ?? null) === self::$PLAYER_WIDGET_TYPE) {
                return $widget;
            }
        }

        return null;
    }

    /**
     * Picks the progressive file, which Download Station is able to download, with the highest resolution.
     * The stream of the main video is preferred over alternatives like sign language,
     * the default audio over alternatives like audio description.
     *
     * @param array $streams
     *
     * @return array|null array with keys url and height
     */
    protected function getBestMedia(array $streams)
    {
        $best = null;

        foreach ($streams as $stream) {
            $isMain = ($stream['kind'] ?? null) === self::$MAIN_STREAM_KIND ? 1 : 0;

            foreach ($stream['media'] ?? [] as $media) {
                $mediaUrl = $media['url'] ?? null;
                if (!is_string($mediaUrl) || !$this->isProgressiveMp4($mediaUrl)) {
                    continue;
                }

                $height = (int)($media['maxVResolutionPx'] ?? 0);
                $candidate = [
                    'url' => $mediaUrl,
                    'height' => $height,
                    'score' => [$isMain, $this->hasDefaultAudio($media) ? 1 : 0, $height],
                ];

                $this->getLogger()->log(sprintf(
                    'Found %s stream with a height of "%d": %s',
                    $stream['kind'] ?? 'unknown',
                    $height,
                    $mediaUrl
                ));

                if ($best === null || $candidate['score'] > $best['score']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * @param string $mediaUrl
     *
     * @return bool
     */
    protected function isProgressiveMp4($mediaUrl)
    {
        $path = parse_url($mediaUrl, PHP_URL_PATH);

        return is_string($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp4';
    }

    /**
     * @param array $media
     *
     * @return bool
     */
    protected function hasDefaultAudio(array $media)
    {
        $audio = $media['audios'][0] ?? [];

        return in_array($audio['kind'] ?? '', self::$DEFAULT_AUDIO_KINDS, true) &&
            ($audio['languageCode'] ?? self::$DEFAULT_AUDIO_LANGUAGE) === self::$DEFAULT_AUDIO_LANGUAGE;
    }

    /**
     * @param array  $apiData
     * @param array  $widget
     * @param Result $result
     *
     * @return void
     */
    protected function addTitle(array $apiData, array $widget, Result $result)
    {
        $meta = $widget['mediaCollection']['embedded']['meta'] ?? [];

        $result->setTitle(trim($meta['seriesTitle'] ?? $widget['show']['title'] ?? ''));
        $result->setEpisodeTitle(trim($meta['title'] ?? $widget['title'] ?? $apiData['title'] ?? ''));
    }
}
