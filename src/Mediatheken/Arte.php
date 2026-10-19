<?php

namespace TheiNaD\DSMediatheken\Mediatheken;

use TheiNaD\DSMediatheken\Utils\Mediathek;
use TheiNaD\DSMediatheken\Utils\Result;

/**
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2018-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
class Arte extends Mediathek
{
    public const API_URL_PATTERN = 'https://api.arte.tv/api/player/v2/config/%s/%s';
    protected const PROTOCOL_PROGRESSIVE = 'HTTPS';
    protected const PROTOCOL_HLS_MARKER = 'HLS';

    /**
     * Maps the language of the url to the language code used in the version codes of the streams.
     *
     * @var array
     */
    protected static $LANGUAGE_MAP = [
        'de' => 'A',
        'fr' => 'F',
        'en' => 'E[ANG]',
        'es' => 'E[ESP]',
        'it' => 'E[ITA]',
        'pl' => 'E[POL]',
    ];
    protected static $DEFAULT_LANGUAGE = 'de';

    /**
     * Structure of the version code of a stream, e.g. "VOF-STMF":
     * V (original voice) (voice language) (audio description) -ST (hearing impaired) (subtitle language)
     *
     * @var string
     */
    protected static $VERSION_CODE_PATTERN = '#^V(?P<ov>O?)(?P<voice>[FA]|E\[[A-Z]+\]|EU)?(?P<ad>AUD|)' .
        '(?:(?P<sub>-ST)(?P<sdh>M?)(?P<sublang>[FA]|E\[[A-Z]+\]|EU))?#';
    protected static $VIDEO_URL_PATTERN = '#arte\.tv/(?:guide/)?(?:(?P<lang>[a-z]{2})/)?(?:videos/)?' .
        '(?P<id>\d{6}-\d{3}-[AF])(?:[/?\\#]|$)#i';
    protected static $SUPPORT_MATCHER = 'arte.tv';

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     *
     * @return Result|null
     */
    public function getDownloadInfo($url, $username = '', $password = '')
    {
        $ids = $this->extractLanguageAndVideoId($url);
        if ($ids === null) {
            $this->getLogger()->log('could not find a video id in ' . $url);

            return null;
        }

        list($language, $videoId) = $ids;
        $this->getLogger()->log(sprintf('using language %s and video id %s', $language, $videoId));

        $config = $this->getPlayerConfig($language, $videoId);
        if ($config === null) {
            return null;
        }

        $attributes = $config['data']['attributes'] ?? [];

        if (!empty($attributes['restriction']['geoblocking']['restrictedArea'])) {
            $this->getLogger()->log(
                'the video is restricted to ' . ($attributes['restriction']['geoblocking']['code'] ?? 'some countries')
            );
        }

        if (empty($attributes['rights'])) {
            $this->getLogger()->log('the video is not available in this language or the broadcast rights expired');

            return null;
        }

        $result = $this->getBestSource($attributes['streams'] ?? [], $language);
        if ($result === null) {
            return null;
        }

        $result->setUri($this->getTools()->addProtocolFromUrlIfMissing($result->getUri(), $url));
        $this->addTitle($result, $attributes['metadata'] ?? []);

        return $result;
    }

    /**
     * @param string $url
     *
     * @return array|null language and video id
     */
    protected function extractLanguageAndVideoId($url)
    {
        if (preg_match(static::$VIDEO_URL_PATTERN, $url, $match) !== 1) {
            return null;
        }

        $language = strtolower($match['lang']);

        return [
            isset(static::$LANGUAGE_MAP[$language]) ? $language : static::$DEFAULT_LANGUAGE,
            strtoupper($match['id']),
        ];
    }

    /**
     * @param string $language
     * @param string $videoId
     *
     * @return array|null
     */
    protected function getPlayerConfig($language, $videoId)
    {
        $playerUrl = sprintf(static::API_URL_PATTERN, $language, $videoId);
        $this->getLogger()->log('the player is located at ' . $playerUrl);

        $response = $this->getTools()->curlRequest($playerUrl, [
            CURLOPT_HTTPHEADER => [
                'x-validated-age: 18',
            ],
        ]);
        if ($response === null) {
            return null;
        }

        $config = json_decode($response, true);

        return is_array($config) ? $config : null;
    }

    /**
     * Picks the progressive file with the best matching language. Streams of other protocols (HLS) can not be
     * downloaded by Download Station.
     *
     * @param array  $streams
     * @param string $language
     *
     * @return Result|null
     */
    protected function getBestSource(array $streams, $language)
    {
        $best = null;
        $hasHls = false;

        foreach ($streams as $stream) {
            $protocol = $stream['protocol'] ?? '';
            $version = $stream['versions'][0] ?? [];
            $versionCode = $version['eStat']['ml5'] ?? '';

            $this->getLogger()->log(sprintf(
                'found %s stream "%s" (%s): %s',
                $protocol,
                $versionCode,
                $version['label'] ?? 'unknown',
                $stream['url'] ?? ''
            ));

            // the protocol is e.g. "HLS" or "API_HLS_NG_MA"
            if (stripos($protocol, self::PROTOCOL_HLS_MARKER) !== false) {
                $hasHls = true;
            }

            if ($protocol !== self::PROTOCOL_PROGRESSIVE || empty($stream['url'])) {
                continue;
            }

            $score = [
                $this->getLanguageScore($versionCode, static::$LANGUAGE_MAP[$language]),
                $this->getBitrate($stream),
            ];
            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score, 'url' => $stream['url'], 'bitrate' => $score[1]];
            }
        }

        if ($best === null) {
            $this->getLogger()->log(
                $hasHls
                    ? 'the video is only available as HLS stream, which can not be downloaded by Download Station'
                    : 'no progressive stream found'
            );

            return null;
        }

        $result = new Result();
        $result->setUri($best['url']);
        $result->setBitrateRating($best['bitrate']);

        return $result;
    }

    /**
     * Prefers (in this order) voice in the requested language, no audio description, no subtitles, the original voice,
     * subtitles in the requested language, no subtitles for the hearing impaired.
     *
     * Subtitles may be part of the picture, so a version without them is preferred to one with them.
     *
     * @param string $versionCode
     * @param string $languageCode
     *
     * @return int
     */
    protected function getLanguageScore($versionCode, $languageCode)
    {
        if (preg_match(static::$VERSION_CODE_PATTERN, $versionCode, $match) !== 1) {
            return -1;
        }

        $flags = [
            ($match['voice'] ?? '') === $languageCode,
            $match['ad'] === '',
            ($match['sub'] ?? '') === '',
            $match['ov'] !== '',
            ($match['sublang'] ?? '') === $languageCode,
            ($match['sdh'] ?? '') === '',
        ];

        $score = 0;
        foreach ($flags as $flag) {
            $score = $score * 2 + ($flag ? 1 : 0);
        }

        return $score;
    }

    /**
     * @param array $stream
     *
     * @return int
     */
    protected function getBitrate(array $stream)
    {
        $bitrate = $this->getTools()->pregMatchDefault('#MP4-(\d+)_#', $stream['url'], 0);

        return (int)$bitrate;
    }

    /**
     * @param Result $result
     * @param array  $metadata
     *
     * @return void
     */
    protected function addTitle(Result $result, array $metadata)
    {
        // the subtitle is the title of the episode
        $result->setTitle(trim($metadata['title'] ?? ''));
        $result->setEpisodeTitle(trim($metadata['subtitle'] ?? ''));
    }
}
