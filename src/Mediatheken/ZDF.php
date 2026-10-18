<?php

namespace TheiNaD\DSMediatheken\Mediatheken;

use TheiNaD\DSMediatheken\Utils\Mediathek;
use TheiNaD\DSMediatheken\Utils\Result;

/**
 * Uses the same APIs as the apps of the ZDF Mediathek:
 *
 * 1. an API token is requested
 * 2. the GraphQL API tells the metadata and the url template of the playlist (PTMD) of the video
 * 3. the PTMD lists all the files of the video
 *
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2017-2022 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
class ZDF extends Mediathek
{
    public const TOKEN_URL = 'https://zdf-prod-futura.zdf.de/mediathekV2/token';
    public const GRAPHQL_URL = 'https://api.zdf.de/graphql';
    public const API_BASE_URL = 'https://api.zdf.de';
    protected const PLAYER_ID = 'android_native_6';
    protected const PLACEHOLDER_PLAYER_ID = '{playerId}';
    protected const API_AUTH_HEADER = 'Api-Auth';
    protected const VOD_MEDIA_TYPE_SIGN_LANGUAGE = 'DGS';
    protected const GRAPHQL_QUERY = <<<'GRAPHQL'
query VideoByCanonical($canonical: String!) {
  videoByCanonical(canonical: $canonical) {
    canonical
    title
    smartCollection {
      canonical
      title
    }
    currentMedia {
      nodes {
        ptmdTemplate
        ... on VodMedia {
          vodMediaType
        }
        ... on LiveMedia {
          liveMediaType
        }
        id
      }
    }
  }
}
GRAPHQL;

    /**
     * Facets containing one of these have restrictions and are only used if there is nothing else.
     *
     * @var array
     */
    protected static $RESTRICTED_FACETS = [
        'hbbtv',
        'restriction_useragent',
    ];
    /**
     * Maps mimetype extensions to ratings, a higher rating is preferred.
     * Only progressive files can be downloaded by Download Station, so other formats (HLS, DASH) are not listed.
     *
     * @var array
     */
    protected static $EXTENSION_RATING = [
        'webm' => 1,
        'mp4' => 2,
    ];
    /**
     * Fallback for qualities without a `highestVerticalResolution`, maps qualities to an approximate height.
     *
     * @var array
     */
    protected static $QUALITY_HEIGHT = [
        'low' => 288,
        'med' => 360,
        'high' => 540,
        'veryhigh' => 720,
        'hd' => 1080,
        'fhd' => 1080,
        'uhd' => 2160,
    ];
    protected static $SUPPORTED_LANGUAGE = 'deu';
    protected static $MAIN_TRACK_CLASS = 'main';
    protected static $SUPPORT_MATCHER = 'zdf.de';

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     *
     * @return Result|null
     */
    public function getDownloadInfo($url, $username = '', $password = '')
    {
        $canonical = $this->getCanonicalFromUrl($url);
        if ($canonical === null) {
            $this->getLogger()->log('no video id found in ' . $url);

            return null;
        }

        $this->getLogger()->log('video id is ' . $canonical);

        $apiToken = $this->getApiToken();
        if ($apiToken === null) {
            $this->getLogger()->log('could not retrieve an API token');

            return null;
        }

        $video = $this->getVideo($canonical, $apiToken);
        if ($video === null) {
            $this->getLogger()->log('could not retrieve the video "' . $canonical . '"');

            return null;
        }

        $result = $this->getBestResult($video, $apiToken);
        if ($result === null) {
            $this->getLogger()->log('no suitable file found');

            return null;
        }

        $result->setUri($this->getTools()->addProtocolFromUrlIfMissing($result->getUri(), $url));
        $this->addTitle($video, $result);

        return $result;
    }

    /**
     * Both the current urls (/video/.../id and /play/.../id) and the previous ones (/.../id.html) end with the id.
     *
     * @param string $url
     *
     * @return string|null
     */
    protected function getCanonicalFromUrl($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $canonical = preg_replace('#\.html$#i', '', basename(rtrim($path, '/')));

        return $canonical === '' || $canonical === '.' ? null : $canonical;
    }

    /**
     * @return string|null value for the API auth header, e.g. "Bearer abc"
     */
    protected function getApiToken()
    {
        $response = $this->getTools()->curlRequest(self::TOKEN_URL);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['type'], $data['token']) || !is_string($data['type']) || !is_string($data['token'])) {
            return null;
        }

        return $data['type'] . ' ' . $data['token'];
    }

    /**
     * @param string $canonical
     * @param string $apiToken
     *
     * @return array|null
     */
    protected function getVideo($canonical, $apiToken)
    {
        $response = $this->getTools()->curlRequest(self::GRAPHQL_URL, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'operationName' => 'VideoByCanonical',
                'query' => self::GRAPHQL_QUERY,
                'variables' => ['canonical' => $canonical],
            ]),
            CURLOPT_HTTPHEADER => [
                self::API_AUTH_HEADER . ': ' . $apiToken,
                'Apollo-Require-Preflight: true',
                'Content-Type: application/json',
            ],
        ]);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        return $data['data']['videoByCanonical'] ?? null;
    }

    /**
     * A video can consist of multiple nodes, e.g. the regular one and one with a sign language interpreter.
     * The regular ones are preferred, the next node is only requested if the previous one had no suitable file.
     *
     * @param array  $video
     * @param string $apiToken
     *
     * @return Result|null
     */
    protected function getBestResult(array $video, $apiToken)
    {
        $nodes = $video['currentMedia']['nodes'] ?? [];

        usort($nodes, function ($a, $b) {
            return $this->isSignLanguage($a) <=> $this->isSignLanguage($b);
        });

        foreach ($nodes as $node) {
            if (empty($node['ptmdTemplate'])) {
                continue;
            }

            if (isset($node['liveMediaType'])) {
                $this->getLogger()->log('livestreams can not be downloaded');
                continue;
            }

            $result = $this->getBestResultFromPtmd($this->getPtmdUrl($node['ptmdTemplate']), $apiToken);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array $node
     *
     * @return int
     */
    protected function isSignLanguage(array $node)
    {
        return ($node['vodMediaType'] ?? null) === self::VOD_MEDIA_TYPE_SIGN_LANGUAGE ? 1 : 0;
    }

    /**
     * @param string $ptmdTemplate
     *
     * @return string
     */
    protected function getPtmdUrl($ptmdTemplate)
    {
        $path = str_replace(self::PLACEHOLDER_PLAYER_ID, self::PLAYER_ID, $ptmdTemplate);

        return preg_match('#^https?://#i', $path) === 1 ? $path : self::API_BASE_URL . $path;
    }

    /**
     * @param string $ptmdUrl
     * @param string $apiToken
     *
     * @return Result|null
     */
    protected function getBestResultFromPtmd($ptmdUrl, $apiToken)
    {
        $this->getLogger()->log('requesting ' . $ptmdUrl);

        $response = $this->getTools()->curlRequest($ptmdUrl, [
            CURLOPT_HTTPHEADER => [
                self::API_AUTH_HEADER . ': ' . $apiToken,
            ],
        ]);
        if ($response === null) {
            return null;
        }

        $ptmd = json_decode($response, true);

        $best = null;
        foreach ($ptmd['priorityList'] ?? [] as $priorityListItem) {
            foreach ($priorityListItem['formitaeten'] ?? [] as $formitaet) {
                $restricted = $this->hasRestrictedFacet($formitaet['facets'] ?? []);

                foreach ($formitaet['qualities'] ?? [] as $quality) {
                    foreach ($quality['audio']['tracks'] ?? [] as $track) {
                        $candidate = $this->createCandidate($track, $quality, $restricted);
                        if ($candidate !== null && ($best === null || $candidate['score'] > $best['score'])) {
                            $best = $candidate;
                        }
                    }
                }
            }
        }

        if ($best === null) {
            $this->getLogger()->log('no progressive file found in ' . $ptmdUrl);

            return null;
        }

        $this->getLogger()->log(sprintf('Url "%s" won with a height of "%d"', $best['uri'], $best['height']));

        $result = new Result();
        $result->setUri($best['uri']);
        $result->setQualityRating($best['height']);

        return $result;
    }

    /**
     * @param array $track
     * @param array $quality
     * @param bool  $restricted
     *
     * @return array|null
     */
    protected function createCandidate(array $track, array $quality, $restricted)
    {
        $uri = $track['uri'] ?? null;
        if (!is_string($uri)) {
            return null;
        }

        $extension = strtolower(pathinfo((string)parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!isset(self::$EXTENSION_RATING[$extension])) {
            return null;
        }

        $height = (int)($quality['highestVerticalResolution'] ?? 0);
        if ($height === 0) {
            $height = self::$QUALITY_HEIGHT[$quality['quality'] ?? ''] ?? 0;
        }

        $isMainTrack = ($track['class'] ?? null) === self::$MAIN_TRACK_CLASS;
        $isSupportedLanguage = ($track['language'] ?? null) === self::$SUPPORTED_LANGUAGE;

        return [
            'uri' => $uri,
            'height' => $height,
            'score' => [
                ($isMainTrack ? 2 : 0) + ($isSupportedLanguage ? 1 : 0),
                $restricted ? 0 : 1,
                self::$EXTENSION_RATING[$extension],
                $height,
            ],
        ];
    }

    /**
     * @param array $facets
     *
     * @return bool
     */
    protected function hasRestrictedFacet(array $facets)
    {
        return count(array_intersect($facets, self::$RESTRICTED_FACETS)) > 0;
    }

    /**
     * @param array  $video
     * @param Result $result
     *
     * @return void
     */
    protected function addTitle(array $video, Result $result)
    {
        $title = trim($video['smartCollection']['title'] ?? '');
        $episodeTitle = trim($video['title'] ?? '');

        // single videos like movies are their own collection
        if ($title === $episodeTitle) {
            $title = '';
        }

        $result->setTitle($title);
        $result->setEpisodeTitle($episodeTitle);
    }
}
